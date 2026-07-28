<?php
/**
 * REST controller: ทำสำเนาเนื้อหาใน Topic + อ่านโครงสร้าง Curriculum
 *
 * รองรับทุกชนิดที่ Duplicator_Factory รู้จัก (บทเรียน, แบบทดสอบ, งานที่มอบหมาย)
 * โดยไม่เชื่อ topic_id / course_id ที่ส่งมาจาก JavaScript — ยึด post_parent จริงเสมอ
 *
 * @package TLCD
 */

namespace TLCD\Api;

use TLCD\Compatibility;
use TLCD\Curriculum_Order;
use TLCD\Permission;
use TLCD\Services\Duplicator_Factory;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Class Content_Controller
 */
class Content_Controller extends Rest_Controller {

	/**
	 * ลงทะเบียน route
	 *
	 * @return void
	 */
	public function register_routes() {
		$duplicate_args = array(
			'id'       => array(
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => static function ( $value ) {
					return absint( $value ) > 0;
				},
			),
			'topic_id' => array(
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
		);

		// Endpoint ทั่วไป — ใช้ได้กับเนื้อหาทุกชนิดที่รองรับ.
		register_rest_route(
			self::NAMESPACE_V1,
			'/contents/(?P<id>\d+)/duplicate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'duplicate' ),
				'permission_callback' => array( $this, 'can_duplicate' ),
				'args'                => $duplicate_args,
			)
		);

		/*
		 * Alias เดิมของเวอร์ชัน 1.0 — คงไว้เพื่อไม่ให้ integration ที่มีอยู่พัง
		 * พฤติกรรมเหมือนกันทุกอย่าง แต่จำกัดเฉพาะบทเรียน
		 */
		register_rest_route(
			self::NAMESPACE_V1,
			'/lessons/(?P<id>\d+)/duplicate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'duplicate_lesson' ),
				'permission_callback' => array( $this, 'can_duplicate' ),
				'args'                => $duplicate_args,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/courses/(?P<id>\d+)/curriculum',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'curriculum' ),
				'permission_callback' => array( $this, 'can_read_curriculum' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * ตรวจสิทธิ์การทำสำเนาเนื้อหา
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

		$content_id = absint( $request['id'] );

		if ( ! Permission::can_manage_content( $content_id ) ) {
			return new WP_Error(
				'tlcd_forbidden',
				__( 'You do not have permission to edit this item.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * ตรวจสิทธิ์การอ่าน curriculum
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return true|WP_Error
	 */
	public function can_read_curriculum( WP_REST_Request $request ) {
		$login = $this->require_login();

		if ( is_wp_error( $login ) ) {
			return $login;
		}

		if ( ! Permission::can_manage_course( absint( $request['id'] ) ) ) {
			return new WP_Error(
				'tlcd_forbidden',
				__( 'You do not have permission to access this course.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * POST /lessons/{id}/duplicate — alias ที่จำกัดเฉพาะบทเรียน
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	public function duplicate_lesson( WP_REST_Request $request ) {
		return $this->duplicate( $request, Compatibility::lesson_post_type() );
	}

	/**
	 * POST /contents/{id}/duplicate
	 *
	 * @param WP_REST_Request $request       Request.
	 * @param string          $expected_type จำกัด post type (ว่าง = ชนิดใดก็ได้ที่รองรับ).
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	public function duplicate( WP_REST_Request $request, $expected_type = '' ) {
		$runtime = $this->assert_runtime();

		if ( is_wp_error( $runtime ) ) {
			return $runtime;
		}

		$content_id = absint( $request['id'] );
		$content    = get_post( $content_id );

		if ( ! $content ) {
			return new WP_Error(
				'tlcd_content_not_found',
				__( 'The requested item was not found.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 404 )
			);
		}

		if ( $expected_type && $expected_type !== $content->post_type ) {
			return new WP_Error(
				'tlcd_lesson_not_found',
				__( 'The requested lesson was not found.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 404 )
			);
		}

		$duplicator = Duplicator_Factory::for_post_type( $content->post_type );

		if ( ! $duplicator ) {
			return new WP_Error(
				'tlcd_unsupported_type',
				sprintf(
					/* translators: %s: post type */
					__( 'Duplicating %s content is not supported yet.', 'tutor-lms-curriculum-duplicator' ),
					$content->post_type
				),
				array( 'status' => 400 )
			);
		}

		// ไม่เชื่อ topic_id จาก JavaScript — ยึด post_parent จริงเป็นหลัก.
		$topic_id = (int) $content->post_parent;

		$requested_topic = absint( $request->get_param( 'topic_id' ) );

		if ( $requested_topic && $requested_topic !== $topic_id ) {
			return new WP_Error(
				'tlcd_topic_mismatch',
				__( 'This item does not belong to the specified topic.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $topic_id || Compatibility::topic_post_type() !== get_post_type( $topic_id ) ) {
			return new WP_Error(
				'tlcd_orphan_content',
				__( 'This item is not inside any topic, so it cannot be duplicated yet.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 400 )
			);
		}

		if ( ! Permission::can_manage_topic( $topic_id ) ) {
			return new WP_Error(
				'tlcd_forbidden',
				__( 'You do not have permission to edit this topic.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 403 )
			);
		}

		$course_id = Compatibility::course_id_from_topic( $topic_id );

		if ( ! $course_id || ! Permission::can_manage_course( $course_id ) ) {
			return new WP_Error(
				'tlcd_forbidden',
				__( 'You do not have permission to edit this course.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 403 )
			);
		}

		$new_id = $this->with_lock(
			'content:' . $content_id,
			static function () use ( $duplicator, $content_id, $topic_id ) {
				return $duplicator->duplicate( $content_id, $topic_id );
			}
		);

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		return $this->success(
			array(
				'source_id'    => $content_id,
				'duplicate_id' => (int) $new_id,
				'post_type'    => $content->post_type,
				'topic_id'     => $topic_id,
				'course_id'    => $course_id,
				'title'        => get_the_title( $new_id ),
				'menu_order'   => (int) get_post_field( 'menu_order', $new_id ),
			),
			$this->success_message( $content->post_type ),
			201
		);
	}

	/**
	 * ข้อความสำเร็จตามชนิดเนื้อหา
	 *
	 * @param string $post_type Post type.
	 *
	 * @return string
	 */
	private function success_message( $post_type ) {
		if ( Compatibility::quiz_post_type() === $post_type ) {
			return __( 'Quiz duplicated successfully.', 'tutor-lms-curriculum-duplicator' );
		}

		if ( Compatibility::assignment_post_type() === $post_type ) {
			return __( 'Assignment duplicated successfully.', 'tutor-lms-curriculum-duplicator' );
		}

		return __( 'Lesson duplicated successfully.', 'tutor-lms-curriculum-duplicator' );
	}

	/**
	 * GET /courses/{id}/curriculum
	 *
	 * ใช้ให้ฝั่ง JavaScript รู้ว่าแถวไหนใน DOM คือ Topic/Content ID อะไร
	 * ต้องส่ง "ทุกชนิด" ที่ Course Builder แสดง ไม่ใช่เฉพาะชนิดที่ทำสำเนาได้
	 * มิฉะนั้นจำนวนแถวจะไม่ตรงกันแล้วปุ่มจะไม่ถูกแทรก
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	public function curriculum( WP_REST_Request $request ) {
		$course_id = absint( $request['id'] );

		if ( Compatibility::course_post_type() !== get_post_type( $course_id ) ) {
			return new WP_Error(
				'tlcd_course_not_found',
				__( 'The requested course was not found.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 404 )
			);
		}

		$topic_ids   = Curriculum_Order::get_ordered_children( $course_id, array( Compatibility::topic_post_type() ) );
		$child_types = Compatibility::topic_child_post_types();
		$duplicable  = Duplicator_Factory::supported_post_types();
		$topics      = array();

		foreach ( $topic_ids as $topic_id ) {
			$contents = array();

			foreach ( Curriculum_Order::get_ordered_children( $topic_id, $child_types ) as $content_id ) {
				$post_type = get_post_type( $content_id );

				$contents[] = array(
					'id'         => (int) $content_id,
					'title'      => wp_strip_all_tags( get_the_title( $content_id ) ),
					'post_type'  => $post_type,
					'duplicable' => in_array( $post_type, $duplicable, true ),
				);
			}

			$topics[] = array(
				'id'       => (int) $topic_id,
				'title'    => wp_strip_all_tags( get_the_title( $topic_id ) ),
				'contents' => $contents,
			);
		}

		return $this->success(
			array(
				'course_id'             => $course_id,
				'lesson_post_type'      => Compatibility::lesson_post_type(),
				'duplicable_post_types' => $duplicable,
				'topics'                => $topics,
			)
		);
	}
}
