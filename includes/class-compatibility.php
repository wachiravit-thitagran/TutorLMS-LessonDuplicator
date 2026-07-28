<?php
/**
 * Compatibility layer.
 *
 * รวมทุกจุดที่ต้อง "รู้จัก" Tutor LMS ไว้ในคลาสเดียว เพื่อให้ส่วน Duplicate Service
 * ไม่ต้องผูกกับโครงสร้างภายในของ Tutor LMS โดยตรง
 *
 * @package TLCD
 */

namespace TLCD;

defined( 'ABSPATH' ) || exit;

/**
 * Class Compatibility
 */
class Compatibility {

	const BUILDER_REACT  = 'react';
	const BUILDER_LEGACY = 'legacy';

	/**
	 * Check PHP requirement.
	 *
	 * @return bool
	 */
	public static function is_php_supported() {
		return version_compare( PHP_VERSION, '7.4', '>=' );
	}

	/**
	 * Is Tutor LMS loaded?
	 *
	 * @return bool
	 */
	public static function is_tutor_active() {
		return function_exists( 'tutor' ) && defined( 'TUTOR_VERSION' );
	}

	/**
	 * Installed Tutor LMS version.
	 *
	 * @return string
	 */
	public static function tutor_version() {
		return defined( 'TUTOR_VERSION' ) ? (string) TUTOR_VERSION : '';
	}

	/**
	 * เวอร์ชัน Tutor LMS ล่าสุดที่ตรวจสอบซอร์สแล้ว
	 *
	 * @return string
	 */
	public static function tested_version() {
		return TLCD_TESTED_TUTOR_VERSION;
	}

	/**
	 * Is the installed Tutor LMS version supported?
	 *
	 * @return bool
	 */
	public static function is_supported() {
		if ( ! self::is_tutor_active() ) {
			return false;
		}

		return version_compare( self::tutor_version(), TLCD_MIN_TUTOR_VERSION, '>=' );
	}

	/**
	 * ดึงสาย major.minor ของเวอร์ชัน เช่น "4.0.2" => "4.0"
	 *
	 * @param string $version เวอร์ชัน.
	 *
	 * @return string
	 */
	public static function version_branch( $version ) {
		return preg_match( '/^(\d+)\.(\d+)/', (string) $version, $matches )
			? $matches[1] . '.' . $matches[2]
			: (string) $version;
	}

	/**
	 * เวอร์ชันที่ติดตั้งอยู่ใหม่กว่าที่ทดสอบไว้ (ในระดับ minor/major) หรือไม่
	 *
	 * patch release ในสายเดียวกันไม่ถือว่าใหม่กว่า เพราะแทบไม่แตะโครงสร้างข้อมูล
	 *
	 * @return bool
	 */
	public static function is_untested_newer() {
		if ( ! self::is_tutor_active() ) {
			return false;
		}

		$installed = self::tutor_version();

		if ( version_compare( $installed, TLCD_TESTED_TUTOR_VERSION, '<=' ) ) {
			return false;
		}

		return self::version_branch( $installed ) !== TLCD_TESTED_TUTOR_BRANCH;
	}

