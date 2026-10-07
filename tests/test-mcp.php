<?php
/**
 * MCP abilities integration tests.
 *
 * @package TLCD\Tests
 */
class TLCD_MCP_Test extends WP_UnitTestCase {

	public function test_registers_mcp_abilities_when_abilities_api_is_available() {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API requires WordPress 6.9+.' );
		}

		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );

		$this->assertNotNull( wp_get_ability( 'tutorlms-duplicator/get-curriculum' ) );
		$this->assertNotNull( wp_get_ability( 'tutorlms-duplicator/duplicate-content' ) );
		$this->assertNotNull( wp_get_ability( 'tutorlms-duplicator/duplicate-topic' ) );
	}
}
