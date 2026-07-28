<?php
/**
 * ชุดทดสอบ Lesson Duplicator
 *
 * ครอบคลุม TC-01, TC-02 และ TC-08 จากแผนพัฒนา
 *
 * @package TLCD\Tests
 */

use TLCD\Services\Lesson_Duplicator;
use TLCD\Services\Post_Meta_Copier;

/**
 * Class Test_Lesson_Duplicator
 */
class Test_Lesson_Duplicator extends TLCD_TestCase {

	/**
	 * โครงสร้างข้อมูลที่ seed ไว้
	 *
	 * @var array
	 */
	private $data;

	/**
	 * ตั้งค่าเริ่มต้น
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->data = TLCD_Seeder::full_course( array( 'author' => $this->admin_id() ) );
	}

	/**
	 * TC-01: ทำสำเนาบทเรียนปกติแล้วได้เนื้อหาครบ
	 *
	 * @return void
	 */
	public function test_duplicates_core_post_fields() {
		$source = get_post( $this->data['lesson_a'] );

		$new_id = ( new Lesson_Duplicator() )->duplicate( $source->ID, $this->data['topic_one'] );

		$this->assertNotWPError( $new_id );

		$copy = get_post( $new_id );

		$this->assertSame( $source->post_content, $copy->post_content, 'เนื้อหาต้องเหมือนต้นฉบับ' );
		$this->assertSame( $source->post_excerpt, $copy->post_excerpt, 'excerpt ต้องเหมือนต้นฉบับ' );
		$this->assertSame( $source->post_status, $copy->post_status, 'สถานะต้องเหมือนต้นฉบับ' );
		$this->assertSame( 'lesson', $copy->post_type );
		$this->assertSame( (int) $this->data['topic_one'], (int) $copy->post_parent );
		$this->assertNotSame( $source->ID, $copy->ID );
		$this->assertNotSame( $source->post_name, $copy->post_name, 'slug ต้องไม่ซ้ำกับต้นฉบับ' );
	}

	/**
	 * ถ้าคัดลอก meta ไม่ครบ ต้องลบโพสต์สำเนาและคืน error
	 *
	 * @return void
	 */
	public function test_rolls_back_when_meta_copy_fails() {
		$before   = $this->count_lessons();
		$callback = static function ( $check, $object_id, $meta_key ) {
			if ( '_video' === $meta_key ) {
				return false;
			}

			return $check;
		};

		add_filter( 'add_post_metadata', $callback, 10, 3 );
		$result = ( new Lesson_Duplicator() )->duplicate( $this->data['lesson_b'], $this->data['topic_one'] );
		remove_filter( 'add_post_metadata', $callback, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'tlcd_meta_copy_failed', $result->get_error_code() );
		$this->assertSame( $before, $this->count_lessons() );
	}

	/**
	 * ถ้าคัดลอก taxonomy ไม่สำเร็จ ต้องไม่ตอบว่าสำเร็จ
	 *
	 * @return void
	 */
	public function test_rolls_back_when_taxonomy_copy_fails() {
		$before     = $this->count_lessons();
		$duplicator = new class() extends Lesson_Duplicator {
			protected function copy_taxonomies( $source_id, $destination_id ) {
				unset( $source_id, $destination_id );

				return new WP_Error( 'simulated_taxonomy_failure', 'simulated' );
			}
		};

		$result = $duplicator->duplicate( $this->data['lesson_a'], $this->data['topic_one'] );

		$this->assertWPError( $result );
		$this->assertSame( 'simulated_taxonomy_failure', $result->get_error_code() );
		$this->assertSame( $before, $this->count_lessons() );
	}

	/**
	 * ถ้าเขียน menu_order ล้มเหลว ต้อง rollback สำเนา
	 *
	 * @return void
	 */
	public function test_rolls_back_when_reordering_fails() {
		global $wpdb;

		$before   = $this->count_lessons();
		$posts    = $wpdb->posts;
		$callback = static function ( $query ) use ( $posts ) {
			if ( false !== strpos( $query, "UPDATE `{$posts}` SET `menu_order`" ) ) {
				return 'UPDATE broken menu order';
			}

			return $query;
		};

		add_filter( 'query', $callback );
		$wpdb->suppress_errors( true );
		$result = ( new Lesson_Duplicator() )->duplicate( $this->data['lesson_a'], $this->data['topic_one'] );
		$wpdb->suppress_errors( false );
		remove_filter( 'query', $callback );

		$this->assertWPError( $result );
		$this->assertSame( 'tlcd_order_update_failed', $result->get_error_code() );
		$this->assertSame( $before, $this->count_lessons() );
	}

