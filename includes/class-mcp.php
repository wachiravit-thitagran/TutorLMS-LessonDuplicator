<?php
/**
 * MCP / WordPress Abilities integration.
 *
 * @package TLCD
 */

namespace TLCD;

defined( 'ABSPATH' ) || exit;

final class MCP {
	public static function register() {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	public static function register_category() {
		wp_register_ability_category(
			'tutorlms-duplicator',
			array(
				'label'       => 'Tutor LMS Curriculum Duplicator',
				'description' => 'Curriculum inspection and duplication abilities.',
			)
		);
	}

	public static function register_abilities() {
		self::ability( 'get-curriculum', 'Get Course Curriculum', 'GET', '/tlcd/v1/courses/{course_id}/curriculum', array(
			'course_id' => array( 'type' => 'integer', 'minimum' => 1 ),
		), array( 'course_id' ), true );

		self::ability( 'duplicate-content', 'Duplicate Course Content', 'POST', '/tlcd/v1/contents/{content_id}/duplicate', array(
			'content_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'topic_id'   => array( 'type' => 'integer', 'minimum' => 1 ),
		), array( 'content_id' ), false );

		self::ability( 'duplicate-topic', 'Duplicate Course Topic', 'POST', '/tlcd/v1/topics/{topic_id}/duplicate', array(
			'topic_id'  => array( 'type' => 'integer', 'minimum' => 1 ),
			'course_id' => array( 'type' => 'integer', 'minimum' => 1 ),
		), array( 'topic_id' ), false );
	}

	private static function ability( $name, $label, $method, $route, array $properties, array $required, $readonly ) {
		wp_register_ability(
			'tutorlms-duplicator/' . $name,
			array(
				'label'       => $label,
				'description' => $label . ' using the duplicator REST contract and permission checks.',
				'category'    => 'tutorlms-duplicator',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => $properties,
					'required'   => $required,
				),
				'execute_callback'    => static function ( array $input ) use ( $method, $route ) {
					return self::dispatch( $method, $route, $input );
				},
				'permission_callback' => static function () { return is_user_logged_in(); },
				'meta'                => self::meta( $readonly ),
			)
		);
	}

	private static function dispatch( $method, $route, array $input ) {
		foreach ( array( 'course_id', 'content_id', 'topic_id' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$route = str_replace( '{' . $key . '}', (string) absint( $input[ $key ] ), $route );
			}
		}

		$request = new \WP_REST_Request( $method, $route );
		foreach ( $input as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = rest_do_request( $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response instanceof \WP_REST_Response ? $response->get_data() : $response;
	}

	private static function meta( $readonly ) {
		return array(
			'mcp' => array( 'public' => true, 'type' => 'tool' ),
			'annotations' => array(
				'readonly' => (bool) $readonly,
				'destructive' => false,
				'idempotent' => (bool) $readonly,
				'openWorldHint' => ! $readonly,
			),
		);
	}
}
