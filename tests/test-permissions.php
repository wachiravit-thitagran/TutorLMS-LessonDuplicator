<?php
/**
 * ชุดทดสอบสิทธิ์และ REST endpoint
 *
 * ครอบคลุม TC-05 และ TC-06 จากแผนพัฒนา
 *
 * @package TLCD\Tests
 */

use TLCD\Permission;

/**
 * Class Test_Permissions
 */
class Test_Permissions extends TLCD_TestCase {

	/**
	 * โครงสร้างข้อมูลที่ seed ไว้
	 *
	 * @var array
	 */
	private $data;

	/**
	 * เจ้าของคอร์ส
	 *
	 * @var int
	 */
	private $owner_id;

	/**
	 * ผู้สอนคนอื่นที่ไม่ได้สอนคอร์สนี้
	 *
	 * @var int
	 */
	private $other_instructor_id;

	/**
	 * ผู้เรียน
	 *
	 * @var int
	 */
	private $student_id;

	/**
	 * REST server
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * ตั้งค่าเริ่มต้น
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->owner_id            = $this->create_instructor();
		$this->other_instructor_id = $this->create_instructor();
		$this->student_id          = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->data = TLCD_Seeder::full_course( array( 'author' => $this->owner_id ) );

		TLCD_Seeder::attach_instructor( $this->owner_id, $this->data['course_id'] );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		do_action( 'rest_api_init' );
	}

	/**
	 * เจ้าของคอร์สต้องแก้ไขได้
	 *
	 * @return void
	 */
	public function test_course_owner_can_manage() {
		wp_set_current_user( $this->owner_id );

		$this->assertTrue( Permission::can_manage_course( $this->data['course_id'] ) );
		$this->assertTrue( Permission::can_manage_topic( $this->data['topic_one'] ) );
		$this->assertTrue( Permission::can_manage_content( $this->data['lesson_a'] ) );
	}

	/**
	 * ผู้ดูแลระบบต้องแก้ไขได้เสมอ
	 *
	 * @return void
	 */
	public function test_administrator_can_manage() {
		wp_set_current_user( $this->admin_id() );

		$this->assertTrue( Permission::can_manage_course( $this->data['course_id'] ) );
		$this->assertTrue( Permission::can_manage_content( $this->data['lesson_a'] ) );
	}

	/**
	 * TC-05: ผู้สอนที่ไม่ใช่เจ้าของคอร์สต้องแก้ไขไม่ได้
	 *
	 * @return void
	 */
	public function test_other_instructor_cannot_manage() {
		wp_set_current_user( $this->other_instructor_id );

		$this->assertFalse( Permission::can_manage_course( $this->data['course_id'] ) );
		$this->assertFalse( Permission::can_manage_topic( $this->data['topic_one'] ) );
		$this->assertFalse( Permission::can_manage_content( $this->data['lesson_a'] ) );
	}

	/**
	 * ผู้เรียนต้องแก้ไขไม่ได้
	 *
	 * @return void
	 */
	public function test_student_cannot_manage() {
		wp_set_current_user( $this->student_id );

		$this->assertFalse( Permission::can_manage_course( $this->data['course_id'] ) );
		$this->assertFalse( Permission::can_manage_content( $this->data['lesson_a'] ) );
	}

	/**
	 * ผู้ที่ยังไม่ล็อกอินต้องแก้ไขไม่ได้
	 *
	 * @return void
	 */
	public function test_logged_out_cannot_manage() {
		wp_set_current_user( 0 );

		$this->assertFalse( Permission::can_manage_course( $this->data['course_id'] ) );
		$this->assertFalse( Permission::can_use_builder_ui( $this->data['course_id'] ) );
	}

	/**
	 * ต้องแปลง post type เป็น context ของ Tutor LMS ให้ถูกต้อง
	 *
	 * @return void
	 */
	public function test_maps_post_type_to_tutor_context() {
		$this->assertSame( 'lesson', Permission::content_context( $this->data['lesson_a'] ) );
		$this->assertSame( 'quiz', Permission::content_context( $this->data['quiz_id'] ) );
		$this->assertSame( 'assignment', Permission::content_context( $this->data['assignment_id'] ) );
	}

