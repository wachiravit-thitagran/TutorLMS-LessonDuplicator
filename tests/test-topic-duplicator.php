<?php
/**
 * ชุดทดสอบ Topic Duplicator
 *
 * ครอบคลุม TC-03, TC-04 และ TC-07 จากแผนพัฒนา
 *
 * @package TLCD\Tests
 */

use TLCD\Services\Topic_Duplicator;

/**
 * Class Test_Topic_Duplicator
 */
class Test_Topic_Duplicator extends TLCD_TestCase {

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
	 * TC-03: ทำสำเนาหัวข้อพร้อมบทเรียนครบทุกรายการ
	 *
	 * @return void
	 */
	public function test_duplicates_topic_with_all_lessons() {
		$result = ( new Topic_Duplicator() )->duplicate( $this->data['topic_one'] );

		$this->assertNotWPError( $result );
		$this->assertCount( 3, $result['lessons'], 'ต้องคัดลอกบทเรียนครบ 3 รายการ' );

		$titles = array_map(
			static function ( $lesson ) {
				return get_the_title( $lesson['duplicate_id'] );
			},
			$result['lessons']
		);

		$this->assertSame(
			array( 'บทเรียน A – Copy', 'บทเรียน B – Copy', 'บทเรียน C – Copy' ),
			$titles,
			'ลำดับบทเรียนในสำเนาต้องเหมือนต้นฉบับ'
		);
	}

	/**
	 * TC-03: ลำดับบทเรียนในหัวข้อสำเนาต้องเริ่มจาก 1 และเรียงตามต้นฉบับ
	 *
	 * @return void
	 */
	public function test_preserves_lesson_order_in_new_topic() {
		$result = ( new Topic_Duplicator() )->duplicate( $this->data['topic_one'] );

		$expected = wp_list_pluck( $result['lessons'], 'duplicate_id' );

		$this->assertCurriculumOrder( $expected, $result['topic_id'] );

		$orders = array_map(
			static function ( $id ) {
				return (int) get_post_field( 'menu_order', $id );
			},
			$expected
		);

		$this->assertSame( array( 1, 2, 3 ), $orders, 'ลำดับต้องเริ่มจาก 1 ต่อเนื่องกัน' );
	}

	/**
	 * หัวข้อสำเนาต้องอยู่ต่อจากหัวข้อต้นฉบับ
	 *
	 * @return void
	 */
	public function test_places_topic_copy_after_source() {
		$result = ( new Topic_Duplicator() )->duplicate( $this->data['topic_one'] );

		$this->assertCurriculumOrder(
			array(
				$this->data['topic_one'],
				$result['topic_id'],
				$this->data['topic_empty'],
				$this->data['topic_mixed'],
			),
			$this->data['course_id']
		);
	}

	/**
	 * คำอธิบายหัวข้อต้องถูกคัดลอก
	 *
	 * @return void
	 */
	public function test_copies_topic_summary() {
		$result = ( new Topic_Duplicator() )->duplicate( $this->data['topic_one'] );

		$this->assertSame(
			'สรุปหัวข้อที่ 1',
			get_post_field( 'post_content', $result['topic_id'] )
		);
		$this->assertSame( 'หัวข้อที่ 1 – Copy', get_the_title( $result['topic_id'] ) );
	}

	/**
	 * TC-04: หัวข้อว่างต้องทำสำเนาได้โดยไม่ error
	 *
	 * @return void
	 */
	public function test_duplicates_empty_topic() {
		$result = ( new Topic_Duplicator() )->duplicate( $this->data['topic_empty'] );

		$this->assertNotWPError( $result );
		$this->assertSame( array(), $result['contents'] );
		$this->assertSame( array(), $result['skipped'] );
		$this->assertSame( 'topics', get_post_type( $result['topic_id'] ) );
	}

	/**
	 * เนื้อหาผสม (บทเรียน + แบบทดสอบ + งานที่มอบหมาย) ต้องคัดลอกครบ
	 *
	 * @return void
	 */
	public function test_duplicates_mixed_content_types() {
		$result = ( new Topic_Duplicator() )->duplicate( $this->data['topic_mixed'] );

		$this->assertNotWPError( $result );
		$this->assertCount( 3, $result['contents'], 'ต้องคัดลอกทั้ง 3 ชนิด' );
		$this->assertSame( array(), $result['skipped'], 'ไม่ควรมีรายการถูกข้าม' );

		$types = wp_list_pluck( $result['contents'], 'post_type' );

		$this->assertSame( array( 'lesson', 'tutor_quiz', 'tutor_assignments' ), $types );
	}

