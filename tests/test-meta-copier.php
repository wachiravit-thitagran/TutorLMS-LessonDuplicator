<?php
/**
 * ชุดทดสอบ Post_Meta_Copier
 *
 * คลาสนี้คือด่านที่กันข้อมูลผู้เรียนไม่ให้ติดไปกับสำเนา จึงต้องทดสอบทั้งสองโหมด
 * (allowlist และ blocklist) และทดสอบ pattern ทุกกลุ่มที่ตั้งใจบล็อกไว้
 *
 * @package TLCD\Tests
 */

use TLCD\Services\Post_Meta_Copier;

/**
 * Class Test_Meta_Copier
 */
class Test_Meta_Copier extends TLCD_TestCase {

	/**
	 * โพสต์ต้นทาง
	 *
	 * @var int
	 */
	private $source;

	/**
	 * โพสต์ปลายทาง
	 *
	 * @var int
	 */
	private $destination;

	/**
	 * ตั้งค่าเริ่มต้น
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$course_id = TLCD_Seeder::course();
		$topic_id  = TLCD_Seeder::topic( $course_id, array( 'order' => 1 ) );

		$this->source      = TLCD_Seeder::lesson( $topic_id, array( 'title' => 'ต้นทาง' ) );
		$this->destination = TLCD_Seeder::lesson( $topic_id, array( 'title' => 'ปลายทาง' ) );
	}

	/**
	 * โหมดเริ่มต้นต้องเป็น allowlist
	 *
	 * @return void
	 */
	public function test_default_mode_is_allowlist() {
		$this->assertSame( Post_Meta_Copier::MODE_ALLOWLIST, Post_Meta_Copier::mode() );
	}

	/**
	 * ต้องสลับไปโหมด blocklist ได้ผ่าน filter
	 *
	 * @return void
	 */
	public function test_mode_is_filterable() {
		$callback = static function () {
			return Post_Meta_Copier::MODE_BLOCKLIST;
		};

		add_filter( 'tlcd_meta_copy_mode', $callback );
		$mode = Post_Meta_Copier::mode();
		remove_filter( 'tlcd_meta_copy_mode', $callback );

		$this->assertSame( Post_Meta_Copier::MODE_BLOCKLIST, $mode );
	}

	/**
	 * ค่าที่ไม่รู้จักต้องตกกลับเป็น allowlist ไม่ใช่เปิดกว้าง
	 *
	 * @return void
	 */
	public function test_unknown_mode_falls_back_to_allowlist() {
		$callback = static function () {
			return 'anything-else';
		};

		add_filter( 'tlcd_meta_copy_mode', $callback );
		$mode = Post_Meta_Copier::mode();
		remove_filter( 'tlcd_meta_copy_mode', $callback );

		$this->assertSame( Post_Meta_Copier::MODE_ALLOWLIST, $mode );
	}

	/**
	 * โหมด blocklist ต้องคัดลอกทุกคีย์ยกเว้นที่บล็อกไว้
	 *
	 * @return void
	 */
	public function test_blocklist_mode_copies_everything_not_blocked() {
		update_post_meta( $this->source, '_addon_meta', 'ค่า addon' );
		update_post_meta( $this->source, '_edit_lock', '123:1' );
		update_post_meta( $this->source, '_tutor_completed_5', time() );

		$callback = static function () {
			return Post_Meta_Copier::MODE_BLOCKLIST;
		};

		add_filter( 'tlcd_meta_copy_mode', $callback );
		Post_Meta_Copier::copy( $this->source, $this->destination, array() );
		remove_filter( 'tlcd_meta_copy_mode', $callback );

		$this->assertSame( 'ค่า addon', get_post_meta( $this->destination, '_addon_meta', true ) );
		$this->assertSame( '', get_post_meta( $this->destination, '_edit_lock', true ) );
		$this->assertSame( '', get_post_meta( $this->destination, '_tutor_completed_5', true ) );
	}

	/**
	 * pattern ที่ตั้งใจบล็อกต้องทำงานจริงทุกกลุ่ม
	 *
	 * @dataProvider blocked_key_provider
	 *
	 * @param string $key meta key ที่ต้องถูกบล็อก.
	 *
	 * @return void
	 */
	public function test_blocked_patterns( $key ) {
		$this->assertTrue(
			Post_Meta_Copier::is_blocked( $key ),
			$key . ' ต้องถูกบล็อก'
		);
	}