	/**
	 * TC-05: REST ต้องตอบ 403 และไม่สร้างรายการใหม่
	 *
	 * @return void
	 */
	public function test_rest_forbids_other_instructor() {
		wp_set_current_user( $this->other_instructor_id );

		$before = $this->count_lessons();

		$response = $this->dispatch_duplicate( $this->data['lesson_a'] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( $before, $this->count_lessons(), 'ต้องไม่มีบทเรียนใหม่ถูกสร้าง' );
	}

	/**
	 * ผู้เรียนต้องถูกปฏิเสธที่ REST
	 *
	 * @return void
	 */
	public function test_rest_forbids_student() {
		wp_set_current_user( $this->student_id );

		$response = $this->dispatch_duplicate( $this->data['lesson_a'] );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * ผู้ที่ยังไม่ล็อกอินต้องถูกปฏิเสธ
	 *
	 * @return void
	 */
	public function test_rest_requires_login() {
		wp_set_current_user( 0 );

		$response = $this->dispatch_duplicate( $this->data['lesson_a'] );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * เจ้าของคอร์สต้องทำสำเนาผ่าน REST ได้
	 *
	 * @return void
	 */
	public function test_rest_allows_course_owner() {
		wp_set_current_user( $this->owner_id );

		$response = $this->dispatch_duplicate( $this->data['lesson_a'] );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( (int) $this->data['lesson_a'], $data['data']['source_id'] );
		$this->assertGreaterThan( 0, $data['data']['duplicate_id'] );
	}

	/**
	 * ต้องไม่เชื่อ topic_id ที่ส่งมาจาก client
	 *
	 * @return void
	 */
	public function test_rest_rejects_mismatched_topic_id() {
		wp_set_current_user( $this->owner_id );

		$request = new WP_REST_Request( 'POST', '/tlcd/v1/contents/' . $this->data['lesson_a'] . '/duplicate' );
		$request->set_param( 'topic_id', $this->data['topic_mixed'] );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'tlcd_topic_mismatch', $response->get_data()['code'] );
	}

	/**
	 * TC-06: คำขอซ้ำระหว่างที่ยังทำงานอยู่ต้องถูกปฏิเสธ
	 *
	 * @return void
	 */
	public function test_rest_blocks_concurrent_duplicate_request() {
		global $wpdb;

		wp_set_current_user( $this->owner_id );

		$lock_name = '_tlcd_lock_' . md5( 'content:' . $this->data['lesson_a'] );

		// จำลองว่าผู้สอนอีกคนถือ lock ของรายการนี้อยู่.
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $lock_name,
				'option_value' => ( time() + 15 ) . '|other-request-token',
				'autoload'     => 'no',
			)
		);

		$response = $this->dispatch_duplicate( $this->data['lesson_a'] );

		delete_option( $lock_name );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'tlcd_duplicate_in_progress', $response->get_data()['code'] );
	}

	/**
	 * TC-06: lock ต้องถูกปลดหลังทำงานเสร็จ เพื่อให้กดครั้งต่อไปได้ทันที
	 *
	 * @return void
	 */
	public function test_lock_is_released_after_success() {
		wp_set_current_user( $this->owner_id );

		$this->dispatch_duplicate( $this->data['lesson_a'] );

		$this->assertFalse(
			get_option( '_tlcd_lock_' . md5( 'content:' . $this->data['lesson_a'] ), false ),
			'lock ต้องถูกปลดหลังคำขอเสร็จสิ้น'
		);
	}

	/**
	 * endpoint เดิมของเวอร์ชัน 1.0 ต้องยังใช้งานได้
	 *
	 * @return void
	 */
	public function test_legacy_lesson_endpoint_still_works() {
		wp_set_current_user( $this->owner_id );

		$request  = new WP_REST_Request( 'POST', '/tlcd/v1/lessons/' . $this->data['lesson_a'] . '/duplicate' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
	}

	/**
	 * endpoint เดิมต้องรับเฉพาะบทเรียน
	 *
	 * @return void
	 */
	public function test_legacy_endpoint_rejects_non_lesson() {
		wp_set_current_user( $this->owner_id );

		$request  = new WP_REST_Request( 'POST', '/tlcd/v1/lessons/' . $this->data['quiz_id'] . '/duplicate' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * endpoint ต้องรับเฉพาะ POST
	 *
	 * @return void
	 */
	public function test_duplicate_endpoint_rejects_get() {
		wp_set_current_user( $this->owner_id );

		$request  = new WP_REST_Request( 'GET', '/tlcd/v1/contents/' . $this->data['lesson_a'] . '/duplicate' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * curriculum endpoint ต้องคืนโครงสร้างครบและเรียงถูกต้อง
	 *
	 * @return void
	 */
	public function test_curriculum_endpoint_returns_full_structure() {
		wp_set_current_user( $this->owner_id );

		$request  = new WP_REST_Request( 'GET', '/tlcd/v1/courses/' . $this->data['course_id'] . '/curriculum' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data()['data'];

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 3, $data['topics'] );
		$this->assertSame( 'หัวข้อที่ 1', $data['topics'][0]['title'] );
		$this->assertCount( 3, $data['topics'][0]['contents'] );
		$this->assertSame( array(), $data['topics'][1]['contents'], 'หัวข้อว่างต้องคืน array ว่าง' );

		$mixed = $data['topics'][2]['contents'];

		$this->assertSame( 'lesson', $mixed[0]['post_type'] );
		$this->assertTrue( $mixed[0]['duplicable'] );
		$this->assertSame( 'tutor_quiz', $mixed[1]['post_type'] );
		$this->assertTrue( $mixed[1]['duplicable'] );
	}

	/**
	 * curriculum endpoint ต้องปฏิเสธผู้ที่ไม่มีสิทธิ์
	 *
	 * @return void
	 */
	public function test_curriculum_endpoint_is_protected() {
		wp_set_current_user( $this->student_id );

		$request  = new WP_REST_Request( 'GET', '/tlcd/v1/courses/' . $this->data['course_id'] . '/curriculum' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * ยิงคำขอทำสำเนาเนื้อหา
	 *
	 * @param int $content_id Content ID.
	 *
	 * @return WP_REST_Response
	 */
	private function dispatch_duplicate( $content_id ) {
		$request = new WP_REST_Request( 'POST', '/tlcd/v1/contents/' . $content_id . '/duplicate' );

		return $this->server->dispatch( $request );
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
