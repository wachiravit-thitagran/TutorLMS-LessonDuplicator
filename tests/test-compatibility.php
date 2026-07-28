<?php
/**
 * ชุดทดสอบ Compatibility layer
 *
 * คลาสนี้คือด่านที่ตัดสินว่าปลั๊กอินจะทำงานหรือหยุด เมื่อ Tutor LMS เปลี่ยนไป
 * จึงต้องมั่นใจว่าตรรกะการตัดสินใจถูกต้องทุกกรณี
 *
 * @package TLCD\Tests
 */

use TLCD\Compatibility;
use TLCD\Api\Content_Controller;

/**
 * Class Test_Compatibility
 */
class Test_Compatibility extends TLCD_TestCase {

	/**
	 * แยกสาย major.minor ได้ถูกต้อง
	 *
	 * @return void
	 */
	public function test_version_branch() {
		$this->assertSame( '4.0', Compatibility::version_branch( '4.0.2' ) );
		$this->assertSame( '4.1', Compatibility::version_branch( '4.1.0-beta1' ) );
		$this->assertSame( '3.7', Compatibility::version_branch( '3.7' ) );
		$this->assertSame( '10.2', Compatibility::version_branch( '10.2.15' ) );
		$this->assertSame( '', Compatibility::version_branch( '' ) );
	}

	/**
	 * post type ต้องมีค่า fallback ที่ถูกต้องเมื่อไม่มี Tutor LMS
	 *
	 * @return void
	 */
	public function test_post_type_defaults() {
		$this->assertSame( 'courses', Compatibility::course_post_type() );
		$this->assertSame( 'topics', Compatibility::topic_post_type() );
		$this->assertSame( 'lesson', Compatibility::lesson_post_type() );
		$this->assertSame( 'tutor_quiz', Compatibility::quiz_post_type() );
		$this->assertSame( 'tutor_assignments', Compatibility::assignment_post_type() );
	}

	/**
	 * รายการชนิดเนื้อหาต้องครอบคลุมทุกชนิดที่ Course Builder แสดง
	 *
	 * ถ้าขาดชนิดใดไป จำนวนแถวใน DOM จะไม่ตรงกับข้อมูล แล้วปุ่มจะไม่ถูกแทรก
	 *
	 * @return void
	 */
	public function test_topic_child_post_types_cover_builder_rows() {
		$types = Compatibility::topic_child_post_types();

		foreach ( array( 'lesson', 'tutor_quiz', 'tutor_assignments', 'tutor_h5p_quiz', 'tutor_zoom_meeting', 'tutor-google-meet' ) as $expected ) {
			$this->assertContains( $expected, $types, $expected . ' หายไปจากรายการชนิดเนื้อหา' );
		}

		$this->assertSame( array_unique( $types ), $types, 'ต้องไม่มีชนิดซ้ำ' );
	}

	/**
	 * ชนิดที่ทำสำเนาได้ต้องเป็นส่วนย่อยของชนิดที่แสดงในหัวข้อ
	 *
	 * @return void
	 */
	public function test_duplicable_types_are_subset_of_child_types() {
		$duplicable = Compatibility::duplicable_post_types();
		$children   = Compatibility::topic_child_post_types();

		$this->assertSame( array(), array_diff( $duplicable, $children ) );
		$this->assertContains( 'lesson', $duplicable );
		$this->assertNotContains( 'tutor_zoom_meeting', $duplicable );
	}

	/**
	 * filter ต้องเปลี่ยนรายการชนิดเนื้อหาได้
	 *
	 * @return void
	 */
	public function test_child_post_types_are_filterable() {
		$callback = static function ( $types ) {
			$types[] = 'my_custom_content';

			return $types;
		};

		add_filter( 'tlcd_topic_child_post_types', $callback );
		$types = Compatibility::topic_child_post_types();
		remove_filter( 'tlcd_topic_child_post_types', $callback );

		$this->assertContains( 'my_custom_content', $types );
	}

	/**
	 * สถานะที่นับต้องรวมฉบับร่าง แต่ไม่รวมถังขยะ
	 *
	 * @return void
	 */
	public function test_countable_statuses() {
		$statuses = Compatibility::countable_post_statuses();

		$this->assertContains( 'publish', $statuses );
		$this->assertContains( 'draft', $statuses );
		$this->assertContains( 'private', $statuses );
		$this->assertNotContains( 'trash', $statuses );
		$this->assertNotContains( 'auto-draft', $statuses );
	}

