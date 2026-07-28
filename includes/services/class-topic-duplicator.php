<?php
/**
 * Topic Duplicator Service
 *
 * ทำสำเนาหัวข้อพร้อมเนื้อหาทั้งหมดภายใน โดยเรียกใช้ Duplicator ตัวเดียวกับปุ่ม Duplicate
 * ของเนื้อหาแต่ละชนิด (ผ่าน Duplicator_Factory) เพื่อให้ผลลัพธ์เหมือนกันเสมอ
 *
 * หากเกิดข้อผิดพลาดกลางทาง จะลบทุกอย่างที่สร้างใน operation นี้ทิ้ง (rollback)
 *
 * @package TLCD
 */

namespace TLCD\Services;

use TLCD\Compatibility;
use TLCD\Curriculum_Order;
use TLCD\Logger;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Class Topic_Duplicator
 */
class Topic_Duplicator {

	/**
	 * จำนวนเนื้อหาสูงสุดต่อหนึ่งหัวข้อที่ยอมทำสำเนาในคำขอเดียว
	 *
	 * กันไม่ให้คำขอตายกลางทางเพราะ max_execution_time ซึ่งจะทำให้ rollback
	 * ไม่ได้ทำงานและเหลือข้อมูลครึ่ง ๆ กลาง ๆ
	 */
	const MAX_CHILDREN = 200;

	/**
	 * ID ที่สร้างใน operation ปัจจุบัน (ใช้ rollback)
	 *
	 * @var int[]
	 */
	private $created_ids = array();

