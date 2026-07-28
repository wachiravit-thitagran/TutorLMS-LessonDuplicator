<?php
/**
 * Base class สำหรับการทำสำเนา "เนื้อหาใน Topic" หนึ่งรายการ
 *
 * Lesson / Quiz / Assignment ใช้ขั้นตอนหลักร่วมกันทั้งหมด (ตรวจต้นฉบับ → ตรวจปลายทาง →
 * สร้างโพสต์ → คัดลอก meta → คัดลอก taxonomy → จัดลำดับ) ต่างกันเฉพาะ
 * post type, allowlist ของ meta และงานเพิ่มเติมเฉพาะชนิด (เช่น คำถามของ Quiz)
 *
 * แยกเป็น base class เพื่อให้ผลลัพธ์จากปุ่ม Duplicate ของเนื้อหาแต่ละชนิดและจาก
 * Duplicate Topic เหมือนกันเสมอ
 *
 * @package TLCD
 */

namespace TLCD\Services;

use TLCD\Compatibility;
use TLCD\Curriculum_Order;
use TLCD\Logger;
use Throwable;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Class Content_Duplicator
 */
abstract class Content_Duplicator {

	/**
	 * ฟิลด์ที่คัดลอกจากโพสต์ต้นฉบับ
	 *
	 * ไม่คัดลอก: ID, guid, post_date, post_modified, post_name (ให้ WP สร้างใหม่)
	 *
	 * @var string[]
	 */
	protected static $copied_fields = array(
		'post_content',
		'post_excerpt',
		'post_status',
		'comment_status',
		'ping_status',
		'post_password',
		'post_content_filtered',
		'post_mime_type',
	);

	/**
	 * post type ที่คลาสนี้รับผิดชอบ
	 *
	 * @return string
	 */
	abstract public function post_type();

	/**
	 * meta key ที่ต้องคัดลอกสำหรับชนิดนี้
	 *
	 * @return string[]
	 */
	abstract protected function meta_allowlist();

	/**
	 * ชื่อสั้นของชนิดเนื้อหา ใช้ในชื่อ hook (lesson|quiz|assignment)
	 *
	 * @return string
	 */
	abstract protected function type_slug();

	/**
	 * ข้อความ error เมื่อไม่พบต้นฉบับ
	 *
	 * @return string
	 */
	protected function not_found_message() {
		return __( 'The source item was not found.', 'tutor-lms-curriculum-duplicator' );
	}

	/**
	 * ข้อความ error เมื่อชนิดไม่ตรง
	 *
	 * @return string
	 */
	protected function wrong_type_message() {
		return __( 'The selected item is not a supported content type.', 'tutor-lms-curriculum-duplicator' );
	}

	/**
	 * งานเพิ่มเติมหลังสร้างโพสต์สำเนาแล้ว (override ได้)
	 *
	 * คืน WP_Error เพื่อบอกให้ผู้เรียกทำ rollback
	 *
	 * @param int     $new_id    ID ใหม่.
	 * @param WP_Post $source    โพสต์ต้นฉบับ.
	 * @param int     $topic_id  Topic ปลายทาง.
	 * @param int     $course_id Course ปลายทาง.
	 *
	 * @return true|WP_Error
	 */
	protected function after_insert( $new_id, $source, $topic_id, $course_id ) {
		unset( $new_id, $source, $topic_id, $course_id );

		return true;
	}