	/**
	 * meta key ที่ต้องไม่ถูกคัดลอกเด็ดขาด
	 *
	 * @return array[]
	 */
	public function blocked_key_provider() {
		return array(
			'ล็อกการแก้ไข'          => array( '_edit_lock' ),
			'ผู้แก้ไขล่าสุด'        => array( '_edit_last' ),
			'transient'             => array( '_transient_something' ),
			'site transient'        => array( '_site_transient_something' ),
			'บทเรียนที่เรียนจบแล้ว' => array( '_tutor_completed_lesson_id_12' ),
			'ข้อมูลการอ่าน'         => array( '_lesson_reading_info' ),
			'ผลการทำแบบทดสอบ'       => array( '_tutor_quiz_attempt_1' ),
			'ความพยายามทำข้อสอบ'    => array( '_tutor_attempt_summary' ),
			'การลงทะเบียนเรียน'     => array( '_tutor_enrolled_5' ),
			'ความก้าวหน้าในคอร์ส'   => array( '_tutor_course_progress' ),
			'งานที่ผู้เรียนส่ง'     => array( '_tutor_assignment_submission_9' ),
			'การให้คะแนนงาน'        => array( '_tutor_assignment_evaluate_9' ),
			'จำนวนการเข้าชม'        => array( 'post_view_count' ),
			'oEmbed cache'          => array( '_oembed_abc123' ),
			'Elementor CSS cache'   => array( '_elementor_css' ),
			'Elementor asset cache' => array( '_elementor_page_assets' ),
			'Divi cache'            => array( '_et_dynamic_cached_shortcodes' ),
		);
	}

	/**
	 * meta ที่ต้องคัดลอกได้ต้องไม่ติด blocklist
	 *
	 * @dataProvider allowed_key_provider
	 *
	 * @param string $key meta key ที่ต้องคัดลอกได้.
	 *
	 * @return void
	 */
	public function test_allowed_keys_are_not_blocked( $key ) {
		$this->assertFalse(
			Post_Meta_Copier::is_blocked( $key ),
			$key . ' ต้องไม่ถูกบล็อก'
		);
	}

	/**
	 * meta key ที่ต้องคัดลอกได้
	 *
	 * @return array[]
	 */
	public function allowed_key_provider() {
		return array(
			'รูปหน้าปก'       => array( '_thumbnail_id' ),
			'วิดีโอ'          => array( '_video' ),
			'ไฟล์แนบ'         => array( '_tutor_attachments' ),
			'พรีวิว'          => array( '_is_preview' ),
			'ตั้งค่าแบบทดสอบ' => array( 'tutor_quiz_option' ),
			'ตั้งค่างาน'      => array( 'assignment_option' ),
			'Elementor data'  => array( '_elementor_data' ),
		);
	}

	/**
	 * allowlist ของทุกชนิดต้องไม่ทับกับ blocklist
	 *
	 * @return void
	 */
	public function test_no_allowlist_overlaps_blocklist() {
		$allowlists = array(
			'lesson'     => Post_Meta_Copier::lesson_allowlist(),
			'quiz'       => Post_Meta_Copier::quiz_allowlist(),
			'assignment' => Post_Meta_Copier::assignment_allowlist(),
			'topic'      => Post_Meta_Copier::topic_allowlist(),
		);

		foreach ( $allowlists as $name => $keys ) {
			foreach ( $keys as $key ) {
				$this->assertFalse(
					Post_Meta_Copier::is_blocked( $key ),
					sprintf( 'allowlist ของ %s มี %s ที่ถูก blocklist บล็อกไว้', $name, $key )
				);
			}
		}
	}

	/**
	 * meta ที่มีหลายค่า (multi-value) ต้องคัดลอกครบทุกค่า
	 *
	 * @return void
	 */
	public function test_copies_multi_value_meta() {
		add_post_meta( $this->source, '_tutor_attachments', 11 );
		add_post_meta( $this->source, '_tutor_attachments', 22 );
		add_post_meta( $this->source, '_tutor_attachments', 33 );

		Post_Meta_Copier::copy( $this->source, $this->destination, array( '_tutor_attachments' ) );

		$values = get_post_meta( $this->destination, '_tutor_attachments', false );

		$this->assertCount( 3, $values );
		$this->assertSame( array( '11', '22', '33' ), $values );
	}

