<?php
/**
 * ชุดทดสอบ atomic REST lock
 *
 * @package TLCD\Tests
 */

use TLCD\Api\Rest_Controller;

/**
 * Controller สำหรับเปิด protected lock methods ให้ชุดทดสอบเรียกได้
 */
class TLCD_Test_Rest_Controller extends Rest_Controller {

	/**
	 * Route registration ไม่จำเป็นสำหรับชุดทดสอบนี้
	 *
	 * @return void
	 */
	public function register_routes() {}

	/**
	 * Acquire lock.
	 *
	 * @param string $key Operation key.
	 *
	 * @return string|WP_Error
	 */
	public function acquire( $key ) {
		return $this->acquire_lock( $key );
	}

	/**
	 * Release lock.
	 *
	 * @param string $key   Operation key.
	 * @param string $token Owner token.
	 *
	 * @return void
	 */
	public function release( $key, $token ) {
		$this->release_lock( $key, $token );
	}
}

/**
 * Class Test_REST_Lock
 */
class Test_REST_Lock extends TLCD_TestCase {

	/**
	 * ผู้ใช้คนอื่นต้อง acquire รายการเดียวกันไม่ได้
	 *
	 * @return void
	 */
	public function test_lock_is_shared_across_users() {
		$controller  = new TLCD_Test_Rest_Controller();
		$key         = 'content:123';
		$first_user  = self::factory()->user->create();
		$second_user = self::factory()->user->create();

		wp_set_current_user( $first_user );
		$token = $controller->acquire( $key );

		wp_set_current_user( $second_user );
		$second = $controller->acquire( $key );

		$this->assertIsString( $token );
		$this->assertWPError( $second );
		$this->assertSame( 'tlcd_duplicate_in_progress', $second->get_error_code() );

		$controller->release( $key, $token );
	}

	/**
	 * Request ที่ไม่ได้เป็นเจ้าของต้องปลด lock ไม่ได้
	 *
	 * @return void
	 */
	public function test_only_owner_token_can_release_lock() {
		$controller = new TLCD_Test_Rest_Controller();
		$key        = 'topic:456';
		$token      = $controller->acquire( $key );

		$controller->release( $key, 'wrong-token' );
		$blocked = $controller->acquire( $key );

		$this->assertWPError( $blocked );

		$controller->release( $key, $token );
		$this->assertIsString( $controller->acquire( $key ) );
	}
}
