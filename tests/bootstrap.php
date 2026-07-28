<?php
/**
 * PHPUnit bootstrap
 *
 * รองรับสองโหมด
 *   1. มี WP_TESTS_DIR / WP_DEVELOP_DIR  → รันเป็น integration test บน WordPress จริง
 *   2. ไม่มี                             → หยุดพร้อมข้อความบอกวิธีติดตั้ง
 *
 * @package TLCD\Tests
 */

$tlcd_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $tlcd_tests_dir ) {
	$tlcd_develop_dir = getenv( 'WP_DEVELOP_DIR' );

	if ( $tlcd_develop_dir ) {
		$tlcd_tests_dir = rtrim( $tlcd_develop_dir, '/\\' ) . '/tests/phpunit';
	}
}

if ( ! $tlcd_tests_dir ) {
	$tlcd_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $tlcd_tests_dir . '/includes/functions.php' ) ) {
	echo 'ไม่พบชุดทดสอบของ WordPress ที่ ' . $tlcd_tests_dir . PHP_EOL; // phpcs:ignore
	echo 'ติดตั้งด้วย: bash bin/install-wp-tests.sh wordpress_test root "" 127.0.0.1 latest' . PHP_EOL; // phpcs:ignore
	exit( 1 );
}

require_once $tlcd_tests_dir . '/includes/functions.php';

/**
 * โหลดปลั๊กอินก่อน WordPress ยิง init
 *
 * @return void
 */
function tlcd_manually_load_plugin() {
	$plugin_dir = dirname( __DIR__ );

	// ถ้ามี Tutor LMS ให้โหลดก่อน เพื่อทดสอบกับของจริง.
	$tutor = getenv( 'TUTOR_PLUGIN_FILE' );

	if ( $tutor && file_exists( $tutor ) ) {
		require_once $tutor;
	}

	require_once $plugin_dir . '/tutor-lms-curriculum-duplicator.php';
}

tests_add_filter( 'muplugins_loaded', 'tlcd_manually_load_plugin' );

require $tlcd_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/class-tlcd-seeder.php';
require_once __DIR__ . '/class-tlcd-testcase.php';
