<?php
/**
 * Assignment Duplicator Service
 *
 * งานที่มอบหมายเก็บข้อมูลทั้งหมดไว้ใน post meta:
 *   assignment_option                  — คะแนน, เกณฑ์ผ่าน, เวลาส่ง, ขนาดไฟล์
 *   _tutor_assignment_attachments      — attachment ID ของไฟล์ประกอบ
 *   _tutor_course_id_for_assignments   — course id (เขียนทับด้วยคอร์สปลายทางเสมอ)
 *
 * ส่วนงานที่ผู้เรียนส่ง (assignment submission) เก็บใน comments table และต้องไม่ถูกคัดลอก
 *
 * @package TLCD
 */

namespace TLCD\Services;

use TLCD\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Class Assignment_Duplicator
 */
class Assignment_Duplicator extends Content_Duplicator {

	/**
	 * post type ของงานที่มอบหมาย
	 *
	 * @return string
	 */
	public function post_type() {
		return Compatibility::assignment_post_type();
	}

	/**
	 * ชื่อสั้นของชนิดเนื้อหา
	 *
	 * @return string
	 */
	protected function type_slug() {
		return 'assignment';
	}

	/**
	 * meta key ที่ต้องคัดลอก
	 *
	 * @return string[]
	 */
	protected function meta_allowlist() {
		return Post_Meta_Copier::assignment_allowlist();
	}

	/**
	 * meta ที่เก็บ course id ของงานที่มอบหมาย
	 *
	 * @return string[]
	 */
	protected function course_reference_meta_keys() {
		return array( '_tutor_course_id_for_assignments' );
	}

	/**
	 * ข้อความ error เมื่อไม่พบต้นฉบับ
	 *
	 * @return string
	 */
	protected function not_found_message() {
		return __( 'The source assignment was not found.', 'tutor-lms-curriculum-duplicator' );
	}

	/**
	 * ข้อความ error เมื่อชนิดไม่ตรง
	 *
	 * @return string
	 */
	protected function wrong_type_message() {
		return __( 'The selected item is not an assignment.', 'tutor-lms-curriculum-duplicator' );
	}
}
