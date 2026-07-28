<?php
/**
 * Permission checks.
 *
 * ใช้ can_user_manage() ของ Tutor LMS เป็นหลัก และมี fallback เป็น capability ของ WordPress
 *
 * @package TLCD
 */

namespace TLCD;

defined( 'ABSPATH' ) || exit;

/**
 * Class Permission
 */
class Permission {

	/**
	 * เรียก tutor_utils()->can_user_manage() ถ้ามี
	 *
	 * @param string $for     course|topic|lesson.
	 * @param int    $post_id Post ID.
	 *
	 * @return bool|null null = Tutor ไม่พร้อมให้ตรวจ.
	 */
	private static function tutor_can( $for, $post_id ) {
		if ( ! function_exists( 'tutor_utils' ) ) {
			return null;
		}

		$utils = tutor_utils();

		if ( ! is_object( $utils ) || ! method_exists( $utils, 'can_user_manage' ) ) {
			return null;
		}

		return (bool) $utils->can_user_manage( $for, $post_id );
	}

	/**
	 * ตรวจสิทธิ์แบบรวม
	 *
	 * @param string $for     course|topic|lesson.
	 * @param int    $post_id Post ID.
	 *
	 * @return bool
	 */
	private static function can( $for, $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || ! is_user_logged_in() ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$tutor_result = self::tutor_can( $for, $post_id );

		if ( null !== $tutor_result ) {
			$allowed = $tutor_result;
		} else {
			$allowed = current_user_can( 'edit_post', $post_id );
		}

		/**
		 * Filter ผลการตรวจสิทธิ์
		 *
		 * @param bool   $allowed ผลลัพธ์.
		 * @param string $for     course|topic|lesson.
		 * @param int    $post_id Post ID.
		 */
		return (bool) apply_filters( 'tlcd_user_can_manage', $allowed, $for, $post_id );
	}

	/**
	 * แก้ไขคอร์สนี้ได้หรือไม่
	 *
	 * @param int $course_id Course ID.
	 *
	 * @return bool
	 */
	public static function can_manage_course( $course_id ) {
		return self::can( 'course', $course_id );
	}

	/**
	 * แก้ไข Topic นี้ได้หรือไม่
	 *
	 * @param int $topic_id Topic ID.
	 *
	 * @return bool
	 */
	public static function can_manage_topic( $topic_id ) {
		return self::can( 'topic', $topic_id );
	}

	/**
	 * แก้ไข Lesson นี้ได้หรือไม่
	 *
	 * @param int $lesson_id Lesson ID.
	 *
	 * @return bool
	 */
	public static function can_manage_lesson( $lesson_id ) {
		return self::can( 'lesson', $lesson_id );
	}

	/**
	 * แก้ไขเนื้อหาใน Topic ชิ้นนี้ได้หรือไม่ (บทเรียน / แบบทดสอบ / งานที่มอบหมาย)
	 *
	 * `can_user_manage()` ของ Tutor รับ context เป็นคำเดี่ยว ๆ (lesson|quiz|assignment)
	 * ไม่ใช่ชื่อ post type จึงต้องแปลงก่อน
	 *
	 * @param int $content_id Post ID.
	 *
	 * @return bool
	 */
	public static function can_manage_content( $content_id ) {
		return self::can( self::content_context( $content_id ), $content_id );
	}

	/**
	 * แปลง post type เป็น context ที่ Tutor LMS เข้าใจ
	 *
	 * @param int $content_id Post ID.
	 *
	 * @return string
	 */
	public static function content_context( $content_id ) {
		$post_type = get_post_type( absint( $content_id ) );

		if ( Compatibility::quiz_post_type() === $post_type ) {
			return 'quiz';
		}

		if ( Compatibility::assignment_post_type() === $post_type ) {
			return 'assignment';
		}

		return 'lesson';
	}

	/**
	 * ผู้ใช้ปัจจุบันมีสิทธิ์เห็นปุ่ม Duplicate ในคอร์สนี้หรือไม่
	 *
	 * @param int $course_id Course ID.
	 *
	 * @return bool
	 */
	public static function can_use_builder_ui( $course_id ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( ! $course_id ) {
			// ยังไม่มีคอร์ส (กำลังสร้างใหม่) — ให้ผู้ที่สร้างคอร์สได้เท่านั้น.
			return current_user_can( 'edit_tutor_courses' ) || current_user_can( 'manage_options' );
		}

		return self::can_manage_course( $course_id );
	}
}
