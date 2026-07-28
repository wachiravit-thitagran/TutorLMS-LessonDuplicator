<?php
/**
 * คัดลอก post meta ตามหลัก Allowlist ก่อน Blocklist
 *
 * เหตุผลที่ใช้ allowlist เป็นค่าเริ่มต้น: การคัดลอก meta ทุกคีย์มีความเสี่ยงที่จะ
 * ลากข้อมูลความก้าวหน้าผู้เรียน, cache, หรือ builder state ติดมาด้วย
 *
 * ถ้าเว็บไซต์มี addon ที่เก็บ meta เพิ่ม สามารถเพิ่มคีย์ผ่าน filter `tlcd_lesson_meta_allowlist`
 * หรือสลับไปโหมด blocklist ผ่าน filter `tlcd_meta_copy_mode`
 *
 * @package TLCD
 */

namespace TLCD\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Class Post_Meta_Copier
 */
class Post_Meta_Copier {

	const MODE_ALLOWLIST = 'allowlist';
	const MODE_BLOCKLIST = 'blocklist';

	/**
	 * Meta keys ของ Lesson ที่ต้องคัดลอก
	 *
	 * @return string[]
	 */
	public static function lesson_allowlist() {
		$keys = array(
			// Tutor LMS core.
			'_thumbnail_id',              // Featured image.
			'_video',                     // แหล่งวิดีโอ + runtime.
			'_tutor_attachments',         // Exercise files.
			'_is_preview',                // Course preview addon.
			'_tutor_course_id_for_lesson', // จะถูกเขียนทับด้วย course id ปลายทาง.
			'_content_drip_settings',     // Content drip addon.

			// Page builder: Elementor.
			'_elementor_edit_mode',
			'_elementor_template_type',
			'_elementor_version',
			'_elementor_pro_version',
			'_elementor_data',
			'_elementor_page_settings',
			'_elementor_controls_usage',

			// Page builder: Divi.
			'_et_pb_use_builder',
			'_et_pb_old_content',
			'_et_pb_page_layout',
			'_et_pb_side_nav',
			'_et_pb_post_hide_nav',
			'_et_builder_version',

			// WordPress.
			'_wp_page_template',
		);

		/**
		 * Filter รายการ meta key ของ Lesson ที่จะคัดลอก
		 *
		 * @param string[] $keys Meta keys.
		 */
		return array_values( array_unique( (array) apply_filters( 'tlcd_lesson_meta_allowlist', $keys ) ) );
	}

	/**
	 * Meta keys ของ Quiz ที่ต้องคัดลอก
	 *
	 * คำถามและตัวเลือกของแบบทดสอบไม่ได้อยู่ใน meta แต่อยู่ในตาราง
	 * `tutor_quiz_questions` / `tutor_quiz_question_answers` (ดู Quiz_Duplicator)
	 *
	 * @return string[]
	 */
	public static function quiz_allowlist() {
		$keys = array(
			'tutor_quiz_option',      // Tutor\Quiz::META_QUIZ_OPTION.
			'_thumbnail_id',
			'_content_drip_settings',
		);

		/**
		 * Filter รายการ meta key ของ Quiz ที่จะคัดลอก
		 *
		 * @param string[] $keys Meta keys.
		 */
		return array_values( array_unique( (array) apply_filters( 'tlcd_quiz_meta_allowlist', $keys ) ) );
	}

	/**
	 * Meta keys ของ Assignment ที่ต้องคัดลอก
	 *
	 * @return string[]
	 */
	public static function assignment_allowlist() {
		$keys = array(
			'assignment_option',                // คะแนน เกณฑ์ผ่าน เวลาส่ง ขนาดไฟล์.
			'_tutor_assignment_attachments',    // ไฟล์ประกอบ.
			'_tutor_course_id_for_assignments', // จะถูกเขียนทับด้วย course id ปลายทาง.
			'_thumbnail_id',
			'_content_drip_settings',
		);

		/**
		 * Filter รายการ meta key ของ Assignment ที่จะคัดลอก
		 *
		 * @param string[] $keys Meta keys.
		 */
		return array_values( array_unique( (array) apply_filters( 'tlcd_assignment_meta_allowlist', $keys ) ) );
	}

	/**
	 * Meta keys ของ Topic ที่ต้องคัดลอก
	 *
	 * @return string[]
	 */
	public static function topic_allowlist() {
		$keys = array(
			'_content_drip_settings',
		);

		/**
		 * Filter รายการ meta key ของ Topic ที่จะคัดลอก
		 *
		 * @param string[] $keys Meta keys.
		 */
		return array_values( array_unique( (array) apply_filters( 'tlcd_topic_meta_allowlist', $keys ) ) );
	}