	/**
	 * ตรวจว่าโครงสร้างที่ปลั๊กอินพึ่งพายังมีอยู่จริงในเวอร์ชันที่ติดตั้ง
	 *
	 * นี่คือด่านสุดท้ายกันการคัดลอกผิด: ต่อให้เวอร์ชันใหม่กว่าที่ทดสอบ ปลั๊กอินก็ยัง
	 * ปฏิเสธการทำงานถ้าสมมติฐานหลัก (post type / ความสัมพันธ์) หายไป
	 *
	 * @return array[] แต่ละรายการมี key: code, message, blocking
	 */
	public static function runtime_issues() {
		$issues = array();

		if ( ! self::is_tutor_active() ) {
			$issues[] = array(
				'code'     => 'tutor_missing',
				'message'  => __( 'Tutor LMS was not found.', 'tutor-lms-curriculum-duplicator' ),
				'blocking' => true,
			);

			return (array) apply_filters( 'tlcd_runtime_issues', $issues );
		}

		$required_post_types = array(
			self::course_post_type() => __( 'course', 'tutor-lms-curriculum-duplicator' ),
			self::topic_post_type()  => __( 'topic', 'tutor-lms-curriculum-duplicator' ),
			self::lesson_post_type() => __( 'lesson', 'tutor-lms-curriculum-duplicator' ),
		);

		foreach ( $required_post_types as $post_type => $label ) {
			if ( ! post_type_exists( $post_type ) ) {
				$issues[] = array(
					'code'     => 'post_type_missing_' . $post_type,
					'message'  => sprintf(
						/* translators: 1: human label, 2: post type name */
						__( 'The %1$s post type (%2$s) was not found.', 'tutor-lms-curriculum-duplicator' ),
						$label,
						$post_type
					),
					'blocking' => true,
				);
			}
		}

		if ( ! function_exists( 'tutor_utils' ) || ! method_exists( tutor_utils(), 'can_user_manage' ) ) {
			$issues[] = array(
				'code'     => 'can_user_manage_missing',
				'message'  => __( 'tutor_utils()->can_user_manage() was not found — the plugin will fall back to standard WordPress capabilities.', 'tutor-lms-curriculum-duplicator' ),
				'blocking' => false,
			);
		}

		/**
		 * Filter รายการปัญหาที่ตรวจพบตอน runtime
		 *
		 * @param array[] $issues รายการปัญหา.
		 */
		return (array) apply_filters( 'tlcd_runtime_issues', $issues );
	}

	/**
	 * มีปัญหาระดับที่ต้องหยุดการทำงานหรือไม่
	 *
	 * @return array[] ปัญหาที่ blocking (ว่าง = ผ่าน)
	 */
	public static function blocking_runtime_issues() {
		return array_values(
			array_filter(
				self::runtime_issues(),
				static function ( $issue ) {
					return ! empty( $issue['blocking'] );
				}
			)
		);
	}

	/**
	 * Is Tutor LMS Pro active? (ถ้ามี Pro อยู่แล้ว ปลั๊กอินนี้ไม่จำเป็น)
	 *
	 * @return bool
	 */
	public static function is_tutor_pro_active() {
		return self::is_tutor_active() && ! empty( tutor()->has_pro );
	}

	/**
	 * Course post type.
	 *
	 * @return string
	 */
	public static function course_post_type() {
		if ( self::is_tutor_active() && ! empty( tutor()->course_post_type ) ) {
			return tutor()->course_post_type;
		}

		return 'courses';
	}

	/**
	 * Lesson post type.
	 *
	 * @return string
	 */
	public static function lesson_post_type() {
		if ( self::is_tutor_active() && ! empty( tutor()->lesson_post_type ) ) {
			return tutor()->lesson_post_type;
		}

		return 'lesson';
	}

	/**
	 * Topic post type.
	 *
	 * Tutor LMS เปิดให้เปลี่ยนค่านี้ผ่าน filter `tutor_topics_post_type` จึงต้องอ่านจาก
	 * config ของ Tutor ก่อน แล้วค่อย fallback เป็นค่าเริ่มต้น
	 *
	 * @return string
	 */
	public static function topic_post_type() {
		if ( self::is_tutor_active() && ! empty( tutor()->topics_post_type ) ) {
			return tutor()->topics_post_type;
		}

		return 'topics';
	}

	/**
	 * Quiz post type.
	 *
	 * @return string
	 */
	public static function quiz_post_type() {
		if ( self::is_tutor_active() && ! empty( tutor()->quiz_post_type ) ) {
			return tutor()->quiz_post_type;
		}

		return 'tutor_quiz';
	}

	/**
	 * Assignment post type.
	 *
	 * @return string
	 */
	public static function assignment_post_type() {
		if ( self::is_tutor_active() && ! empty( tutor()->assignment_post_type ) ) {
			return tutor()->assignment_post_type;
		}

		return 'tutor_assignments';
	}