	/**
	 * ชนิดที่ยังไม่รองรับต้องถูกข้ามและรายงานกลับ ไม่ใช่ทำให้ทั้ง operation ล้มเหลว
	 *
	 * @return void
	 */
	public function test_reports_unsupported_content_as_skipped() {
		register_post_type( 'tutor_zoom_meeting', array( 'public' => false ) );

		wp_insert_post(
			array(
				'post_type'   => 'tutor_zoom_meeting',
				'post_title'  => 'ประชุม Zoom',
				'post_status' => 'publish',
				'post_parent' => $this->data['topic_mixed'],
				'menu_order'  => 4,
			)
		);

		$result = ( new Topic_Duplicator() )->duplicate( $this->data['topic_mixed'] );

		$this->assertNotWPError( $result );
		$this->assertCount( 1, $result['skipped'] );
		$this->assertSame( 'tutor_zoom_meeting', $result['skipped'][0]['post_type'] );
		$this->assertCount( 3, $result['contents'], 'รายการที่รองรับต้องยังถูกคัดลอกครบ' );
	}

	/**
	 * TC-07: ถ้าล้มเหลวกลางทางต้อง rollback ให้หมด
	 *
	 * @return void
	 */
	public function test_rolls_back_when_a_child_fails() {
		$topics_before  = $this->count_posts_of_type( 'topics' );
		$lessons_before = $this->count_posts_of_type( 'lesson' );

		// บังคับให้บทเรียนใบที่สองสร้างไม่สำเร็จ.
		$this->fail_insert_for( 'บทเรียน B' );

		$result = ( new Topic_Duplicator() )->duplicate( $this->data['topic_one'] );

		$this->stop_failing_insert();

		$this->assertWPError( $result );
		$this->assertSame(
			$topics_before,
			$this->count_posts_of_type( 'topics' ),
			'ต้องไม่เหลือหัวข้อที่สร้างค้างไว้'
		);
		$this->assertSame(
			$lessons_before,
			$this->count_posts_of_type( 'lesson' ),
			'ต้องไม่เหลือบทเรียนที่สร้างค้างไว้'
		);
	}

	/**
	 * Exception จาก extension hook ต้องถูกแปลงเป็น error และ rollback ทั้ง operation
	 *
	 * @return void
	 */
	public function test_rolls_back_when_extension_throws() {
		$topics_before  = $this->count_posts_of_type( 'topics' );
		$lessons_before = $this->count_posts_of_type( 'lesson' );
		$logged_context = '';
		$thrower        = static function ( $postarr, $source ) {
			if ( 'บทเรียน B' === $source->post_title ) {
				throw new RuntimeException( 'Sensitive extension failure' );
			}

			return $postarr;
		};
		$logger         = static function ( $context ) use ( &$logged_context ) {
			$logged_context = $context;
		};

		add_filter( 'tlcd_content_postarr', $thrower, 10, 2 );
		add_action( 'tlcd/log/error', $logger, 10, 1 );

		try {
			$result = ( new Topic_Duplicator() )->duplicate( $this->data['topic_one'] );
		} catch ( Throwable $error ) {
			$result = $error;
		}

		remove_filter( 'tlcd_content_postarr', $thrower, 10 );
		remove_action( 'tlcd/log/error', $logger, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'tlcd_unexpected_duplication_error', $result->get_error_code() );
		$this->assertSame( 'topic_duplicate', $logged_context );
		$this->assertSame( $topics_before, $this->count_posts_of_type( 'topics' ) );
		$this->assertSame( $lessons_before, $this->count_posts_of_type( 'lesson' ) );
		$this->assertStringNotContainsString( 'Sensitive extension failure', $result->get_error_message() );
	}

	/**
	 * TC-07: rollback ต้องไม่กระทบข้อมูลเดิม
	 *
	 * @return void
	 */
	public function test_rollback_leaves_source_untouched() {
		$this->fail_insert_for( 'บทเรียน A' );

		( new Topic_Duplicator() )->duplicate( $this->data['topic_one'] );

		$this->stop_failing_insert();

		$this->assertCurriculumOrder(
			array( $this->data['lesson_a'], $this->data['lesson_b'], $this->data['lesson_c'] ),
			$this->data['topic_one'],
			'Curriculum เดิมต้องไม่เสียหาย'
		);
		$this->assertSame( 'publish', get_post_status( $this->data['lesson_a'] ) );
	}

	/**
	 * rollback ต้องลบคำถามของแบบทดสอบที่เพิ่งสร้างด้วย
	 *
	 * @return void
	 */
	public function test_rollback_removes_copied_quiz_questions() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tutor_quiz_questions" );
		// phpcs:enable

