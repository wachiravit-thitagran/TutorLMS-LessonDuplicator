<?php
/**
 * Plugin Name:       Tutor LMS Curriculum Duplicator
 * Plugin URI:        https://github.com/wachiravit/TutorLMS-LessonDuplicator
 * Description:       เพิ่มปุ่ม Duplicate สำหรับ Lesson และ Topic ในหน้า Course Builder ของ Tutor LMS (ฟรี) โดยไม่แก้ไฟล์ Core และไม่ต้องใช้ Tutor LMS Pro
 * Version:           1.1.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  tutor
 * Author:            Wachiravit
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tutor-lms-curriculum-duplicator
 * Domain Path:       /languages
 *
 * @package TLCD
 */

defined( 'ABSPATH' ) || exit;

define( 'TLCD_VERSION', '1.1.2' );
define( 'TLCD_FILE', __FILE__ );
define( 'TLCD_PATH', plugin_dir_path( __FILE__ ) );
define( 'TLCD_URL', plugin_dir_url( __FILE__ ) );
define( 'TLCD_BASENAME', plugin_basename( __FILE__ ) );

/**
 * เวอร์ชัน Tutor LMS ต่ำสุดที่รองรับ
 *
 * React Course Builder เริ่มใช้ใน Tutor LMS 3.0.0 — ต่ำกว่านี้เป็น Course Builder
 * แบบเดิมซึ่งคนละโครงสร้าง UI ทั้งหมด
 */
define( 'TLCD_MIN_TUTOR_VERSION', '3.0.0' );

/**
 * เวอร์ชัน Tutor LMS ล่าสุดที่ตรวจสอบซอร์สโค้ดแล้ว
 *
 * ยืนยันแล้วว่าตรงกับที่ปลั๊กอินนี้คาดหวัง: post types, meta keys,
 * ความสัมพันธ์ post_parent, การจัดลำดับ menu_order, tutor_utils()->can_user_manage()
 * และ data-cy selectors ของ Course Builder
 */
define( 'TLCD_TESTED_TUTOR_VERSION', '4.0.2' );

/**
 * สาย (major.minor) ที่ถือว่าอยู่ในขอบเขตที่ทดสอบแล้ว
 *
 * patch release ในสายเดียวกัน (เช่น 4.0.3, 4.0.9) แทบไม่เปลี่ยนโครงสร้างข้อมูล
 * จึงไม่ขึ้นคำเตือน แต่เมื่อขึ้น 4.1 หรือ 5.x จะแจ้งเตือนผู้ดูแลระบบ
 */
define( 'TLCD_TESTED_TUTOR_BRANCH', '4.0' );

/**
 * Autoloader.
 *
 * TLCD\Services\Lesson_Duplicator  => includes/services/class-lesson-duplicator.php
 * TLCD\Integrations\Adapter_Interface => includes/integrations/interface-adapter.php
 *
 * @param string $class Fully qualified class name.
 *
 * @return void
 */
spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'TLCD\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( 'TLCD\\' ) );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );

		$dir = TLCD_PATH . 'includes/';
		if ( $parts ) {
			$dir .= strtolower( str_replace( '_', '-', implode( '/', $parts ) ) ) . '/';
		}

		$is_interface = ( '_Interface' === substr( $name, -10 ) );
		if ( $is_interface ) {
			$name   = substr( $name, 0, -10 );
			$prefix = 'interface-';
		} else {
			$prefix = 'class-';
		}

		$file = $dir . $prefix . strtolower( str_replace( '_', '-', $name ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Boot the plugin.
 *
 * @return \TLCD\Plugin
 */
function tlcd() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new \TLCD\Plugin();
	}

	return $instance;
}

add_action( 'plugins_loaded', array( tlcd(), 'boot' ), 20 );

register_activation_hook(
	__FILE__,
	function () {
		require_once TLCD_PATH . 'includes/class-compatibility.php';

		if ( ! \TLCD\Compatibility::is_php_supported() ) {
			deactivate_plugins( TLCD_BASENAME );
			wp_die(
				esc_html__( 'Tutor LMS Curriculum Duplicator requires PHP 7.4 or higher.', 'tutor-lms-curriculum-duplicator' ),
				esc_html__( 'Could not activate the plugin', 'tutor-lms-curriculum-duplicator' ),
				array( 'back_link' => true )
			);
		}

		// Activation ยังทำได้แม้ Tutor LMS ยังไม่พร้อม แต่จะขึ้น admin notice แทน.
		update_option( 'tlcd_activated_at', time(), false );
	}
);
