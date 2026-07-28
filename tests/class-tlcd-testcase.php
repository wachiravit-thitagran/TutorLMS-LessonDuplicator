<?php
/**
 * Base test case
 *
 * เตรียม post type และตารางของ Tutor LMS ให้พร้อมก่อนทุกชุดทดสอบ เพื่อให้ชุดทดสอบ
 * รันได้ทั้งกรณีที่ติดตั้ง Tutor LMS จริง (integration) และกรณีจำลองโครงสร้าง (unit)
 *
 * @package TLCD\Tests
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class TLCD_TestCase
 */
abstract class TLCD_TestCase extends WP_UnitTestCase {

	/**
	 * ตั้งค่าเริ่มต้นของแต่ละเทสต์
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		TLCD_Seeder::register_post_types();
		TLCD_Seeder::ensure_quiz_tables();

		// รีเซ็ตผู้ใช้เป็น admin เพื่อให้การตรวจสิทธิ์ไม่รบกวนเทสต์ที่ไม่ได้ทดสอบสิทธิ์.
		wp_set_current_user( $this->admin_id() );
	}

	/**
	 * ล้างสถานะหลังแต่ละเทสต์
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}tutor_quiz_questions" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}tutor_quiz_question_answers" );
		// phpcs:enable

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * ผู้ดูแลระบบสำหรับใช้ในเทสต์ (สร้างครั้งเดียวต่อ process)
	 *
	 * @return int
	 */
	protected function admin_id() {
		static $admin_id = null;

		if ( null === $admin_id || ! get_userdata( $admin_id ) ) {
			$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		}

		return $admin_id;
	}

	/**
	 * สร้างผู้ใช้บทบาท tutor_instructor (สร้าง role ให้ถ้ายังไม่มี)
	 *
	 * @return int
	 */
	protected function create_instructor() {
		/*
		 * เมื่อรันโดยไม่มี Tutor LMS ปลั๊กอินจะ fallback ไปใช้ `edit_post` ของ WordPress
		 * จึงต้องให้ capability ครบพอที่ผู้สอนจะแก้โพสต์ของตัวเองที่เผยแพร่แล้วได้
		 * แต่ต้องไม่ให้ `edit_others_posts` เพื่อให้ทดสอบกรณี "ไม่ใช่เจ้าของคอร์ส" ได้จริง
		 */
		if ( ! get_role( 'tutor_instructor' ) ) {
			add_role(
				'tutor_instructor',
				'Tutor Instructor',
				array(
					'read'                   => true,
					'upload_files'           => true,
					'edit_posts'             => true,
					'edit_published_posts'   => true,
					'publish_posts'          => true,
					'delete_posts'           => true,
					'delete_published_posts' => true,
					'edit_tutor_courses'     => true,
					'publish_tutor_courses'  => true,
				)
			);
		}

		return self::factory()->user->create( array( 'role' => 'tutor_instructor' ) );
	}

	/**
	 * ตรวจว่าลำดับของลูกภายใต้ parent ตรงกับที่คาดหวัง
	 *
	 * @param int[]  $expected_ids ลำดับที่คาดหวัง.
	 * @param int    $parent_id    Parent post ID.
	 * @param string $message      ข้อความเมื่อไม่ผ่าน.
	 *
	 * @return void
	 */
	protected function assertCurriculumOrder( array $expected_ids, $parent_id, $message = '' ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName
		$actual = \TLCD\Curriculum_Order::get_ordered_children(
			$parent_id,
			\TLCD\Compatibility::topic_child_post_types()
		);

		if ( ! $actual ) {
			$actual = \TLCD\Curriculum_Order::get_ordered_children(
				$parent_id,
				array( \TLCD\Compatibility::topic_post_type() )
			);
		}

		$this->assertSame( array_map( 'intval', $expected_ids ), $actual, $message );
	}

	/**
	 * นับจำนวนคำถามของแบบทดสอบ
	 *
	 * @param int $quiz_id Quiz ID.
	 *
	 * @return int
	 */
	protected function count_quiz_questions( $quiz_id ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'tutor_quiz_questions WHERE quiz_id = %d',
				absint( $quiz_id )
			)
		);
		// phpcs:enable
	}

	/**
	 * ดึงคำถามทั้งหมดของแบบทดสอบ เรียงตามลำดับ
	 *
	 * @param int $quiz_id Quiz ID.
	 *
	 * @return array
	 */
	protected function get_quiz_questions( $quiz_id ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'tutor_quiz_questions WHERE quiz_id = %d ORDER BY question_order ASC',
				absint( $quiz_id )
			),
			ARRAY_A
		);
		// phpcs:enable
	}

	/**
	 * ดึงตัวเลือกคำตอบของคำถาม
	 *
	 * @param int $question_id Question ID.
	 *
	 * @return array
	 */
	protected function get_question_answers( $question_id ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'tutor_quiz_question_answers WHERE belongs_question_id = %d ORDER BY answer_order ASC',
				absint( $question_id )
			),
			ARRAY_A
		);
		// phpcs:enable
	}
}
