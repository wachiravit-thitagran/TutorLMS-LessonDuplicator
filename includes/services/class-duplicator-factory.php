<?php
/**
 * เลือก Duplicator ให้ตรงกับ post type
 *
 * จุดเดียวที่รู้ว่า "เนื้อหาชนิดนี้ใช้คลาสไหนทำสำเนา" ทั้ง REST controller และ
 * Topic_Duplicator เรียกผ่านที่นี่ เพื่อให้เพิ่มชนิดใหม่ในอนาคตแก้ที่เดียว
 *
 * @package TLCD
 */

namespace TLCD\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Class Duplicator_Factory
 */
class Duplicator_Factory {

	/**
	 * แผนที่ post type => class
	 *
	 * @return array<string, string>
	 */
	public static function map() {
		$lesson     = new Lesson_Duplicator();
		$quiz       = new Quiz_Duplicator();
		$assignment = new Assignment_Duplicator();

		$map = array(
			$lesson->post_type()     => Lesson_Duplicator::class,
			$quiz->post_type()       => Quiz_Duplicator::class,
			$assignment->post_type() => Assignment_Duplicator::class,
		);

		/**
		 * Filter แผนที่ post type => คลาส Duplicator
		 *
		 * คลาสที่เพิ่มเข้ามาต้องสืบทอดจาก Content_Duplicator
		 *
		 * @param array<string, string> $map แผนที่.
		 */
		return (array) apply_filters( 'tlcd_duplicator_map', $map );
	}

	/**
	 * สร้าง Duplicator สำหรับ post type ที่ระบุ
	 *
	 * @param string $post_type Post type.
	 *
	 * @return Content_Duplicator|null
	 */
	public static function for_post_type( $post_type ) {
		$map = self::map();

		if ( ! isset( $map[ $post_type ] ) ) {
			return null;
		}

		$class = $map[ $post_type ];

		if ( ! class_exists( $class ) ) {
			return null;
		}

		$instance = new $class();

		return $instance instanceof Content_Duplicator ? $instance : null;
	}

	/**
	 * สร้าง Duplicator จาก post ID
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return Content_Duplicator|null
	 */
	public static function for_post( $post_id ) {
		$post_type = get_post_type( absint( $post_id ) );

		return $post_type ? self::for_post_type( $post_type ) : null;
	}

	/**
	 * post type ที่ทำสำเนาได้จริงในตอนนี้
	 *
	 * @return string[]
	 */
	public static function supported_post_types() {
		return array_keys( self::map() );
	}
}
