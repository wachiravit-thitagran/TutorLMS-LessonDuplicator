<?php
/**
 * Plugin bootstrap
 *
 * @package TLCD
 */

namespace TLCD;

use TLCD\Api\Content_Controller;
use TLCD\Api\Topic_Controller;
use TLCD\Integrations\Course_Builder;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 */
class Plugin {

	/**
	 * เริ่มทำงาน
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// แสดงคำเตือนเสมอ ทั้งกรณีเวอร์ชันต่ำเกินไปและกรณีใหม่กว่าที่ตรวจสอบไว้.
		add_action( 'admin_notices', array( Compatibility::class, 'render_admin_notice' ) );

		if ( ! Compatibility::is_supported() ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		( new Course_Builder() )->register();

		add_filter( 'plugin_action_links_' . TLCD_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * โหลดไฟล์แปลภาษา
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'tutor-lms-curriculum-duplicator',
			false,
			dirname( TLCD_BASENAME ) . '/languages'
		);
	}

	/**
	 * ลงทะเบียน REST routes
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		( new Content_Controller() )->register_routes();
		( new Topic_Controller() )->register_routes();
	}

	/**
	 * แสดงสถานะบนหน้ารายการปลั๊กอิน
	 *
	 * @param string[] $links Action links.
	 *
	 * @return string[]
	 */
	public function plugin_action_links( $links ) {
		if ( Compatibility::is_tutor_pro_active() ) {
			$status = __( 'Tutor LMS Pro detected (its built-in Duplicate feature is already active)', 'tutor-lms-curriculum-duplicator' );
			$color  = '#666';
		} elseif ( Compatibility::is_untested_newer() ) {
			$status = sprintf(
				/* translators: 1: installed version, 2: tested version */
				__( 'Tutor LMS %1$s — untested (verified up to %2$s)', 'tutor-lms-curriculum-duplicator' ),
				Compatibility::tutor_version(),
				Compatibility::tested_version()
			);
			$color = '#b4770a';
		} else {
			$status = sprintf(
				/* translators: %s: Tutor LMS version */
				__( 'Running with Tutor LMS %s', 'tutor-lms-curriculum-duplicator' ),
				Compatibility::tutor_version()
			);
			$color = '#666';
		}

		array_unshift(
			$links,
			'<span style="color:' . esc_attr( $color ) . '">' . esc_html( $status ) . '</span>'
		);

		return $links;
	}
}