		// ให้บทเรียนกับแบบทดสอบผ่าน แล้วให้งานที่มอบหมายล้มเหลว.
		$this->fail_insert_for( 'งานส่งท้ายบท' );

		$result = ( new Topic_Duplicator() )->duplicate( $this->data['topic_mixed'] );

		$this->stop_failing_insert();

		$this->assertWPError( $result );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tutor_quiz_questions" );
		// phpcs:enable

		$this->assertSame( $before, $after, 'คำถามที่คัดลอกมาต้องถูกลบทิ้งพร้อม rollback' );
	}

	/**
	 * หัวข้อที่มีเนื้อหาเกินขีดจำกัดต้องถูกปฏิเสธก่อนเริ่มสร้าง
	 *
	 * @return void
	 */
	public function test_rejects_topic_over_size_limit() {
		$callback = static function () {
			return 2;
		};

		add_filter( 'tlcd_max_topic_children', $callback );

		$result = ( new Topic_Duplicator() )->duplicate( $this->data['topic_one'] );

		remove_filter( 'tlcd_max_topic_children', $callback );

		$this->assertWPError( $result );
		$this->assertSame( 'tlcd_topic_too_large', $result->get_error_code() );
		$this->assertSame( 413, $result->get_error_data()['status'] );
	}

	/**
	 * หัวข้อที่ไม่มีอยู่จริงต้องถูกปฏิเสธ
	 *
	 * @return void
	 */
	public function test_rejects_missing_topic() {
		$result = ( new Topic_Duplicator() )->duplicate( 999999 );

		$this->assertWPError( $result );
		$this->assertSame( 'tlcd_topic_not_found', $result->get_error_code() );
	}

	/**
	 * หัวข้อในถังขยะต้องถูกปฏิเสธ
	 *
	 * @return void
	 */
	public function test_rejects_trashed_topic() {
		wp_trash_post( $this->data['topic_one'] );

		$result = ( new Topic_Duplicator() )->duplicate( $this->data['topic_one'] );

		$this->assertWPError( $result );
		$this->assertSame( 'tlcd_topic_trashed', $result->get_error_code() );
	}

	/**
	 * TC-08: ความก้าวหน้าของผู้เรียนต้องไม่ติดไปกับหัวข้อสำเนา
	 *
	 * @return void
	 */
	public function test_does_not_carry_student_progress() {
		$student_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		TLCD_Seeder::complete_lesson( $student_id, $this->data['lesson_a'] );

		$result = ( new Topic_Duplicator() )->duplicate( $this->data['topic_one'] );

		foreach ( $result['lessons'] as $lesson ) {
			$this->assertSame(
				'',
				get_post_meta( $lesson['duplicate_id'], '_tutor_completed_' . $student_id, true )
			);
		}
	}

	/**
	 * callback ที่ใช้บังคับให้ wp_insert_post ล้มเหลว
	 *
	 * @var callable|null
	 */
	private $insert_failure_callback = null;

	/**
	 * บังคับให้ wp_insert_post คืน WP_Error สำหรับโพสต์ที่ชื่อมีข้อความที่ระบุ
	 *
	 * ใช้ `wp_insert_post_empty_content` เพราะเป็นจุดเดียวใน wp_insert_post() ที่
	 * คืน WP_Error ได้โดยไม่ต้องทำให้ฐานข้อมูลพัง (การใส่ post_type ที่ไม่มีอยู่จริง
	 * ไม่ทำให้ล้มเหลว — WordPress ยอมบันทึกให้)
	 *
	 * @param string $needle ข้อความในชื่อโพสต์.
	 *
	 * @return void
	 */
	private function fail_insert_for( $needle ) {
		$this->insert_failure_callback = static function ( $maybe_empty, $postarr ) use ( $needle ) {
			if ( isset( $postarr['post_title'] ) && false !== strpos( $postarr['post_title'], $needle ) ) {
				return true;
			}

			return $maybe_empty;
		};

		add_filter( 'wp_insert_post_empty_content', $this->insert_failure_callback, 10, 2 );
	}

	/**
	 * ยกเลิกการบังคับให้ล้มเหลว
	 *
	 * @return void
	 */
	private function stop_failing_insert() {
		if ( $this->insert_failure_callback ) {
			remove_filter( 'wp_insert_post_empty_content', $this->insert_failure_callback, 10 );
			$this->insert_failure_callback = null;
		}
	}

	/**
	 * นับจำนวนโพสต์ตามชนิด (ไม่รวมถังขยะ)
	 *
	 * @param string $post_type Post type.
	 *
	 * @return int
	 */
	private function count_posts_of_type( $post_type ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'trash'",
				$post_type
			)
		);
		// phpcs:enable
	}
}