	/**
	 * หา course id จาก topic ได้ถูกต้อง
	 *
	 * @return void
	 */
	public function test_course_id_from_topic() {
		$course_id = TLCD_Seeder::course();
		$topic_id  = TLCD_Seeder::topic( $course_id );

		$this->assertSame( $course_id, Compatibility::course_id_from_topic( $topic_id ) );
	}

	/**
	 * topic ที่ไม่มี parent เป็นคอร์ส ต้องคืน 0 ไม่ใช่เดา
	 *
	 * @return void
	 */
	public function test_course_id_from_orphan_topic() {
		$orphan = TLCD_Seeder::topic( 0 );

		$this->assertSame( 0, Compatibility::course_id_from_topic( $orphan ) );
		$this->assertSame( 0, Compatibility::course_id_from_topic( 999999 ) );
	}

	/**
	 * ส่ง ID ที่ไม่ใช่ topic เข้าไปต้องคืน 0
	 *
	 * @return void
	 */
	public function test_course_id_from_topic_rejects_wrong_type() {
		$course_id = TLCD_Seeder::course();
		$topic_id  = TLCD_Seeder::topic( $course_id );
		$lesson_id = TLCD_Seeder::lesson( $topic_id );

		$this->assertSame( 0, Compatibility::course_id_from_topic( $lesson_id ) );
	}

	/**
	 * หา course id จาก lesson ได้ทั้งทาง parent และทาง meta
	 *
	 * @return void
	 */
	public function test_course_id_from_lesson() {
		$course_id = TLCD_Seeder::course();
		$topic_id  = TLCD_Seeder::topic( $course_id );
		$lesson_id = TLCD_Seeder::lesson( $topic_id );

		$this->assertSame( $course_id, Compatibility::course_id_from_lesson( $lesson_id ) );

		// บทเรียนกำพร้า — ต้องหาจาก meta แทน.
		$orphan = TLCD_Seeder::lesson( 0 );
		update_post_meta( $orphan, '_tutor_course_id_for_lesson', $course_id );

		$this->assertSame( $course_id, Compatibility::course_id_from_lesson( $orphan ) );
	}

	/**
	 * meta ที่ชี้ไปโพสต์ที่ไม่ใช่คอร์ส ต้องไม่ถูกเชื่อ
	 *
	 * @return void
	 */
	public function test_course_id_from_lesson_validates_meta() {
		$orphan = TLCD_Seeder::lesson( 0 );
		update_post_meta( $orphan, '_tutor_course_id_for_lesson', 999999 );

		$this->assertSame( 0, Compatibility::course_id_from_lesson( $orphan ) );
	}

	/**
	 * เมื่อไม่มี Tutor LMS ต้องรายงานว่าใช้งานไม่ได้และบล็อกการทำสำเนา
	 *
	 * @return void
	 */
	public function test_runtime_issues_when_tutor_missing() {
		if ( Compatibility::is_tutor_active() ) {
			$this->markTestSkipped( 'มี Tutor LMS ติดตั้งอยู่ — เทสต์นี้สำหรับกรณีไม่มีเท่านั้น' );
		}

		$issues = Compatibility::runtime_issues();

		$this->assertNotEmpty( $issues );
		$this->assertSame( 'tutor_missing', $issues[0]['code'] );
		$this->assertTrue( $issues[0]['blocking'] );
		$this->assertNotEmpty( Compatibility::blocking_runtime_issues() );
	}

	/**
	 * เมื่อมี Tutor LMS จริงและ post type ครบ ต้องไม่มีปัญหาระดับ blocking
	 *
	 * @return void
	 */
	public function test_no_blocking_issues_with_tutor_installed() {
		if ( ! Compatibility::is_tutor_active() ) {
			$this->markTestSkipped( 'ไม่มี Tutor LMS — เป็น integration test' );
		}

		$this->assertSame(
			array(),
			Compatibility::blocking_runtime_issues(),
			'ติดตั้ง Tutor LMS จริงแล้วต้องไม่มีปัญหาที่บล็อกการทำงาน'
		);
	}

