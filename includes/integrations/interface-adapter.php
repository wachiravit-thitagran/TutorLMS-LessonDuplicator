<?php
/**
 * Course Builder adapter interface
 *
 * เมื่อ Tutor LMS เปลี่ยน UI ให้เขียน adapter ตัวใหม่แทนการแก้ Duplicate Service
 *
 * @package TLCD
 */

namespace TLCD\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Interface Adapter_Interface
 */
interface Adapter_Interface {

	/**
	 * ชื่อ adapter (ใช้ใน debug)
	 *
	 * @return string
	 */
	public function get_name();

	/**
	 * adapter นี้รองรับ context ปัจจุบันหรือไม่
	 *
	 * @param string $context react|legacy.
	 *
	 * @return bool
	 */
	public function supports( $context );

	/**
	 * โหลด asset ของ adapter
	 *
	 * @param int $course_id Course ID ปัจจุบัน.
	 *
	 * @return void
	 */
	public function register_assets( $course_id );
}