	/**
	 * Post types ที่สามารถอยู่ใต้ Topic ได้ (ใช้ตอนจัดลำดับ)
	 *
	 * ต้องครบทุกชนิดที่ Course Builder แสดงในแถวเนื้อหา ไม่ใช่เฉพาะชนิดที่ทำสำเนาได้
	 * เพราะรายการนี้ใช้เทียบจำนวนแถวใน DOM กับข้อมูลจาก REST ด้วย
	 *
	 * @return string[]
	 */
	public static function topic_child_post_types() {
		$types = array(
			self::lesson_post_type(),
			self::quiz_post_type(),
			self::assignment_post_type(),
			'tutor_h5p_quiz',      // Interactive Quiz (addon).
			'tutor_zoom_meeting',  // Zoom (addon).
			'tutor-google-meet',   // Google Meet (addon).
		);

		if ( self::is_tutor_active() ) {
			if ( ! empty( tutor()->zoom_post_type ) ) {
				$types[] = tutor()->zoom_post_type;
			}

			if ( ! empty( tutor()->meet_post_type ) ) {
				$types[] = tutor()->meet_post_type;
			}
		}

		/**
		 * Filter post types ที่ถือว่าเป็น "เนื้อหาใน Topic"
		 *
		 * @param string[] $types Post types.
		 */
		return array_values( array_unique( (array) apply_filters( 'tlcd_topic_child_post_types', $types ) ) );
	}

	/**
	 * Post types ที่ปลั๊กอินทำสำเนาได้จริงในเวอร์ชันนี้
	 *
	 * @return string[]
	 */
	public static function duplicable_post_types() {
		$types = array(
			self::lesson_post_type(),
			self::quiz_post_type(),
			self::assignment_post_type(),
		);

		/**
		 * Filter post types ที่ทำสำเนาได้
		 *
		 * @param string[] $types Post types.
		 */
		return array_values( array_unique( (array) apply_filters( 'tlcd_duplicable_post_types', $types ) ) );
	}

	/**
	 * สถานะโพสต์ที่นับเป็น "รายการที่มีอยู่จริง" ใน Curriculum
	 *
	 * ต้องตรงกับที่ Course Builder แสดง มิฉะนั้นจำนวนแถวใน DOM จะไม่ตรงกับข้อมูล
	 *
	 * @return string[]
	 */
	public static function countable_post_statuses() {
		$statuses = array( 'publish', 'private', 'draft', 'pending', 'future' );

		/**
		 * Filter สถานะโพสต์ที่นับเป็นรายการใน Curriculum
		 *
		 * @param string[] $statuses Post statuses.
		 */
		return array_values( array_unique( (array) apply_filters( 'tlcd_countable_post_statuses', $statuses ) ) );
	}

	/**
	 * Which Course Builder is running on the current request?
	 *
	 * @return string|null react|legacy|null
	 */
	public static function current_builder_context() {
		if ( ! self::is_tutor_active() ) {
			return null;
		}

		$is_backend_builder = is_admin()
			&& isset( $_GET['page'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& 'create-course' === sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$is_frontend_builder = false;
		if ( ! is_admin() && function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'is_tutor_frontend_dashboard' ) ) {
			$is_frontend_builder = (bool) tutor_utils()->is_tutor_frontend_dashboard( 'create-course' );
		}

		if ( ! $is_backend_builder && ! $is_frontend_builder ) {
			return null;
		}

		$context = version_compare( self::tutor_version(), '3.0.0', '>=' )
			? self::BUILDER_REACT
			: self::BUILDER_LEGACY;

		/**
		 * Filter builder context ที่ตรวจพบ
		 *
		 * @param string $context react|legacy.
		 */
		return apply_filters( 'tlcd_builder_context', $context );
	}

	/**
	 * Course ID ของหน้า Course Builder ปัจจุบัน
	 *
	 * @return int
	 */
	public static function current_course_id() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$course_id = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0;

		if ( ! $course_id ) {
			$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $post_id && self::course_post_type() === get_post_type( $post_id ) ) {
				$course_id = $post_id;
			}
		}

