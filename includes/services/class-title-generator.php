<?php
/**
 * สร้างชื่อสำเนา
 *
 * รูปแบบ (ข้อความต่อท้ายมาจากไฟล์แปลของแต่ละภาษา):
 *   "ชื่อเดิม – Copy"      → ภาษาไทย "ชื่อเดิม – สำเนา"
 *   "ชื่อเดิม – Copy 2"
 *   "ชื่อเดิม – Copy 3"
 *
 * จะตัด suffix เดิมออกก่อนเสมอ เพื่อไม่ให้เกิด "ชื่อเดิม – Copy – Copy"
 *
 * @package TLCD
 */

namespace TLCD\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Class Title_Generator
 */
class Title_Generator {

	/**
	 * จำนวนลำดับสูงสุดที่จะไล่หา ก่อนเปลี่ยนไปใช้ timestamp
	 */
	const MAX_INDEX = 500;

	/**
	 * ข้อความต่อท้ายชื่อสำเนา
	 *
	 * @return string
	 */
	public static function suffix() {
		/**
		 * Filter ข้อความต่อท้ายชื่อสำเนา
		 *
		 * @param string $suffix ค่าเริ่มต้น "– Copy" (ภาษาไทยแปลเป็น "– สำเนา" ผ่านไฟล์ .mo).
		 */
		$suffix = apply_filters( 'tlcd_copy_suffix', __( '– Copy', 'tutor-lms-curriculum-duplicator' ) );

		return trim( (string) $suffix );
	}

	/**
	 * ตัด suffix (พร้อมเลขลำดับ) ออกจากชื่อ เพื่อให้ได้ชื่อฐาน
	 *
	 * @param string $title ชื่อเดิม.
	 *
	 * @return string
	 */
	public static function base_title( $title ) {
		$suffix = self::suffix();

		if ( '' === $suffix ) {
			return trim( $title );
		}

		$pattern = '/\s*' . preg_quote( $suffix, '/' ) . '(\s+\d+)?\s*$/u';

		$previous = null;
		$title    = trim( (string) $title );

		// ตัดซ้ำจนหมด เผื่อข้อมูลเดิมมี suffix ซ้อนกันอยู่แล้ว.
		while ( $previous !== $title ) {
			$previous = $title;
			$title    = trim( (string) preg_replace( $pattern, '', $title ) );
		}

		return $title;
	}

	/**
	 * ประกอบชื่อสำเนาจากชื่อฐานและลำดับ
	 *
	 * @param string $base  ชื่อฐาน.
	 * @param int    $index ลำดับ (1 = ไม่ใส่ตัวเลข).
	 *
	 * @return string
	 */
	public static function compose( $base, $index ) {
		$suffix = self::suffix();
		$index  = max( 1, (int) $index );

		if ( '' === $suffix ) {
			return $base;
		}

		$title = trim( $base . ' ' . $suffix );

		if ( $index > 1 ) {
			$title .= ' ' . $index;
		}

		return $title;
	}

	/**
	 * สร้างชื่อสำเนาที่ไม่ชนกับชื่อพี่น้องภายใต้ parent เดียวกัน
	 *
	 * @param string   $source_title ชื่อต้นฉบับ.
	 * @param int      $parent_id    Parent post ID (topic สำหรับ lesson / course สำหรับ topic).
	 * @param string[] $post_types   Post types ที่ถือเป็นพี่น้อง.
	 *
	 * @return string
	 */
	public static function generate( $source_title, $parent_id, array $post_types ) {
		$base     = self::base_title( $source_title );
		$existing = self::sibling_titles( $parent_id, $post_types );

		$index = 1;
		$title = self::compose( $base, $index );

		while ( in_array( self::normalize( $title ), $existing, true ) && $index <= self::MAX_INDEX ) {
			++$index;
			$title = self::compose( $base, $index );
		}

		/*
		 * ถ้าหาเลขที่ว่างไม่เจอจริง ๆ ต้องไม่คืนชื่อที่ชนกันเงียบ ๆ
		 * เพราะผู้ใช้จะแยกไม่ออกว่าอันไหนคือสำเนาใหม่
		 */
		if ( in_array( self::normalize( $title ), $existing, true ) ) {
			$title = self::compose( $base, $index ) . ' (' . gmdate( 'Y-m-d H:i:s' ) . ')';
		}

		/**
		 * Filter ชื่อสำเนาที่สร้างขึ้น
		 *
		 * @param string $title        ชื่อใหม่.
		 * @param string $source_title ชื่อต้นฉบับ.
		 * @param int    $parent_id    Parent post ID.
		 */
		return apply_filters( 'tlcd_generated_title', $title, $source_title, $parent_id );
	}

	/**
	 * ชื่อของโพสต์พี่น้องทั้งหมด (normalize แล้ว)
	 *
	 * @param int      $parent_id  Parent post ID.
	 * @param string[] $post_types Post types.
	 *
	 * @return string[]
	 */
	private static function sibling_titles( $parent_id, array $post_types ) {
		global $wpdb;

		$parent_id = absint( $parent_id );
		$statuses  = \TLCD\Compatibility::countable_post_statuses();

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

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$titles = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_title FROM {$wpdb->posts}
				 WHERE post_parent = %d
				   AND post_type IN ( {$type_placeholders} )
				   AND post_status IN ( {$status_placeholders} )",
				$params
			)
		);
		// phpcs:enable

		return array_map( array( __CLASS__, 'normalize' ), (array) $titles );
	}

	/**
	 * ทำให้ชื่อเปรียบเทียบกันได้ (ตัดช่องว่างซ้ำ + lowercase)
	 *
	 * @param string $title ชื่อ.
	 *
	 * @return string
	 */
	private static function normalize( $title ) {
		$title = wp_strip_all_tags( (string) $title );
		$title = preg_replace( '/\s+/u', ' ', $title );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $title ) ) : strtolower( trim( $title ) );
	}
}