	/**
	 * ทำสำเนาหัวข้อ
	 *
	 * @param int $source_id Topic ID ต้นฉบับ.
	 *
	 * @return array|WP_Error {
	 *     @type int    $topic_id   Topic ID ใหม่.
	 *     @type int    $course_id  Course ID.
	 *     @type array  $contents   [ ['source_id' => int, 'duplicate_id' => int, 'post_type' => string], ... ].
	 *     @type array  $lessons    เฉพาะบทเรียน (คงไว้เพื่อความเข้ากันได้ย้อนหลัง).
	 *     @type array  $skipped    รายการที่ข้าม.
	 *     @type int    $menu_order ลำดับใหม่.
	 * }
	 */
	public function duplicate( $source_id ) {
		$this->created_ids = array();

		$source_id = absint( $source_id );
		$source    = get_post( $source_id );

		if ( ! $source || Compatibility::topic_post_type() !== $source->post_type ) {
			return new WP_Error( 'tlcd_topic_not_found', __( 'The source topic was not found.', 'tutor-lms-curriculum-duplicator' ), array( 'status' => 404 ) );
		}

		if ( 'trash' === $source->post_status ) {
			return new WP_Error( 'tlcd_topic_trashed', __( 'Topics in the trash cannot be duplicated.', 'tutor-lms-curriculum-duplicator' ), array( 'status' => 400 ) );
		}

		$course_id = Compatibility::course_id_from_topic( $source_id );

		if ( ! $course_id ) {
			return new WP_Error( 'tlcd_course_not_found', __( 'This topic does not belong to any course.', 'tutor-lms-curriculum-duplicator' ), array( 'status' => 400 ) );
		}

		$children = Curriculum_Order::get_ordered_children(
			$source_id,
			Compatibility::topic_child_post_types()
		);

		/**
		 * Filter จำนวนเนื้อหาสูงสุดต่อหัวข้อที่ยอมทำสำเนาในคำขอเดียว
		 *
		 * @param int $max      จำนวนสูงสุด.
		 * @param int $topic_id Topic ต้นฉบับ.
		 */
		$max_children = (int) apply_filters( 'tlcd_max_topic_children', self::MAX_CHILDREN, $source_id );

		if ( $max_children > 0 && count( $children ) > $max_children ) {
			return new WP_Error(
				'tlcd_topic_too_large',
				sprintf(
					/* translators: 1: number of items in topic, 2: maximum supported */
					__( 'This topic has %1$d items, which exceeds the limit of %2$d per duplication. Please split the topic first.', 'tutor-lms-curriculum-duplicator' ),
					count( $children ),
					$max_children
				),
				array( 'status' => 413 )
			);
		}

		try {
			$title = Title_Generator::generate(
				$source->post_title,
				$course_id,
				array( Compatibility::topic_post_type() )
			);

			$author = get_current_user_id() ? get_current_user_id() : (int) $source->post_author;

			$postarr = array(
				'post_type'    => Compatibility::topic_post_type(),
				'post_title'   => $title,
				'post_name'    => '',
				'post_content' => $source->post_content,
				'post_excerpt' => $source->post_excerpt,
				'post_status'  => in_array( $source->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ? 'publish' : $source->post_status,
				'post_author'  => (int) apply_filters( 'tlcd_duplicate_author', $author, $source ),
				'post_parent'  => $course_id,
				'menu_order'   => (int) $source->menu_order,
			);

			/**
			 * Filter ข้อมูลโพสต์ก่อนสร้างสำเนาหัวข้อ
			 *
			 * @param array    $postarr ข้อมูลสำหรับ wp_insert_post().
			 * @param \WP_Post $source  โพสต์ต้นฉบับ.
			 */
			$postarr      = apply_filters( 'tlcd_topic_postarr', $postarr, $source );
			$new_topic_id = wp_insert_post( wp_slash( $postarr ), true );
		} catch ( Throwable $error ) {
			Logger::error( 'topic_duplicate', $error, array( 'source_id' => $source_id ) );

			return new WP_Error(
				'tlcd_unexpected_duplication_error',
				__( 'The topic could not be duplicated because an unexpected error occurred.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 500 )
			);
		}

		if ( is_wp_error( $new_topic_id ) ) {
			return $new_topic_id;
		}

		if ( ! $new_topic_id ) {
			return new WP_Error( 'tlcd_insert_failed', __( 'Could not create the topic duplicate.', 'tutor-lms-curriculum-duplicator' ), array( 'status' => 500 ) );
		}

		$new_topic_id        = (int) $new_topic_id;
		$this->created_ids[] = $new_topic_id;

		try {
			$meta_result = Post_Meta_Copier::copy( $source_id, $new_topic_id, Post_Meta_Copier::topic_allowlist() );

			if ( is_wp_error( $meta_result ) ) {
				Logger::error( 'topic_duplicate', $meta_result->get_error_code(), array( 'source_id' => $source_id ) );
				$this->rollback();

				return $meta_result;
			}

			$result = $this->duplicate_children( $children, $new_topic_id );

			if ( is_wp_error( $result ) ) {
				Logger::error( 'topic_duplicate', $result->get_error_code(), array( 'source_id' => $source_id ) );
				$this->rollback();

				return $result;
			}

			$menu_order = Curriculum_Order::place_after(
				$course_id,
				$source_id,
				$new_topic_id,
				array( Compatibility::topic_post_type() )
			);

			if ( is_wp_error( $menu_order ) ) {
				Logger::error( 'topic_duplicate', $menu_order->get_error_code(), array( 'source_id' => $source_id ) );
				$this->rollback();

				return $menu_order;
			}

			clean_post_cache( $new_topic_id );
		} catch ( Throwable $error ) {
			$this->rollback();
			Logger::error(
				'topic_duplicate',
				$error,
				array(
					'source_id' => $source_id,
					'new_id'    => $new_topic_id,
				)
			);

			return new WP_Error(
				'tlcd_unexpected_duplication_error',
				__( 'The topic could not be duplicated because an unexpected error occurred.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 500 )
			);
		}

		/**
		 * Action หลังทำสำเนาหัวข้อสำเร็จ
		 *
		 * @param int $new_topic_id Topic ID ใหม่.
		 * @param int $source_id    Topic ID ต้นฉบับ.
		 * @param int $course_id    Course ID.
		 */
		try {
			do_action( 'tlcd/topic/duplicated', $new_topic_id, $source_id, $course_id );
		} catch ( Throwable $error ) {
			// สำเนาสมบูรณ์แล้ว จึงบันทึก error ของ extension โดยไม่ rollback.
			Logger::error(
				'topic_duplicated_hook',
				$error,
				array(
					'source_id' => $source_id,
					'new_id'    => $new_topic_id,
				)
			);
		}

		return array(
			'topic_id'   => $new_topic_id,
			'course_id'  => $course_id,
			'title'      => get_the_title( $new_topic_id ),
			'menu_order' => $menu_order,
			'contents'   => $result['contents'],
			'lessons'    => $result['lessons'],
			'skipped'    => $result['skipped'],
		);
	}

	/**
	 * ทำสำเนาเนื้อหาทั้งหมดภายในหัวข้อ
	 *
	 * @param int[] $children     ID ของเนื้อหาต้นฉบับ เรียงตามลำดับเดิม.
	 * @param int   $new_topic_id Topic ใหม่.
	 *
	 * @return array|WP_Error
	 */
	private function duplicate_children( array $children, $new_topic_id ) {
		$lesson_post_type = Compatibility::lesson_post_type();

		$contents     = array();
		$lessons      = array();
		$skipped      = array();
		$new_contents = array();

		foreach ( $children as $child_id ) {
			$child = get_post( $child_id );

			if ( ! $child ) {
				continue;
			}

			$duplicator = Duplicator_Factory::for_post_type( $child->post_type );

			if ( ! $duplicator ) {
				// ชนิดที่ยังไม่รองรับ เช่น Zoom / Google Meet / H5P.
				$skipped[] = array(
					'id'        => (int) $child_id,
					'title'     => $child->post_title,
					'post_type' => $child->post_type,
					'reason'    => 'unsupported_type',
				);
				continue;
			}

			$new_id = $duplicator->duplicate(
				$child_id,
				$new_topic_id,
				array(
					'reorder' => false,
					'rename'  => true,
				)
			);

			if ( is_wp_error( $new_id ) ) {
				return $new_id;
			}

			$this->created_ids[] = (int) $new_id;
			$new_contents[]      = (int) $new_id;

			$entry = array(
				'source_id'    => (int) $child_id,
				'duplicate_id' => (int) $new_id,
				'post_type'    => $child->post_type,
				'title'        => get_the_title( $new_id ),
			);

			$contents[] = $entry;

			if ( $lesson_post_type === $child->post_type ) {
				$lessons[] = $entry;
			}
		}

		// เรียงลำดับเนื้อหาใน Topic ใหม่ให้ตรงกับต้นฉบับ.
		$order_result = Curriculum_Order::renumber( $new_contents );

		if ( is_wp_error( $order_result ) ) {
			return $order_result;
		}

		return array(
			'contents' => $contents,
			'lessons'  => $lessons,
			'skipped'  => $skipped,
		);
	}

	/**
	 * ลบทุกอย่างที่สร้างใน operation นี้
	 *
	 * ต้องลบข้อมูลในตารางของ Tutor ด้วย ไม่ใช่แค่โพสต์ เพราะคำถามของแบบทดสอบ
	 * ไม่ได้ผูกกับโพสต์ผ่าน foreign key
	 *
	 * @return void
	 */
	private function rollback() {
		$quiz_post_type = Compatibility::quiz_post_type();

		// ลบย้อนกลับ เพื่อให้ลูกถูกลบก่อนพ่อแม่.
		foreach ( array_reverse( $this->created_ids ) as $id ) {
			if ( get_post_type( $id ) === $quiz_post_type ) {
				Quiz_Duplicator::delete_questions( $id );
			}

			if ( ! wp_delete_post( $id, true ) ) {
				Logger::error( 'topic_rollback', 'Could not delete a created post.', array( 'post_id' => $id ) );
			}
		}

		/**
		 * Action เมื่อเกิด rollback
		 *
		 * @param int[] $created_ids ID ที่ถูกลบทิ้ง.
		 */
		try {
			do_action( 'tlcd/topic/rollback', $this->created_ids );
		} catch ( Throwable $error ) {
			Logger::error( 'topic_rollback_hook', $error, array( 'post_ids' => $this->created_ids ) );
		}

		$this->created_ids = array();
	}
}