		return $course_id;
	}

	/**
	 * หา Course ID จาก Topic ID
	 *
	 * @param int $topic_id Topic ID.
	 *
	 * @return int
	 */
	public static function course_id_from_topic( $topic_id ) {
		$topic = get_post( $topic_id );

		if ( ! $topic || self::topic_post_type() !== $topic->post_type ) {
			return 0;
		}

		$course_id = (int) $topic->post_parent;

		return self::course_post_type() === get_post_type( $course_id ) ? $course_id : 0;
	}

	/**
	 * หา Course ID จาก Lesson ID
	 *
	 * @param int $lesson_id Lesson ID.
	 *
	 * @return int
	 */
	public static function course_id_from_lesson( $lesson_id ) {
		$lesson = get_post( $lesson_id );

		if ( ! $lesson ) {
			return 0;
		}

		$course_id = self::course_id_from_topic( (int) $lesson->post_parent );

		if ( ! $course_id ) {
			// บทเรียนกำพร้า: Tutor เก็บ course id ไว้ใน meta นี้.
			$course_id = (int) get_post_meta( $lesson_id, '_tutor_course_id_for_lesson', true );
			$course_id = self::course_post_type() === get_post_type( $course_id ) ? $course_id : 0;
		}

		return $course_id;
	}

	/**
	 * หน้าที่ควรแสดง admin notice
	 *
	 * แสดงเฉพาะหน้าที่เกี่ยวข้อง (รายการปลั๊กอิน, หน้า Tutor LMS, Course Builder)
	 * เพื่อให้คำเตือนอยู่ในสายตาโดยไม่รบกวนทุกหน้าใน wp-admin
	 *
	 * @return bool
	 */
	private static function should_render_notice() {
		global $pagenow;

		if ( in_array( $pagenow, array( 'plugins.php', 'update-core.php' ), true ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 0 === strpos( $page, 'tutor' ) || 'create-course' === $page;
	}

	/**
	 * Admin notice เรื่องความเข้ากันได้
	 *
	 * นโยบาย: "เตือนอย่างเดียว ไม่บล็อก" สำหรับเวอร์ชันที่ใหม่กว่าที่ทดสอบ
	 * ส่วนเวอร์ชันที่ต่ำกว่าขั้นต่ำจะไม่โหลดส่วน UI อยู่แล้ว
	 *
	 * @return void
	 */
	public static function render_admin_notice() {
		if ( ! current_user_can( 'activate_plugins' ) || ! self::should_render_notice() ) {
			return;
		}

		$notices = array();

		if ( ! self::is_tutor_active() ) {
			$notices[] = array(
				'type'    => 'warning',
				'message' => __( 'Tutor LMS Curriculum Duplicator requires the Tutor LMS plugin to be activated first.', 'tutor-lms-curriculum-duplicator' ),
			);
		} elseif ( ! self::is_supported() ) {
			$notices[] = array(
				'type'    => 'error',
				'message' => sprintf(
					/* translators: 1: installed version, 2: minimum version */
					__( 'Tutor LMS Curriculum Duplicator supports Tutor LMS %2$s and above, but version %1$s was detected — the duplicate buttons will not be shown.', 'tutor-lms-curriculum-duplicator' ),
					esc_html( self::tutor_version() ),
					esc_html( TLCD_MIN_TUTOR_VERSION )
				),
			);
		} elseif ( self::is_untested_newer() ) {
			$notices[] = array(
				'type'    => 'warning',
				'message' => sprintf(
					/* translators: 1: installed version, 2: tested version */
					__( 'Tutor LMS Curriculum Duplicator has been verified against Tutor LMS %2$s, but version %1$s was detected. The plugin keeps working, but test duplicating lessons and topics on staging first, because a newer version may change the Course Builder structure or meta keys.', 'tutor-lms-curriculum-duplicator' ),
					esc_html( self::tutor_version() ),
					esc_html( TLCD_TESTED_TUTOR_VERSION )
				),
			);
		}

		foreach ( self::runtime_issues() as $issue ) {
			if ( 'tutor_missing' === $issue['code'] ) {
				continue; // แจ้งไปแล้วด้านบน.
			}

			$notices[] = array(
				'type'    => ! empty( $issue['blocking'] ) ? 'error' : 'warning',
				'message' => sprintf(
					/* translators: %s: issue detail */
					__( 'Tutor LMS Curriculum Duplicator: %s', 'tutor-lms-curriculum-duplicator' ),
					$issue['message']
				),
			);
		}

		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%1$s"><p>%2$s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] )
			);
		}
	}
}