	/**
	 * ทำสำเนาเนื้อหาหนึ่งรายการ
	 *
	 * @param int   $source_id ID ต้นฉบับ.
	 * @param int   $topic_id  Topic ปลายทาง.
	 * @param array $args      ตัวเลือกเพิ่มเติม.
	 *
	 *     @type bool $reorder   จัดลำดับใน Topic ทันทีหรือไม่ (ค่าเริ่มต้น true).
	 *     @type bool $rename    สร้างชื่อสำเนาหรือไม่ (ค่าเริ่มต้น true).
	 *     @type int  $anchor_id วางต่อจากโพสต์ไหน (ค่าเริ่มต้น = $source_id).
	 *
	 * @return int|WP_Error ID ใหม่
	 */
	public function duplicate( $source_id, $topic_id, array $args = array() ) {
		$source_id = absint( $source_id );
		$topic_id  = absint( $topic_id );

		$args = wp_parse_args(
			$args,
			array(
				'reorder'   => true,
				'rename'    => true,
				'anchor_id' => $source_id,
			)
		);

		$source = get_post( $source_id );

		if ( ! $source ) {
			return new WP_Error(
				'tlcd_source_not_found',
				$this->not_found_message(),
				array( 'status' => 404 )
			);
		}

		if ( $this->post_type() !== $source->post_type ) {
			return new WP_Error(
				'tlcd_invalid_source_type',
				$this->wrong_type_message(),
				array( 'status' => 400 )
			);
		}

		if ( 'trash' === $source->post_status ) {
			return new WP_Error(
				'tlcd_source_trashed',
				__( 'Items in the trash cannot be duplicated.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 400 )
			);
		}

		$topic = get_post( $topic_id );

		if ( ! $topic || Compatibility::topic_post_type() !== $topic->post_type ) {
			return new WP_Error(
				'tlcd_topic_not_found',
				__( 'The destination topic was not found.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 404 )
			);
		}

		$course_id = Compatibility::course_id_from_topic( $topic_id );

		if ( ! $course_id ) {
			return new WP_Error(
				'tlcd_course_not_found',
				__( 'The destination topic does not belong to any course.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 400 )
			);
		}

		try {
			$new_id = $this->insert_copy( $source, $topic_id, (bool) $args['rename'] );
		} catch ( Throwable $error ) {
			Logger::error(
				'content_duplicate',
				$error,
				array(
					'source_id' => $source_id,
					'type'      => $this->type_slug(),
				)
			);

			return new WP_Error(
				'tlcd_unexpected_duplication_error',
				__( 'The item could not be duplicated because an unexpected error occurred.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 500 )
			);
		}

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		try {
			$result = Post_Meta_Copier::copy( $source_id, $new_id, $this->meta_allowlist() );

			if ( is_wp_error( $result ) ) {
				return $this->rollback_copy( $new_id, $result );
			}

			$result = $this->rewrite_course_references( $new_id, $course_id );

			if ( is_wp_error( $result ) ) {
				return $this->rollback_copy( $new_id, $result );
			}

			$result = $this->copy_taxonomies( $source_id, $new_id );

			if ( is_wp_error( $result ) ) {
				return $this->rollback_copy( $new_id, $result );
			}

			$result = $this->after_insert( $new_id, $source, $topic_id, $course_id );

			if ( is_wp_error( $result ) ) {
				return $this->rollback_copy( $new_id, $result );
			}

			if ( $args['reorder'] ) {
				$result = Curriculum_Order::place_after(
					$topic_id,
					absint( $args['anchor_id'] ),
					$new_id,
					Compatibility::topic_child_post_types()
				);

				if ( is_wp_error( $result ) ) {
					return $this->rollback_copy( $new_id, $result );
				}
			}

			clean_post_cache( $new_id );
		} catch ( Throwable $error ) {
			Logger::error(
				'content_duplicate',
				$error,
				array(
					'source_id' => $source_id,
					'new_id'    => $new_id,
					'type'      => $this->type_slug(),
				)
			);

			return $this->rollback_copy(
				$new_id,
				new WP_Error(
					'tlcd_unexpected_duplication_error',
					__( 'The item could not be duplicated because an unexpected error occurred.', 'tutor-lms-curriculum-duplicator' ),
					array( 'status' => 500 )
				)
			);
		}

		/**
		 * Action หลังทำสำเนาเนื้อหาสำเร็จ (ทุกชนิด)
		 *
		 * @param int    $new_id    ID ใหม่.
		 * @param int    $source_id ID ต้นฉบับ.
		 * @param int    $topic_id  Topic ปลายทาง.
		 * @param string $type      lesson|quiz|assignment.
		 */
		try {
			do_action( 'tlcd/content/duplicated', $new_id, $source_id, $topic_id, $this->type_slug() );

			/**
			 * Action หลังทำสำเนาเนื้อหาชนิดใดชนิดหนึ่งสำเร็จ
			 *
			 * เช่น `tlcd/lesson/duplicated`, `tlcd/quiz/duplicated`
			 *
			 * @param int $new_id    ID ใหม่.
			 * @param int $source_id ID ต้นฉบับ.
			 * @param int $topic_id  Topic ปลายทาง.
			 */
			do_action( 'tlcd/' . $this->type_slug() . '/duplicated', $new_id, $source_id, $topic_id );
		} catch ( Throwable $error ) {
			// สำเนาสมบูรณ์แล้ว จึงบันทึก error ของ extension โดยไม่ rollback.
			Logger::error(
				'content_duplicated_hook',
				$error,
				array(
					'source_id' => $source_id,
					'new_id'    => $new_id,
					'type'      => $this->type_slug(),
				)
			);
		}

		return $new_id;
	}

	/**
	 * สร้างโพสต์สำเนา
	 *
	 * @param WP_Post $source   โพสต์ต้นฉบับ.
	 * @param int     $topic_id Topic ปลายทาง.
	 * @param bool    $rename   สร้างชื่อสำเนาหรือไม่.
	 *
	 * @return int|WP_Error
	 */
	protected function insert_copy( $source, $topic_id, $rename ) {
		$title = $rename
			? Title_Generator::generate( $source->post_title, $topic_id, Compatibility::topic_child_post_types() )
			: $source->post_title;

		$postarr = array(
			'post_type'   => $this->post_type(),
			'post_title'  => $title,
			'post_name'   => '',
			'post_parent' => $topic_id,
			'post_author' => $this->resolve_author( $source ),
			'menu_order'  => (int) $source->menu_order,
		);

		foreach ( static::$copied_fields as $field ) {
			$postarr[ $field ] = $source->{$field};
		}

		/**
		 * Filter สถานะโพสต์ของสำเนา
		 *
		 * @param string  $status สถานะเดิมของต้นฉบับ.
		 * @param WP_Post $source โพสต์ต้นฉบับ.
		 */
		$postarr['post_status'] = apply_filters( 'tlcd_duplicate_post_status', $postarr['post_status'], $source );

		if ( in_array( $postarr['post_status'], array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
			$postarr['post_status'] = 'publish';
		}

		/**
		 * Filter ข้อมูลโพสต์ก่อนสร้างสำเนาเนื้อหา
		 *
		 * @param array   $postarr  ข้อมูลสำหรับ wp_insert_post().
		 * @param WP_Post $source   โพสต์ต้นฉบับ.
		 * @param int     $topic_id Topic ปลายทาง.
		 */
		$postarr = apply_filters( 'tlcd_content_postarr', $postarr, $source, $topic_id );

		if ( Compatibility::lesson_post_type() === $this->post_type() ) {
			/**
			 * Filter ข้อมูลโพสต์ก่อนสร้างสำเนาบทเรียน (คงไว้เพื่อความเข้ากันได้ย้อนหลัง)
			 *
			 * @param array   $postarr  ข้อมูลสำหรับ wp_insert_post().
			 * @param WP_Post $source   โพสต์ต้นฉบับ.
			 * @param int     $topic_id Topic ปลายทาง.
			 */
			$postarr = apply_filters( 'tlcd_lesson_postarr', $postarr, $source, $topic_id );
		}

		$new_id = wp_insert_post( wp_slash( $postarr ), true );

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		if ( ! $new_id ) {
			return new WP_Error(
				'tlcd_insert_failed',
				__( 'Could not create the duplicate.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 500 )
			);
		}

		return (int) $new_id;
	}

	/**
	 * เขียน meta ที่อ้างถึงคอร์สให้ชี้ไปคอร์สปลายทางเสมอ
	 *
	 * Tutor LMS ใช้ meta เหล่านี้เป็น fallback หาคอร์สเมื่อเนื้อหาไม่มี parent
	 * (`Utils::get_course_id_by()`) ถ้าปล่อยให้ชี้คอร์สเดิม การย้ายข้ามคอร์สในอนาคต
	 * จะได้ค่าที่ผิด
	 *
	 * @param int $post_id   ID ของสำเนา.
	 * @param int $course_id Course ปลายทาง.
	 *
	 * @return true|WP_Error
	 */
	protected function rewrite_course_references( $post_id, $course_id ) {
		foreach ( $this->course_reference_meta_keys() as $key ) {
			$updated = update_post_meta( $post_id, $key, $course_id );

			if ( false === $updated && (int) get_post_meta( $post_id, $key, true ) !== (int) $course_id ) {
				return new WP_Error(
					'tlcd_course_reference_update_failed',
					__( 'Could not update the copied item settings.', 'tutor-lms-curriculum-duplicator' ),
					array( 'status' => 500 )
				);
			}
		}

		return true;
	}

	/**
	 * meta key ที่เก็บ course id ของเนื้อหาชนิดนี้
	 *
	 * @return string[]
	 */
	protected function course_reference_meta_keys() {
		return array();
	}

	/**
	 * ผู้สร้างของสำเนา
	 *
	 * นโยบายเริ่มต้น: ใช้ผู้ใช้ที่กด Duplicate
	 * (ปรับเป็น "คงผู้เขียนเดิม" ได้ผ่าน filter tlcd_duplicate_author)
	 *
	 * @param WP_Post $source โพสต์ต้นฉบับ.
	 *
	 * @return int
	 */
	protected function resolve_author( $source ) {
		$author = get_current_user_id();

		if ( ! $author ) {
			$author = (int) $source->post_author;
		}

		/**
		 * Filter ผู้เขียนของสำเนา
		 *
		 * @param int     $author ผู้ใช้ปัจจุบัน.
		 * @param WP_Post $source โพสต์ต้นฉบับ.
		 */
		return (int) apply_filters( 'tlcd_duplicate_author', $author, $source );
	}

	/**
	 * คัดลอก taxonomy terms
	 *
	 * @param int $source_id      Source post ID.
	 * @param int $destination_id Destination post ID.
	 *
	 * @return true|WP_Error
	 */
	protected function copy_taxonomies( $source_id, $destination_id ) {
		$taxonomies = get_object_taxonomies( get_post_type( $source_id ) );

		foreach ( (array) $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $source_id, $taxonomy, array( 'fields' => 'ids' ) );

			if ( is_wp_error( $terms ) ) {
				return $terms;
			}

			if ( empty( $terms ) ) {
				continue;
			}

			$result = wp_set_object_terms( $destination_id, $terms, $taxonomy, false );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * ลบข้อมูลทั้งหมดของสำเนาหนึ่งรายการ.
	 *
	 * @param int $new_id ID สำเนา.
	 *
	 * @return void
	 */
	protected function delete_copy( $new_id ) {
		wp_delete_post( $new_id, true );
	}

	/**
	 * Rollback สำเนาที่ไม่สมบูรณ์.
	 *
	 * @param int      $new_id ID สำเนา.
	 * @param WP_Error $error  Error ที่ต้องส่งกลับ.
	 *
	 * @return WP_Error
	 */
	private function rollback_copy( $new_id, WP_Error $error ) {
		$this->delete_copy( $new_id );

		return $error;
	}
}