	/**
	 * การคัดลอกซ้ำต้องไม่ทำให้ค่าซ้อนกัน
	 *
	 * @return void
	 */
	public function test_copying_twice_does_not_duplicate_values() {
		update_post_meta( $this->source, '_video', array( 'source' => 'youtube' ) );

		Post_Meta_Copier::copy( $this->source, $this->destination, array( '_video' ) );
		Post_Meta_Copier::copy( $this->source, $this->destination, array( '_video' ) );

		$this->assertCount( 1, get_post_meta( $this->destination, '_video', false ) );
	}

	/**
	 * อักขระพิเศษต้องรอดจากการคัดลอกโดยไม่เพี้ยน
	 *
	 * @dataProvider tricky_value_provider
	 *
	 * @param mixed $value ค่าที่ทดสอบ.
	 *
	 * @return void
	 */
	public function test_special_characters_survive( $value ) {
		// WordPress meta APIs expect slashed input.
		update_post_meta( $this->source, '_video', wp_slash( $value ) );

		Post_Meta_Copier::copy( $this->source, $this->destination, array( '_video' ) );

		$this->assertSame( $value, get_post_meta( $this->destination, '_video', true ) );
	}

	/**
	 * ค่าที่มักทำให้ระบบ escape พัง
	 *
	 * @return array[]
	 */
	public function tricky_value_provider() {
		return array(
			'อัญประกาศคู่'    => array( 'เขาบอกว่า "สวัสดี"' ),
			'อัญประกาศเดี่ยว' => array( "it's a test" ),
			'backslash'       => array( 'C:\\path\\to\\file' ),
			'JSON string'     => array( '{"key":"value","nested":{"a":1}}' ),
			'อีโมจิ'          => array( 'ทดสอบ 🎓 อีโมจิ' ),
			'array ซ้อน'      => array(
				array(
					'level1' => array(
						'level2' => array( 'a', 'b', 'c' ),
					),
				),
			),
			'ตัวเลขเป็นสตริง' => array( '0123' ),
			'ค่าว่าง'         => array( '' ),
		);
	}

	/**
	 * ส่ง ID ที่ไม่ถูกต้องต้องไม่ทำอะไรและไม่ error
	 *
	 * @return void
	 */
	public function test_invalid_ids_are_ignored() {
		$this->assertSame( array(), Post_Meta_Copier::copy( 0, $this->destination, array( '_video' ) ) );
		$this->assertSame( array(), Post_Meta_Copier::copy( $this->source, 0, array( '_video' ) ) );
	}

	/**
	 * ต้องรายงานกลับว่าคัดลอกคีย์ไหนไปบ้าง
	 *
	 * @return void
	 */
	public function test_returns_list_of_copied_keys() {
		update_post_meta( $this->source, '_video', array( 'source' => 'youtube' ) );
		update_post_meta( $this->source, '_is_preview', '1' );
		update_post_meta( $this->source, '_ignored', 'x' );

		$copied = Post_Meta_Copier::copy(
			$this->source,
			$this->destination,
			array( '_video', '_is_preview' )
		);

		sort( $copied );

		$this->assertSame( array( '_is_preview', '_video' ), $copied );
	}

	/**
	 * action หลังคัดลอกต้องถูกยิงพร้อมข้อมูลที่ถูกต้อง
	 *
	 * @return void
	 */
	public function test_fires_action_after_copy() {
		update_post_meta( $this->source, '_video', array( 'source' => 'youtube' ) );

		$captured = array();

		$callback = static function ( $destination, $source, $keys ) use ( &$captured ) {
			$captured = compact( 'destination', 'source', 'keys' );
		};

		add_action( 'tlcd_after_copy_meta', $callback, 10, 3 );
		Post_Meta_Copier::copy( $this->source, $this->destination, array( '_video' ) );
		remove_action( 'tlcd_after_copy_meta', $callback, 10 );

		$this->assertSame( $this->destination, $captured['destination'] );
		$this->assertSame( $this->source, $captured['source'] );
		$this->assertSame( array( '_video' ), $captured['keys'] );
	}
}
