<?php
/**
 * Base REST controller
 *
 * @package TLCD
 */

namespace TLCD\Api;

use TLCD\Compatibility;
use TLCD\Logger;
use WP_Error;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Class Rest_Controller
 */
abstract class Rest_Controller {

	const NAMESPACE_V1 = 'tlcd/v1';

	/**
	 * ระยะเวลาล็อกกันกดซ้ำ (วินาที)
	 */
	const LOCK_TTL = 300;

	/**
	 * ลงทะเบียน route
	 *
	 * @return void
	 */
	abstract public function register_routes();

	/**
	 * ต้องล็อกอินก่อนเสมอ
	 *
	 * @return true|WP_Error
	 */
	protected function require_login() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'tlcd_not_logged_in',
				__( 'Please log in.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * ตรวจว่าโครงสร้างของ Tutor LMS ยังตรงกับที่ปลั๊กอินคาดหวัง
	 *
	 * ปลั๊กอินไม่บล็อกเวอร์ชันที่ใหม่กว่าที่ทดสอบไว้ (แค่เตือน) แต่ถ้าสมมติฐานหลัก
	 * เช่น post type หายไปจริง ๆ จะปฏิเสธคำขอทันที ดีกว่าปล่อยให้คัดลอกผิดตำแหน่ง
	 *
	 * @return true|WP_Error
	 */
	protected function assert_runtime() {
		$issues = Compatibility::blocking_runtime_issues();

		if ( ! $issues ) {
			return true;
		}

		$details = wp_list_pluck( $issues, 'message' );

		return new WP_Error(
			'tlcd_incompatible_runtime',
			sprintf(
				/* translators: 1: installed Tutor LMS version, 2: issue list */
				__( 'The structure of Tutor LMS %1$s does not match what this plugin supports, so duplication was stopped to protect your data (%2$s).', 'tutor-lms-curriculum-duplicator' ),
				Compatibility::tutor_version(),
				implode( ', ', $details )
			),
			array( 'status' => 409 )
		);
	}

	/**
	 * สร้าง lock กันการส่งคำขอซ้ำ
	 *
	 * @param string $key คีย์เฉพาะของ operation.
	 *
	 * @return string|WP_Error Owner token เมื่อ acquire สำเร็จ.
	 */
	protected function acquire_lock( $key ) {
		global $wpdb;

		$option_name = $this->lock_option_name( $key );
		$token       = wp_generate_uuid4();
		$now         = time();
		$ttl         = max( 1, (int) apply_filters( 'tlcd_lock_ttl', self::LOCK_TTL, $key ) );
		$value       = ( $now + $ttl ) . '|' . $token;

		/*
		 * INSERT และ stale-lock takeover อยู่ใน statement เดียว จึงไม่มีช่องว่างแบบ
		 * get-then-set ให้คำขอสองชุด acquire พร้อมกันได้
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$acquired = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} ( option_name, option_value, autoload )
				 VALUES ( %s, %s, 'no' )
				 ON DUPLICATE KEY UPDATE
				 option_value = IF(
					CAST( SUBSTRING_INDEX( option_value, '|', 1 ) AS UNSIGNED ) < %d,
					%s,
					option_value
				 )",
				$option_name,
				$value,
				$now,
				$value
			)
		);
		// phpcs:enable

		if ( false === $acquired ) {
			Logger::error( 'lock_acquire', $wpdb->last_error, array( 'key' => $key ) );

			return new WP_Error(
				'tlcd_lock_failed',
				__( 'Could not start duplication safely. Please try again.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 500 )
			);
		}

		// INSERT = 1 affected row, stale takeover = 2, active lock = 0.
		if ( 0 === $acquired ) {
			return new WP_Error(
				'tlcd_duplicate_in_progress',
				__( 'This item is already being duplicated. Please wait a moment.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 409 )
			);
		}

		wp_cache_delete( $option_name, 'options' );

		return $token;
	}

	/**
	 * ปลด lock
	 *
	 * @param string $key   คีย์เฉพาะของ operation.
	 * @param string $token Owner token ที่ได้จาก acquire_lock().
	 *
	 * @return void
	 */
	protected function release_lock( $key, $token ) {
		global $wpdb;

		$option_name = $this->lock_option_name( $key );
		$value_like  = '%|' . $wpdb->esc_like( (string) $token );

		// ลบได้เฉพาะ request เจ้าของ lock เพื่อกัน request เก่าปลด lock ตัวใหม่.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$released = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
				$option_name,
				$value_like
			)
		);

		wp_cache_delete( $option_name, 'options' );

		if ( false === $released ) {
			Logger::error( 'lock_release', $wpdb->last_error, array( 'key' => $key ) );
		}
	}

	/**
	 * ชื่อ option สำหรับ lock ระดับรายการ (ใช้ร่วมกันทุกผู้ใช้)
	 *
	 * @param string $key Operation key.
	 *
	 * @return string
	 */
	private function lock_option_name( $key ) {
		return '_tlcd_lock_' . md5( (string) $key );
	}

	/**
	 * รัน callable ภายใต้ lock และปลด lock เสมอ แม้เกิด exception
	 *
	 * ถ้าไม่ใช้ finally แล้วมี PHP error เกิดขึ้นกลางทาง lock จะค้างจนหมดอายุ
	 * ทำให้ผู้ใช้กด Duplicate ซ้ำไม่ได้จนกว่า stale lock จะหมดอายุ
	 *
	 * @param string   $key      คีย์เฉพาะของ operation.
	 * @param callable $callback งานที่ต้องทำ.
	 *
	 * @return mixed|WP_Error
	 */
	protected function with_lock( $key, callable $callback ) {
		$token = $this->acquire_lock( $key );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		try {
			return $callback();
		} finally {
			$this->release_lock( $key, $token );
		}
	}

	/**
	 * ตอบกลับแบบสำเร็จ
	 *
	 * @param array  $data    ข้อมูล.
	 * @param string $message ข้อความ.
	 * @param int    $status  HTTP status.
	 *
	 * @return WP_REST_Response
	 */
	protected function success( array $data, $message = '', $status = 200 ) {
		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => $message,
				'data'    => $data,
			),
			$status
		);
	}
}