	/**
	 * Exception จาก extension ก่อน insert ต้องกลายเป็น error ที่ปลอดภัย.
	 *
	 * @return void
	 */
	public function test_handles_extension_throw_before_insert() {
		$before         = $this->count_lessons();
		$logged_context = '';
		$thrower        = static function () {
			throw new RuntimeException( 'Sensitive pre-insert failure' );
		};
		$logger         = static function ( $context ) use ( &$logged_context ) {
			$logged_context = $context;
		};

		add_filter( 'tlcd_content_postarr', $thrower );
		add_action( 'tlcd/log/error', $logger, 10, 1 );

		$result = ( new Lesson_Duplicator() )->duplicate( $this->data['lesson_a'], $this->data['topic_one'] );

		remove_filter( 'tlcd_content_postarr', $thrower );
		remove_action( 'tlcd/log/error', $logger, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'tlcd_unexpected_duplication_error', $result->get_error_code() );
		$this->assertSame( 'content_duplicate', $logged_context );
		$this->assertSame( $before, $this->count_lessons() );
		$this->assertStringNotContainsString( 'Sensitive pre-insert failure', $result->get_error_message() );
	}

	/**
	 * ชื่อสำเนาต้องมีคำต่อท้าย
	 *
	 * @return void
	 */
	public function test_appends_copy_suffix_to_title() {
		$new_id = ( new Lesson_Duplicator() )->duplicate( $this->data['lesson_a'], $this->data['topic_one'] );

		$this->assertSame( 'บทเรียน A – Copy', get_the_title( $new_id ) );
	}

	/**
	 * ทำสำเนาซ้ำต้องได้เลขลำดับ ไม่ใช่ "สำเนา – สำเนา"
	 *
	 * @return void
	 */
	public function test_repeated_duplication_increments_index() {
		$duplicator = new Lesson_Duplicator();

		$first  = $duplicator->duplicate( $this->data['lesson_a'], $this->data['topic_one'] );
		$second = $duplicator->duplicate( $this->data['lesson_a'], $this->data['topic_one'] );
		$third  = $duplicator->duplicate( $first, $this->data['topic_one'] );

		$this->assertSame( 'บทเรียน A – Copy', get_the_title( $first ) );
		$this->assertSame( 'บทเรียน A – Copy 2', get_the_title( $second ) );
		$this->assertSame( 'บทเรียน A – Copy 3', get_the_title( $third ) );
		$this->assertStringNotContainsString( 'Copy – Copy', get_the_title( $third ) );
	}

	/**
	 * TC-01: สำเนาต้องอยู่ต่อจากต้นฉบับทันที
	 *
	 * @return void
	 */
	public function test_places_copy_directly_after_source() {
		$new_id = ( new Lesson_Duplicator() )->duplicate( $this->data['lesson_b'], $this->data['topic_one'] );

		$this->assertCurriculumOrder(
			array(
				$this->data['lesson_a'],
				$this->data['lesson_b'],
				$new_id,
				$this->data['lesson_c'],
			),
			$this->data['topic_one'],
			'สำเนาต้องแทรกต่อจากบทเรียน B ไม่ใช่ต่อท้ายสุด'
		);
	}

	/**
	 * TC-01/TC-02: วิดีโอ ไฟล์แนบ รูปหน้าปก และ preview ต้องถูกคัดลอก
	 *
	 * @return void
	 */
	public function test_copies_allowlisted_meta() {
		$thumbnail_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);

		update_post_meta( $this->data['lesson_b'], '_thumbnail_id', $thumbnail_id );

		$new_id = ( new Lesson_Duplicator() )->duplicate( $this->data['lesson_b'], $this->data['topic_one'] );

		$video = get_post_meta( $new_id, '_video', true );

