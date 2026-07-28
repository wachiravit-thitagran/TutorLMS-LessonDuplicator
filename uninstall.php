<?php
/**
 * Uninstall handler
 *
 * ปลั๊กอินนี้ไม่สร้างตารางหรือ custom post type ของตัวเอง
 * มีเพียง option สถานะและ option สำหรับกันการกดซ้ำเท่านั้น
 *
 * สำเนาที่ผู้ใช้สร้างไว้เป็นโพสต์ปกติของ Tutor LMS จึงไม่ถูกแตะต้อง
 *
 * @package TLCD
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * ลบข้อมูลของปลั๊กอินในเว็บไซต์หนึ่งแห่ง
 *
 * @return void
 */
function tlcd_uninstall_site() {
	global $wpdb;

	delete_option( 'tlcd_activated_at' );

	// ลบ option lock ปัจจุบันและ transient lock จากรุ่นก่อน (ถ้ายังค้างอยู่).
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE %s
			    OR option_name LIKE %s
			    OR option_name LIKE %s",
			$wpdb->esc_like( '_tlcd_lock_' ) . '%',
			$wpdb->esc_like( '_transient_tlcd_lock_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_tlcd_lock_' ) . '%'
		)
	);
	// phpcs:enable
}

if ( is_multisite() ) {
	$tlcd_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $tlcd_site_ids as $tlcd_site_id ) {
		switch_to_blog( $tlcd_site_id );
		tlcd_uninstall_site();
		restore_current_blog();
	}

	unset( $tlcd_site_ids, $tlcd_site_id );
} else {
	tlcd_uninstall_site();
}
