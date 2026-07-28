<?php
/**
 * จัดลำดับ Curriculum (menu_order)
 *
 * Tutor LMS เก็บลำดับไว้ที่ menu_order ของโพสต์:
 *  - topics : post_parent = course_id
 *  - เนื้อหา : post_parent = topic_id
 *
 * คลาสนี้ใช้วิธี "เรียงใหม่ทั้งชุด" แทนการบวกเลขทีละตัว เพื่อให้ผลลัพธ์แน่นอน
 * แม้ข้อมูลเดิมจะมี menu_order ซ้ำหรือเป็น 0 ทั้งหมด
 *
 * @package TLCD
 */

namespace TLCD;

defined( 'ABSPATH' ) || exit;

/**
 * Class Curriculum_Order
 */
class Curriculum_Order {

	/**
	 * ลำดับเริ่มต้น (Tutor LMS เริ่มนับจาก 1)
	 */
	const FIRST_ORDER = 1;

	/**
	 * ดึงรายการลูกทั้งหมดของ parent เรียงตามลำดับปัจจุบัน
	 *
	 * @param int      $parent_id  Parent post ID.
	 * @param string[] $post_types Post types ที่นับเป็นลูก.
	 *
	 * @return int[] Post IDs เรียงตามลำดับ
	 */
	public static function get_ordered_children( $parent_id, array $post_types ) {
		global $wpdb;

		$parent_id = absint( $parent_id );
		$statuses  = Compatibility::countable_post_statuses();

		if ( ! $parent_id || ! $post_types || ! $statuses ) {
			return array();
		}

		$type_placeholders   = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$params = array_merge(
			array( $parent_id ),
			array_values( $post_types ),
			array_values( $statuses )
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$sql = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_parent = %d
			   AND post_type IN ( {$type_placeholders} )
			   AND post_status IN ( {$status_placeholders} )
			 ORDER BY menu_order ASC, ID ASC",
			$params
		);

		$ids = $wpdb->get_col( $sql );
		// phpcs:enable

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * วาง $moved_id ต่อจาก $anchor_id แล้วเรียงลำดับใหม่ทั้งชุด
	 *
	 * @param int      $parent_id  Parent post ID.
	 * @param int      $anchor_id  โพสต์ต้นฉบับที่ต้องการวางต่อจาก.
	 * @param int      $moved_id   โพสต์สำเนาที่จะย้าย.
	 * @param string[] $post_types Post types ที่นับเป็นลูก.
	 *
	 * @return int|\WP_Error menu_order ใหม่ของ $moved_id
	 */
	public static function place_after( $parent_id, $anchor_id, $moved_id, array $post_types ) {
		$anchor_id = absint( $anchor_id );
		$moved_id  = absint( $moved_id );

		$children = self::get_ordered_children( $parent_id, $post_types );

		// เอา moved ออกจากตำแหน่งเดิมก่อน.
		$children = array_values(
			array_filter(
				$children,
				static function ( $id ) use ( $moved_id ) {
					return $id !== $moved_id;
				}
			)
		);

		$anchor_index = array_search( $anchor_id, $children, true );

		if ( false === $anchor_index ) {
			$children[] = $moved_id;
		} else {
			array_splice( $children, $anchor_index + 1, 0, array( $moved_id ) );
		}

		return self::apply_order( $children, $moved_id );
	}

	/**
	 * เขียน menu_order ตามลำดับใน array
	 *
	 * @param int[] $ordered_ids Post IDs เรียงตามลำดับที่ต้องการ.
	 * @param int   $return_for  ต้องการค่าลำดับของ ID นี้กลับไป.
	 *
	 * @return int|\WP_Error
	 */
	public static function apply_order( array $ordered_ids, $return_for = 0 ) {
		global $wpdb;

		$order       = self::FIRST_ORDER;
		$result      = 0;
		$case_parts  = array();
		$case_values = array();
		$ids         = array();

		foreach ( $ordered_ids as $id ) {
			$id = absint( $id );

			if ( ! $id ) {
				continue;
			}

			$case_parts[]  = 'WHEN %d THEN %d';
			$case_values[] = $id;
			$case_values[] = $order;
			$ids[]         = $id;

			if ( absint( $return_for ) === $id ) {
				$result = $order;
			}

			++$order;
		}

		if ( ! $ids ) {
			return $result;
		}

		$id_placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$params          = array_merge( $case_values, $ids );

		// อัปเดตทั้งชุดใน statement เดียว เพื่อไม่ให้เกิดลำดับครึ่งสำเร็จ.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$wpdb->posts}` SET `menu_order` = CASE `ID` " .
				implode( ' ', $case_parts ) .
				" END WHERE `ID` IN ( {$id_placeholders} )",
				$params
			)
		);
		// phpcs:enable

		if ( false === $updated ) {
			Logger::error(
				'curriculum_order',
				$wpdb->last_error,
				array( 'post_ids' => $ids )
			);

			return new \WP_Error(
				'tlcd_order_update_failed',
				__( 'Could not update the curriculum order.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 500 )
			);
		}

		foreach ( $ids as $id ) {
			clean_post_cache( $id );
		}

		return $result;
	}

	/**
	 * ตั้งลำดับให้เนื้อหาภายใน Topic ที่เพิ่งสร้างใหม่ (1..n)
	 *
	 * @param int[] $ordered_ids Post IDs.
	 *
	 * @return true|\WP_Error
	 */
	public static function renumber( array $ordered_ids ) {
		$result = self::apply_order( $ordered_ids );

		return is_wp_error( $result ) ? $result : true;
	}
}
