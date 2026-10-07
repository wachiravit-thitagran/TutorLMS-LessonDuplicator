<?php
/**
 * MCP / WordPress Abilities integration.
 *
 * @package TLCD
 */

namespace TLCD;

defined( 'ABSPATH' ) || exit;

/**
 * Registers curriculum duplication abilities for MCP Adapter.
 */
final class MCP {

	/**
	 * Register hooks when the WordPress Abilities API is available.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Register the duplicator ability category.
	 *
	 * @return void
	 */
	public static function register_category() {
		wp_register_ability_category(
			'tutorlms-duplicator',
			array(
				'label'       => 'Tutor LMS Curriculum Duplicator',
				'description' => 'Curriculum inspection and duplication abilities.',
			)
		);
	}

	/**
	 * Register MCP-visible abilities.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		$definitions = array(
			'get-curriculum'    => array(
				'label'       => 'Get Course Curriculum',
				'description' => 'Retrieves the ordered curriculum of a course, including topics and supported child content.',
				'method'      => 'GET',
				'route'       => '/tlcd/v1/courses/{course_id}/curriculum',
				'readonly'    => true,
				'properties'  => array(
					'course_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'    => array( 'course_id' ),
			),
			'duplicate-content'  => array(
				'label'       => 'Duplicate Course Content',
				'description' => 'Creates a copy of a supported curriculum item and optionally places the copy in a specified topic.',
				'method'      => 'POST',
				'route'       => '/tlcd/v1/contents/{content_id}/duplicate',
				'readonly'    => false,
				'properties'  => array(
					'content_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'topic_id'   => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'    => array( 'content_id' ),
			),
			'duplicate-topic'    => array(
				'label'       => 'Duplicate Course Topic',
				'description' => 'Creates a copy of a course topic and its supported curriculum content, optionally within a specified course.',
				'method'      => 'POST',
				'route'       => '/tlcd/v1/topics/{topic_id}/duplicate',
				'readonly'    => false,
				'properties'  => array(
					'topic_id'  => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'course_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'    => array( 'topic_id' ),
			),
		);

		foreach ( $definitions as $name => $definition ) {
			self::ability( $name, $definition );
		}
	}

	/**
	 * Register one REST-backed ability.
	 *
	 * @param string              $name       Ability suffix.
	 * @param array<string,mixed> $definition Ability definition.
	 * @return void
	 */
	private static function ability( $name, array $definition ) {
		$is_readonly = (bool) $definition['readonly'];

		wp_register_ability(
			'tutorlms-duplicator/' . $name,
			array(
				'label'               => (string) $definition['label'],
				'description'         => (string) $definition['description'],
				'category'            => 'tutorlms-duplicator',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => (array) $definition['properties'],
					'required'   => (array) $definition['required'],
				),
				'execute_callback'    => static function ( array $input ) use ( $definition ) {
					return self::dispatch(
						(string) $definition['method'],
						(string) $definition['route'],
						$input
					);
				},
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
				'meta'                => self::meta( $is_readonly ),
			)
		);
	}

	/**
	 * Dispatch through the plugin REST API so its permission callbacks remain authoritative.
	 *
	 * @param string              $method REST method.
	 * @param string              $route  REST route.
	 * @param array<string,mixed> $input  Ability input.
	 * @return mixed
	 */
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

	/**
	 * Shared MCP metadata.
	 *
	 * @param bool $is_readonly Whether the operation is read-only.
	 * @return array<string,mixed>
	 */
	private static function meta( $is_readonly ) {
		return array(
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations' => array(
				'readonly'      => (bool) $is_readonly,
				'destructive'   => false,
				'idempotent'    => (bool) $is_readonly,
				'openWorldHint' => ! $is_readonly,
			),
		);
	}
}
