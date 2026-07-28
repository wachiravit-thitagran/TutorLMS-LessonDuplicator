<?php
/**
 * Course Builder integration
 *
 * เลือก adapter ตามเวอร์ชัน Tutor LMS แล้วโหลด asset เฉพาะหน้า Course Builder เท่านั้น
 *
 * @package TLCD
 */

namespace TLCD\Integrations;

use TLCD\Compatibility;
use TLCD\Permission;

defined( 'ABSPATH' ) || exit;

/**
 * Class Course_Builder
 */
class Course_Builder {

	/**
	 * Adapter ที่ใช้งานอยู่
	 *
	 * @var Adapter_Interface|null
	 */
	private $adapter = null;

	/**
	 * ลงทะเบียน hooks
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ), 100 );
	}

	/**
	 * โหลด asset ถ้าอยู่ในหน้า Course Builder และผู้ใช้มีสิทธิ์
	 *
	 * @return void
	 */
	public function maybe_enqueue() {
		if ( ! Compatibility::is_supported() ) {
			return;
		}

		/**
		 * Filter การแสดงปุ่มเมื่อมี Tutor LMS Pro อยู่แล้ว
		 *
		 * ค่าเริ่มต้นคือ "ไม่แสดง" เพราะ Pro มีปุ่ม Duplicate ของตัวเองอยู่แล้ว
		 * การแสดงทั้งสองปุ่มพร้อมกันจะทำให้ผู้ใช้สับสน
		 *
		 * @param bool $skip ข้ามการโหลด asset หรือไม่.
		 */
		if ( Compatibility::is_tutor_pro_active() && apply_filters( 'tlcd_skip_when_pro_active', true ) ) {
			return;
		}

		$context = Compatibility::current_builder_context();

		if ( ! $context ) {
			return;
		}

		$course_id = Compatibility::current_course_id();

		if ( ! Permission::can_use_builder_ui( $course_id ) ) {
			return;
		}

		$adapter = $this->resolve_adapter( $context );

		if ( ! $adapter ) {
			return;
		}

		$adapter->register_assets( $course_id );
	}

	/**
	 * เลือก adapter ที่เหมาะกับ context
	 *
	 * @param string $context react|legacy.
	 *
	 * @return Adapter_Interface|null
	 */
	private function resolve_adapter( $context ) {
		if ( $this->adapter ) {
			return $this->adapter;
		}

		$adapters = array( new React_Builder_Adapter() );

		/**
		 * Filter รายการ adapter ที่มี
		 *
		 * @param Adapter_Interface[] $adapters Adapters.
		 * @param string              $context  react|legacy.
		 */
		$adapters = apply_filters( 'tlcd_course_builder_adapters', $adapters, $context );

		foreach ( $adapters as $adapter ) {
			if ( $adapter instanceof Adapter_Interface && $adapter->supports( $context ) ) {
				$this->adapter = $adapter;

				return $adapter;
			}
		}

		return null;
	}
}
