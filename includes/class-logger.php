<?php
/**
 * Internal error logging.
 *
 * @package TLCD
 */

namespace TLCD;

defined( 'ABSPATH' ) || exit;

/**
 * Class Logger
 */
class Logger {

	/**
	 * บันทึกรายละเอียดภายในโดยไม่ส่งข้อความอ่อนไหวกลับไปยัง REST client
	 *
	 * @param string            $context บริบทสั้น ๆ ของเหตุการณ์.
	 * @param \Throwable|string $error   Exception หรือรายละเอียดภายใน.
	 * @param array             $data    ข้อมูลประกอบที่ไม่มี secret.
	 *
	 * @return void
	 */
	public static function error( $context, $error, array $data = array() ) {
		$context = sanitize_key( $context );

		if ( $error instanceof \Throwable ) {
			$detail = sprintf(
				'%s: %s in %s:%d',
				get_class( $error ),
				$error->getMessage(),
				$error->getFile(),
				$error->getLine()
			);
		} else {
			$detail = (string) $error;
		}

		/**
		 * Action สำหรับส่ง error ภายในไปยังระบบ logging ของเว็บไซต์
		 *
		 * @param string $context บริบท.
		 * @param string $detail  รายละเอียดภายใน.
		 * @param array  $data    ข้อมูลประกอบ.
		 */
		try {
			do_action( 'tlcd/log/error', $context, $detail, $data );
		} catch ( \Throwable $logging_error ) {
			// Logging extension ต้องไม่ทำให้ operation หลักล้มเหลวซ้ำ.
			unset( $logging_error );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$payload = $data ? ' ' . wp_json_encode( $data ) : '';

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[TLCD][' . $context . '] ' . $detail . $payload );
		}
	}
}