	/**
	 * filter ต้องเพิ่มปัญหา runtime ได้ เพื่อให้จำลองกรณี post type หายได้
	 *
	 * @return void
	 */
	public function test_runtime_issues_are_filterable() {
		$callback = static function ( $issues ) {
			$issues[] = array(
				'code'     => 'simulated',
				'message'  => 'simulated failure',
				'blocking' => true,
			);

			return $issues;
		};

		add_filter( 'tlcd_runtime_issues', $callback );
		$blocking = Compatibility::blocking_runtime_issues();
		remove_filter( 'tlcd_runtime_issues', $callback );

		$codes = wp_list_pluck( $blocking, 'code' );

		$this->assertContains( 'simulated', $codes );
	}

	/**
	 * REST ต้องตอบ 409 เมื่อโครงสร้าง Tutor LMS ไม่ตรงกับที่รองรับ
	 *
	 * @return void
	 */
	public function test_rest_refuses_when_runtime_is_incompatible() {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$controller     = new Content_Controller();
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
		do_action( 'rest_api_init' );
		remove_action( 'rest_api_init', array( $controller, 'register_routes' ) );

		$course_id = TLCD_Seeder::course( array( 'author' => $this->admin_id() ) );
		$topic_id  = TLCD_Seeder::topic( $course_id, array( 'order' => 1 ) );
		$lesson_id = TLCD_Seeder::lesson( $topic_id, array( 'title' => 'บทเรียน' ) );

		$before = $this->count_lessons();

		$callback = static function ( $issues ) {
			$issues[] = array(
				'code'     => 'post_type_missing_simulated',
				'message'  => 'simulated missing post type',
				'blocking' => true,
			);

			return $issues;
		};

		add_filter( 'tlcd_runtime_issues', $callback );

		$request  = new WP_REST_Request( 'POST', '/tlcd/v1/contents/' . $lesson_id . '/duplicate' );
		$response = $wp_rest_server->dispatch( $request );

		remove_filter( 'tlcd_runtime_issues', $callback );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'tlcd_incompatible_runtime', $response->get_data()['code'] );
		$this->assertSame( $before, $this->count_lessons(), 'ต้องไม่สร้างอะไรเลยเมื่อโครงสร้างไม่ตรง' );
	}

	/**
	 * ค่าที่ pin ไว้ในไฟล์หลักต้องสมเหตุสมผล
	 *
	 * @return void
	 */
	public function test_pinned_versions_are_consistent() {
		$this->assertTrue( defined( 'TLCD_MIN_TUTOR_VERSION' ) );
		$this->assertTrue( defined( 'TLCD_TESTED_TUTOR_VERSION' ) );
		$this->assertTrue( defined( 'TLCD_TESTED_TUTOR_BRANCH' ) );

		$this->assertTrue(
			version_compare( TLCD_TESTED_TUTOR_VERSION, TLCD_MIN_TUTOR_VERSION, '>=' ),
			'เวอร์ชันที่ทดสอบต้องไม่ต่ำกว่าเวอร์ชันขั้นต่ำ'
		);
		$this->assertSame(
			TLCD_TESTED_TUTOR_BRANCH,
			Compatibility::version_branch( TLCD_TESTED_TUTOR_VERSION ),
			'สายที่ pin ไว้ต้องตรงกับเวอร์ชันที่ทดสอบ'
		);
	}

	/**
	 * เวอร์ชันในไฟล์ปลั๊กอิน readme.txt และค่าคงที่ต้องตรงกัน
	 *
	 * @return void
	 */
	public function test_version_numbers_match_across_files() {
		$root = dirname( __DIR__ );

		$plugin_file = file_get_contents( $root . '/tutor-lms-curriculum-duplicator.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$readme      = file_get_contents( $root . '/readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		preg_match( '/^ \* Version:\s*(.+)$/m', $plugin_file, $header );
		preg_match( '/^Stable tag:\s*(.+)$/m', $readme, $stable );

		$this->assertSame( TLCD_VERSION, trim( $header[1] ), 'Version header ต้องตรงกับ TLCD_VERSION' );
		$this->assertSame( TLCD_VERSION, trim( $stable[1] ), 'Stable tag ใน readme.txt ต้องตรงกับ TLCD_VERSION' );
	}

	/**
	 * นับจำนวนบทเรียนทั้งหมด
	 *
	 * @return int
	 */
	private function count_lessons() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'trash'",
				'lesson'
			)
		);
		// phpcs:enable
	}
}