		$this->assertSame( 'youtube', $video['source'] );
		$this->assertSame( '12', $video['runtime']['minutes'], 'runtime ต้องคัดลอกมาครบ' );
		$this->assertSame( array( 101, 102 ), get_post_meta( $new_id, '_tutor_attachments', true ) );
		$this->assertSame( '1', get_post_meta( $new_id, '_is_preview', true ) );
		$this->assertSame( (int) $thumbnail_id, (int) get_post_meta( $new_id, '_thumbnail_id', true ) );
	}

	/**
	 * TC-02: ต้องใช้ attachment ID เดิม ไม่สร้างไฟล์ซ้ำใน Media Library
	 *
	 * @return void
	 */
	public function test_does_not_duplicate_media_library_files() {
		$thumbnail_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);

		update_post_meta( $this->data['lesson_b'], '_thumbnail_id', $thumbnail_id );

		$before = wp_count_posts( 'attachment' );

		( new Lesson_Duplicator() )->duplicate( $this->data['lesson_b'], $this->data['topic_one'] );

		$after = wp_count_posts( 'attachment' );

		$this->assertEquals(
			$before->inherit,
			$after->inherit,
			'การทำสำเนาไม่ควรเพิ่มไฟล์ใน Media Library'
		);
	}

	/**
	 * TC-08: ความก้าวหน้าของผู้เรียนต้องไม่ติดมากับสำเนา
	 *
	 * @return void
	 */
	public function test_does_not_copy_student_progress() {
		$student_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		TLCD_Seeder::complete_lesson( $student_id, $this->data['lesson_a'] );

		$new_id = ( new Lesson_Duplicator() )->duplicate( $this->data['lesson_a'], $this->data['topic_one'] );

		$this->assertSame( '', get_post_meta( $new_id, '_tutor_completed_' . $student_id, true ) );
		$this->assertSame( '', get_post_meta( $new_id, '_lesson_reading_info', true ) );
		$this->assertSame(
			'',
			get_user_meta( $student_id, '_tutor_completed_lesson_id_' . $new_id, true ),
			'บทเรียนสำเนาต้องเริ่มต้นเป็นยังไม่เรียนสำหรับผู้เรียนทุกคน'
		);
	}

	/**
	 * meta ที่ห้ามคัดลอกต้องไม่ติดมา
	 *
	 * @return void
	 */
	public function test_does_not_copy_blocked_meta() {
		update_post_meta( $this->data['lesson_a'], '_edit_lock', '1700000000:1' );
		update_post_meta( $this->data['lesson_a'], '_edit_last', '1' );

		$new_id = ( new Lesson_Duplicator() )->duplicate( $this->data['lesson_a'], $this->data['topic_one'] );

		$this->assertSame( '', get_post_meta( $new_id, '_edit_lock', true ) );
		$this->assertSame( '', get_post_meta( $new_id, '_edit_last', true ) );
	}

	/**
	 * meta ที่ไม่อยู่ใน allowlist ต้องไม่ถูกคัดลอก (โหมดเริ่มต้น)
	 *
	 * @return void
	 */
	public function test_allowlist_mode_skips_unknown_meta() {
		update_post_meta( $this->data['lesson_a'], '_some_random_addon_meta', 'ค่าเดิม' );

		$new_id = ( new Lesson_Duplicator() )->duplicate( $this->data['lesson_a'], $this->data['topic_one'] );

		$this->assertSame( '', get_post_meta( $new_id, '_some_random_addon_meta', true ) );
	}

	/**
	 * filter ต้องเพิ่ม meta key เข้า allowlist ได้
	 *
	 * @return void
	 */
	public function test_allowlist_is_filterable() {
		update_post_meta( $this->data['lesson_a'], '_addon_meta', 'ค่าที่ต้องการ' );

		$callback = static function ( $keys ) {
			$keys[] = '_addon_meta';

			return $keys;
		};

		add_filter( 'tlcd_lesson_meta_allowlist', $callback );

		$new_id = ( new Lesson_Duplicator() )->duplicate( $this->data['lesson_a'], $this->data['topic_one'] );

		remove_filter( 'tlcd_lesson_meta_allowlist', $callback );

		$this->assertSame( 'ค่าที่ต้องการ', get_post_meta( $new_id, '_addon_meta', true ) );
	}

	/**
	 * meta ที่เป็น array ต้องคัดลอกได้โดยไม่เพี้ยน
	 *
	 * @return void
	 */
	public function test_serialized_meta_survives_copy() {
		$value = array(
			'nested' => array(
				'quote'  => 'ข้อความมี "อัญประกาศ" และ \\backslash',
				'thai'   => 'ทดสอบภาษาไทย',
				'number' => 42,
			),
		);

		// WordPress meta APIs expect slashed input.
		update_post_meta( $this->data['lesson_a'], '_video', wp_slash( $value ) );

		$new_id = ( new Lesson_Duplicator() )->duplicate( $this->data['lesson_a'], $this->data['topic_one'] );

		$this->assertSame( $value, get_post_meta( $new_id, '_video', true ) );
	}

	/**
	 * `_tutor_course_id_for_lesson` ต้องชี้ไปคอร์สปลายทางเสมอ
	 *
	 * @return void
	 */
	public function test_rewrites_course_reference_meta() {
		delete_post_meta( $this->data['lesson_a'], '_tutor_course_id_for_lesson' );

		$new_id = ( new Lesson_Duplicator() )->duplicate( $this->data['lesson_a'], $this->data['topic_one'] );

		$this->assertSame(
			(int) $this->data['course_id'],
			(int) get_post_meta( $new_id, '_tutor_course_id_for_lesson', true ),
			'ต้องเขียน course id ปลายทางแม้ต้นฉบับจะไม่มี meta นี้'
		);
	}

	/**
	 * ผู้เขียนของสำเนาต้องเป็นผู้ที่กด Duplicate
	 *
	 * @return void
	 */
	public function test_author_defaults_to_current_user() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$new_id = ( new Lesson_Duplicator() )->duplicate( $this->data['lesson_a'], $this->data['topic_one'] );

		$this->assertSame( $editor_id, (int) get_post_field( 'post_author', $new_id ) );
	}

	/**
	 * filter ต้องเปลี่ยนผู้เขียนได้
	 *
	 * @return void
	 */
	public function test_author_is_filterable() {
		$source_author = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_update_post(
			array(
				'ID'          => $this->data['lesson_a'],
				'post_author' => $source_author,
			)
		);

		$callback = static function ( $author, $source ) {
			return (int) $source->post_author;
		};

		add_filter( 'tlcd_duplicate_author', $callback, 10, 2 );

		$new_id = ( new Lesson_Duplicator() )->duplicate( $this->data['lesson_a'], $this->data['topic_one'] );

		remove_filter( 'tlcd_duplicate_author', $callback );

		$this->assertSame( $source_author, (int) get_post_field( 'post_author', $new_id ) );
	}

	/**
	 * บทเรียนที่ไม่มี meta เลยต้องทำสำเนาได้โดยไม่ error
	 *
	 * @return void
	 */
	public function test_lesson_without_meta_duplicates_cleanly() {
		$new_id = ( new Lesson_Duplicator() )->duplicate( $this->data['lesson_c'], $this->data['topic_one'] );

		$this->assertNotWPError( $new_id );
		$this->assertSame( 'lesson', get_post_type( $new_id ) );
	}

	/**
	 * ID ที่ไม่มีอยู่จริงต้องคืน WP_Error ไม่ใช่สร้างโพสต์เปล่า
	 *
	 * @return void
	 */
	public function test_rejects_missing_source() {
		$result = ( new Lesson_Duplicator() )->duplicate( 999999, $this->data['topic_one'] );

		$this->assertWPError( $result );
		$this->assertSame( 'tlcd_source_not_found', $result->get_error_code() );
	}

	/**
	 * ส่ง post type ผิดชนิดต้องถูกปฏิเสธ
	 *
	 * @return void
	 */
	public function test_rejects_wrong_post_type() {
		$result = ( new Lesson_Duplicator() )->duplicate( $this->data['quiz_id'], $this->data['topic_one'] );

		$this->assertWPError( $result );
		$this->assertSame( 'tlcd_invalid_source_type', $result->get_error_code() );
	}

	/**
	 * บทเรียนในถังขยะต้องทำสำเนาไม่ได้
	 *
	 * @return void
	 */
	public function test_rejects_trashed_source() {
		wp_trash_post( $this->data['lesson_a'] );

		$result = ( new Lesson_Duplicator() )->duplicate( $this->data['lesson_a'], $this->data['topic_one'] );

		$this->assertWPError( $result );
		$this->assertSame( 'tlcd_source_trashed', $result->get_error_code() );
	}

	/**
	 * หัวข้อปลายทางที่ไม่มีอยู่จริงต้องถูกปฏิเสธ
	 *
	 * @return void
	 */
	public function test_rejects_missing_destination_topic() {
		$result = ( new Lesson_Duplicator() )->duplicate( $this->data['lesson_a'], 999999 );

		$this->assertWPError( $result );
		$this->assertSame( 'tlcd_topic_not_found', $result->get_error_code() );
	}

	/**
	 * หัวข้อที่ไม่ได้อยู่ในคอร์สใดต้องถูกปฏิเสธ
	 *
	 * @return void
	 */
	public function test_rejects_orphan_topic() {
		$orphan_topic = TLCD_Seeder::topic( 0, array( 'title' => 'หัวข้อกำพร้า' ) );

		$result = ( new Lesson_Duplicator() )->duplicate( $this->data['lesson_a'], $orphan_topic );

		$this->assertWPError( $result );
		$this->assertSame( 'tlcd_course_not_found', $result->get_error_code() );
	}

	/**
	 * allowlist ของบทเรียนต้องไม่มี key ที่อยู่ใน blocklist
	 *
	 * @return void
	 */
	public function test_allowlist_and_blocklist_do_not_overlap() {
		$overlap = array_intersect(
			Post_Meta_Copier::lesson_allowlist(),
			Post_Meta_Copier::blocklist()
		);

		$this->assertSame( array(), $overlap, 'allowlist กับ blocklist ต้องไม่ทับกัน' );
	}

	/**
	 * นับจำนวนบทเรียนที่ยังไม่อยู่ในถังขยะ
	 *
	 * @return int
	 */
	private function count_lessons() {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'trash'",
				'lesson'
			)
		);
	}
}
