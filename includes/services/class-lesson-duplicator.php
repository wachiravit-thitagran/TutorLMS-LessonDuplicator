<?php
/**
 * Lesson Duplicator Service
 *
 * ทำสำเนาบทเรียนหนึ่งรายการ พร้อมเนื้อหา วิดีโอ ไฟล์แนบ และการตั้งค่าที่เกี่ยวข้อง
 * โดยไม่แตะข้อมูลความก้าวหน้าของผู้เรียน
 *
 * ขั้นตอนหลักอยู่ใน Content_Duplicator — คลาสนี้กำหนดเฉพาะส่วนที่เป็นของบทเรียน
 *
 * @package TLCD
 */

namespace TLCD\Services;

use TLCD\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Class Lesson_Duplicator
 */
class Lesson_Duplicator extends Content_Duplicator {

	/**
	 * post type ของบทเรียน
	 *
	 * @return string
	 */
	public function post_type() {
		return Compatibility::lesson_post_type();
	}

	/**
	 * ชื่อสั้นของชนิดเนื้อหา
	 *
	 * @return string
	 */
	protected function type_slug() {
		return 'lesson';
	}

	/**
	 * meta key ที่ต้องคัดลอก
	 *
	 * @return string[]
	 */
	protected function meta_allowlist() {
		return Post_Meta_Copier::lesson_allowlist();
	}

	/**
	 * meta ที่เก็บ course id ของบทเรียน
	 *
	 * @return string[]
	 */
	protected function course_reference_meta_keys() {
		return array( '_tutor_course_id_for_lesson' );
	}

	/**
	 * ข้อความ error เมื่อไม่พบต้นฉบับ
	 *
	 * @return string
	 */
	protected function not_found_message() {
		return __( 'The source lesson was not found.', 'tutor-lms-curriculum-duplicator' );
	}

	/**
	 * ข้อความ error เมื่อชนิดไม่ตรง
	 *
	 * @return string
	 */
	protected function wrong_type_message() {
		return __( 'The selected item is not a lesson.', 'tutor-lms-curriculum-duplicator' );
	}

	/**
	 * ยิง hook ของ Tutor LMS หลังสร้างบทเรียน (ถ้าเปิดใช้)
	 *
	 * @param int      $new_id    ID ใหม่.
	 * @param \WP_Post $source    โพสต์ต้นฉบับ.
	 * @param int      $topic_id  Topic ปลายทาง.
	 * @param int      $course_id Course ปลายทาง.
	 *
	 * @return true
	 */
	protected function after_insert( $new_id, $source, $topic_id, $course_id ) {
		unset( $source, $topic_id, $course_id );

		/**
		 * Filter เปิด/ปิดการยิง hook ของ Tutor LMS หลังสร้างบทเรียน
		 *
		 * ค่าเริ่มต้นคือ false เพื่อไม่ให้ addon ที่ hook อยู่เขียนทับข้อมูลที่เพิ่งคัดลอกมา
		 *
		 * @param bool $fire ยิง hook หรือไม่.
		 */
		if ( apply_filters( 'tlcd_fire_tutor_created_hooks', false ) ) {
			do_action( 'tutor/lesson/created', $new_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		}

		return true;
	}
}