	/**
	 * Meta keys ที่ห้ามคัดลอกเด็ดขาด (ตรวจแบบตรงตัว)
	 *
	 * @return string[]
	 */
	public static function blocklist() {
		$keys = array(
			'_edit_lock',
			'_edit_last',
			'_wp_old_slug',
			'_wp_old_date',
			'_wp_trash_meta_status',
			'_wp_trash_meta_time',
			'_wp_desired_post_slug',
			'_pingme',
			'_encloseme',
			'_thumbnail_url',
			'_tutor_course_product_id',
		);

		/**
		 * Filter รายการ meta key ที่ห้ามคัดลอก
		 *
		 * @param string[] $keys Meta keys.
		 */
		return array_values( array_unique( (array) apply_filters( 'tlcd_meta_blocklist', $keys ) ) );
	}

	/**
	 * รูปแบบ meta key ที่ห้ามคัดลอก (ตรวจแบบ prefix / substring)
	 *
	 * @return string[] regex patterns
	 */
	public static function blocked_patterns() {
		$patterns = array(
			'/^_transient_/',
			'/^_site_transient_/',
			'/^_tutor_completed_/',
			'/^_tutor_quiz_attempt/',
			'/^_lesson_reading_info/',
			'/^_tutor_enrolled/',
			'/^_tutor_course_progress/',
			'/^_tutor_attempt/',
			'/^_tutor_assignment_submission/',
			'/^_tutor_assignment_evaluate/',
			'/_view_count$/',
			'/^_oembed_/',
			'/^_et_dynamic_cached_/',
			'/^_et_builder_module_features_cache$/',
			'/^_elementor_page_assets$/',
			'/^_elementor_css$/',
			'/^_elementor_element_cache$/',
			'/^_elementor_inline_svg$/',
		);

		/**
		 * Filter regex ของ meta key ที่ห้ามคัดลอก
		 *
		 * @param string[] $patterns Regex patterns.
		 */
		return (array) apply_filters( 'tlcd_meta_blocked_patterns', $patterns );
	}

	/**
	 * โหมดการคัดลอก meta
	 *
	 * @return string allowlist|blocklist
	 */
	public static function mode() {
		$mode = apply_filters( 'tlcd_meta_copy_mode', self::MODE_ALLOWLIST );

		return self::MODE_BLOCKLIST === $mode ? self::MODE_BLOCKLIST : self::MODE_ALLOWLIST;
	}

	/**
	 * คีย์นี้ถูกบล็อกหรือไม่
	 *
	 * @param string $key Meta key.
	 *
	 * @return bool
	 */
	public static function is_blocked( $key ) {
		if ( in_array( $key, self::blocklist(), true ) ) {
			return true;
		}

		foreach ( self::blocked_patterns() as $pattern ) {
			if ( preg_match( $pattern, $key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * คัดลอก meta จากโพสต์ต้นทางไปโพสต์ปลายทาง
	 *
	 * @param int      $source_id      Source post ID.
	 * @param int      $destination_id Destination post ID.
	 * @param string[] $allowlist      รายการคีย์ที่อนุญาต (ใช้เมื่ออยู่ในโหมด allowlist).
	 *
	 * @return string[]|\WP_Error คีย์ที่คัดลอกจริง
	 */
	public static function copy( $source_id, $destination_id, array $allowlist ) {
		$source_id      = absint( $source_id );
		$destination_id = absint( $destination_id );

		if ( ! $source_id || ! $destination_id ) {
			return array();
		}

		$all_meta = get_post_meta( $source_id );

		if ( ! is_array( $all_meta ) ) {
			return array();
		}

		$mode   = self::mode();
		$copied = array();

		foreach ( $all_meta as $key => $unused_values ) {
			if ( self::is_blocked( $key ) ) {
				continue;
			}

			if ( self::MODE_ALLOWLIST === $mode && ! in_array( $key, $allowlist, true ) ) {
				continue;
			}

			delete_post_meta( $destination_id, $key );

			// อ่านทีละ key เพื่อให้ WordPress คืนค่าที่ unserialize แล้วอย่างถูกต้อง.
			$values = get_post_meta( $source_id, $key, false );

			foreach ( (array) $values as $value ) {
				// add_post_meta() จะ unslash ก่อนจัดเก็บ จึงต้อง slash ค่ากลับก่อน.
				if ( false === add_post_meta( $destination_id, $key, wp_slash( $value ) ) ) {
					return new \WP_Error(
						'tlcd_meta_copy_failed',
						__( 'Could not copy the item settings.', 'tutor-lms-curriculum-duplicator' ),
						array( 'status' => 500 )
					);
				}
			}

			$copied[] = $key;
		}

		/**
		 * Action หลังคัดลอก meta เสร็จ
		 *
		 * @param int      $destination_id Destination post ID.
		 * @param int      $source_id      Source post ID.
		 * @param string[] $copied         คีย์ที่คัดลอก.
		 */
		do_action( 'tlcd_after_copy_meta', $destination_id, $source_id, $copied );

		return $copied;
	}
}
