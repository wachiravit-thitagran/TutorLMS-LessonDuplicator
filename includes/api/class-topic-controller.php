<?php
/**
 * REST controller: Duplicate Topic
 *
 * @package TLCD
 */

namespace TLCD\Api;

use TLCD\Compatibility;
use TLCD\Permission;
use TLCD\Services\Topic_Duplicator;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Class Topic_Controller
 */
class Topic_Controller extends Rest_Controller {

	/**
	 * ลงทะเบียน route
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/topics/(?P<id>\d+)/duplicate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'duplicate' ),
				'permission_callback' => array( $this, 'can_duplicate' ),
				'args'                => array(
					'id'        => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ) {
							return absint( $value ) > 0;
						},
					),
					'course_id' => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * ตรวจสิทธิ์
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return true|WP_Error
	 */
	public function can_duplicate( WP_REST_Request $request ) {
		$login = $this->require_login();

		if ( is_wp_error( $login ) ) {
			return $login;
		}

		if ( ! Permission::can_manage_topic( absint( $request['id'] ) ) ) {
			return new WP_Error(
				'tlcd_forbidden',
				__( 'You do not have permission to edit this topic.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * POST /topics/{id}/duplicate
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	public function duplicate( WP_REST_Request $request ) {
		$runtime = $this->assert_runtime();

		if ( is_wp_error( $runtime ) ) {
			return $runtime;
		}

		$topic_id = absint( $request['id'] );

		if ( Compatibility::topic_post_type() !== get_post_type( $topic_id ) ) {
			return new WP_Error(
				'tlcd_topic_not_found',
				__( 'The requested topic was not found.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 404 )
			);
		}

		$course_id = Compatibility::course_id_from_topic( $topic_id );

		if ( ! $course_id ) {
			return new WP_Error(
				'tlcd_course_not_found',
				__( 'This topic does not belong to any course.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 400 )
			);
		}

		$requested_course = absint( $request->get_param( 'course_id' ) );

		if ( $requested_course && $requested_course !== $course_id ) {
			return new WP_Error(
				'tlcd_course_mismatch',
				__( 'This topic does not belong to the specified course.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 400 )
			);
		}

		if ( ! Permission::can_manage_course( $course_id ) ) {
			return new WP_Error(
				'tlcd_forbidden',
				__( 'You do not have permission to edit this course.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 403 )
			);
		}

		$result = $this->with_lock(
			'topic:' . $topic_id,
			static function () use ( $topic_id ) {
				$duplicator = new Topic_Duplicator();

				return $duplicator->duplicate( $topic_id );
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$message = __( 'Topic duplicated successfully.', 'tutor-lms-curriculum-duplicator' );

		if ( ! empty( $result['skipped'] ) ) {
			$message = sprintf(
				/* translators: %d: number of skipped items */
				__( 'Topic duplicated successfully (%d unsupported item(s) skipped).', 'tutor-lms-curriculum-duplicator' ),
				count( $result['skipped'] )
			);
		}

		return $this->success(
			array(
				'source_id'    => $topic_id,
				'duplicate_id' => $result['topic_id'],
				'course_id'    => $result['course_id'],
				'title'        => $result['title'],
				'menu_order'   => $result['menu_order'],
				'contents'     => $result['contents'],
				'lessons'      => $result['lessons'],
				'skipped'      => $result['skipped'],
			),
			$message,
			201
		);
	}
}
