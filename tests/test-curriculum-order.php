<?php
/**
 * ชุดทดสอบการจัดลำดับ Curriculum และการตั้งชื่อสำเนา
 *
 * @package TLCD\Tests
 */

use TLCD\Compatibility;
use TLCD\Curriculum_Order;
use TLCD\Services\Lesson_Duplicator;
use TLCD\Services\Title_Generator;

/**
 * Class Test_Curriculum_Order
 */
class Test_Curriculum_Order extends TLCD_TestCase {

	/**
	 * Course ID
	 *
	 * @var int
	 */
	private $course_id;

	/**
	 * Topic ID
	 *
	 * @var int
	 */
	private $topic_id;

	/**
	 * ตั้งค่าเริ่มต้น
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->course_id = TLCD_Seeder::course();
		$this->topic_id  = TLCD_Seeder::topic( $this->course_id, array( 'order' => 1 ) );
	}

	/**
	 * ต้องเรียงตาม menu_order
	 *
	 * @return void
	 */
	public function test_returns_children_in_menu_order() {
		$third  = TLCD_Seeder::lesson(
			$this->topic_id,
			array(
				'title' => 'ที่สาม',
				'order' => 3,
			)
		);
		$first  = TLCD_Seeder::lesson(
			$this->topic_id,
			array(
				'title' => 'ที่หนึ่ง',
				'order' => 1,
			)
		);
		$second = TLCD_Seeder::lesson(
			$this->topic_id,
			array(
				'title' => 'ที่สอง',
				'order' => 2,
			)
		);

		$this->assertSame(
			array( $first, $second, $third ),
			Curriculum_Order::get_ordered_children( $this->topic_id, Compatibility::topic_child_post_types() )
		);
	}

	/**
	 * ถ้า menu_order ซ้ำกันหมด ต้องเรียงต่อด้วย ID เพื่อให้ผลลัพธ์แน่นอน
	 *
	 * @return void
	 */
	public function test_falls_back_to_id_when_menu_order_is_identical() {
		$a = TLCD_Seeder::lesson(
			$this->topic_id,
			array(
				'title' => 'A',
				'order' => 0,
			)
		);
		$b = TLCD_Seeder::lesson(
			$this->topic_id,
			array(
				'title' => 'B',
				'order' => 0,
			)
		);
		$c = TLCD_Seeder::lesson(
			$this->topic_id,
			array(
				'title' => 'C',
				'order' => 0,
			)
		);

		$this->assertSame(
			array( $a, $b, $c ),
			Curriculum_Order::get_ordered_children( $this->topic_id, Compatibility::topic_child_post_types() )
		);
	}

	/**
	 * รายการในถังขยะต้องไม่ถูกนับ
	 *
	 * @return void
	 */
	public function test_excludes_trashed_children() {
		$keep  = TLCD_Seeder::lesson(
			$this->topic_id,
			array(
				'title' => 'เก็บไว้',
				'order' => 1,
			)
		);
		$trash = TLCD_Seeder::lesson(
			$this->topic_id,
			array(
				'title' => 'ทิ้ง',
				'order' => 2,
			)
		);

		wp_trash_post( $trash );

		$this->assertSame(
			array( $keep ),
			Curriculum_Order::get_ordered_children( $this->topic_id, Compatibility::topic_child_post_types() )
		);
	}

	/**
	 * รายการที่เป็นฉบับร่างต้องยังถูกนับ (Course Builder แสดงอยู่)
	 *
	 * @return void
	 */
	public function test_includes_draft_children() {
		$draft = TLCD_Seeder::lesson(
			$this->topic_id,
			array(
				'title'  => 'ฉบับร่าง',
				'order'  => 1,
				'status' => 'draft',
			)
		);

		$this->assertContains(
			$draft,
			Curriculum_Order::get_ordered_children( $this->topic_id, Compatibility::topic_child_post_types() )
		);
	}

	/**
	 * place_after ต้องแทรกตรงตำแหน่งและเรียงเลขใหม่ตั้งแต่ 1
	 *
	 * @return void
	 */
	public function test_place_after_inserts_at_correct_position() {
		$a = TLCD_Seeder::lesson(
			$this->topic_id,
			array(
				'title' => 'A',
				'order' => 1,
			)
		);
		$b = TLCD_Seeder::lesson(
			$this->topic_id,
			array(
				'title' => 'B',
				'order' => 2,
			)
		);
		$c = TLCD_Seeder::lesson(
			$this->topic_id,
			array(
				'title' => 'C',
				'order' => 3,
			)
		);
		$x = TLCD_Seeder::lesson(
			$this->topic_id,
			array(
				'title' => 'X',
				'order' => 99,
			)
		);

		$order = Curriculum_Order::place_after(
			$this->topic_id,
			$a,
			$x,
			Compatibility::topic_child_post_types()
		);

		$this->assertSame( 2, $order, 'ลำดับใหม่ของ X ต้องเป็น 2' );
		$this->assertSame(
			array( $a, $x, $b, $c ),
			Curriculum_Order::get_ordered_children( $this->topic_id, Compatibility::topic_child_post_types() )
		);
		$this->assertSame( 1, (int) get_post_field( 'menu_order', $a ) );
		$this->assertSame( 3, (int) get_post_field( 'menu_order', $b ) );
		$this->assertSame( 4, (int) get_post_field( 'menu_order', $c ) );
	}

	/**
	 * ถ้าไม่พบ anchor ต้องต่อท้ายแทนที่จะพัง
	 *
	 * @return void
	 */
	public function test_place_after_appends_when_anchor_missing() {
		$a = TLCD_Seeder::lesson(
			$this->topic_id,
			array(
				'title' => 'A',
				'order' => 1,
			)
		);
		$x = TLCD_Seeder::lesson(
			$this->topic_id,
			array(
				'title' => 'X',
				'order' => 2,
			)
		);

		$order = Curriculum_Order::place_after(
			$this->topic_id,
			999999,
			$x,
			Compatibility::topic_child_post_types()
		);

		$this->assertSame( 2, $order );
		$this->assertSame(
			array( $a, $x ),
			Curriculum_Order::get_ordered_children( $this->topic_id, Compatibility::topic_child_post_types() )
		);
	}

	/**
	 * ลำดับต้องเริ่มจาก 1 เหมือนที่ Tutor LMS ใช้
	 *
	 * @return void
	 */
	public function test_order_starts_at_one() {
		$a = TLCD_Seeder::lesson(
			$this->topic_id,
			array(
				'title' => 'A',
				'order' => 50,
			)
		);
		$b = TLCD_Seeder::lesson(
			$this->topic_id,
			array(
				'title' => 'B',
				'order' => 70,
			)
		);

		Curriculum_Order::renumber( array( $a, $b ) );

		$this->assertSame( 1, (int) get_post_field( 'menu_order', $a ) );
		$this->assertSame( 2, (int) get_post_field( 'menu_order', $b ) );
	}

	/**
	 * ทำสำเนาติดกันหลายครั้งต้องได้ลำดับที่ถูกต้องทุกครั้ง
	 *
	 * @return void
	 */
	public function test_consecutive_duplications_keep_order_consistent() {
		$a = TLCD_Seeder::lesson(
			$this->topic_id,
			array(
				'title' => 'A',
				'order' => 1,
			)
		);
		$b = TLCD_Seeder::lesson(
			$this->topic_id,
			array(
				'title' => 'B',
				'order' => 2,
			)
		);

		$duplicator = new Lesson_Duplicator();

		$copy_one = $duplicator->duplicate( $a, $this->topic_id );
		$copy_two = $duplicator->duplicate( $a, $this->topic_id );

		$this->assertSame(
			array( $a, $copy_two, $copy_one, $b ),
			Curriculum_Order::get_ordered_children( $this->topic_id, Compatibility::topic_child_post_types() ),
			'สำเนาล่าสุดต้องอยู่ติดกับต้นฉบับเสมอ'
		);
	}

	/**
	 * ชื่อฐานต้องตัด suffix เดิมออกทุกชั้น
	 *
	 * @return void
	 */
	public function test_base_title_strips_existing_suffix() {
		$this->assertSame( 'บทเรียน', Title_Generator::base_title( 'บทเรียน – Copy' ) );
		$this->assertSame( 'บทเรียน', Title_Generator::base_title( 'บทเรียน – Copy 5' ) );
		$this->assertSame( 'บทเรียน', Title_Generator::base_title( 'บทเรียน – Copy – Copy' ) );
		$this->assertSame( 'บทเรียน', Title_Generator::base_title( '  บทเรียน – Copy 2  ' ) );
	}

	/**
	 * ชื่อที่ไม่มี suffix ต้องไม่ถูกแตะต้อง
	 *
	 * @return void
	 */
	public function test_base_title_leaves_plain_titles_alone() {
		$this->assertSame( 'บทเรียนที่ 1', Title_Generator::base_title( 'บทเรียนที่ 1' ) );
		$this->assertSame( 'Lesson 2', Title_Generator::base_title( 'Lesson 2' ) );
	}

	/**
	 * ต้องข้ามชื่อที่ชนกับพี่น้องไปเรื่อย ๆ
	 *
	 * @return void
	 */
	public function test_generate_skips_taken_sibling_titles() {
		TLCD_Seeder::lesson( $this->topic_id, array( 'title' => 'บทเรียน – Copy' ) );
		TLCD_Seeder::lesson( $this->topic_id, array( 'title' => 'บทเรียน – Copy 2' ) );

		$title = Title_Generator::generate(
			'บทเรียน',
			$this->topic_id,
			Compatibility::topic_child_post_types()
		);

		$this->assertSame( 'บทเรียน – Copy 3', $title );
	}

	/**
	 * การเทียบชื่อต้องไม่สนใจตัวพิมพ์เล็กใหญ่และช่องว่างซ้ำ
	 *
	 * @return void
	 */
	public function test_generate_normalizes_when_comparing() {
		TLCD_Seeder::lesson( $this->topic_id, array( 'title' => 'LESSON  –  Copy' ) );

		$title = Title_Generator::generate(
			'lesson',
			$this->topic_id,
			Compatibility::topic_child_post_types()
		);

		$this->assertSame( 'lesson – Copy 2', $title );
	}

	/**
	 * ข้อความต่อท้ายต้องเปลี่ยนผ่าน filter ได้
	 *
	 * @return void
	 */
	public function test_suffix_is_filterable() {
		$callback = static function () {
			return '(copy)';
		};

		add_filter( 'tlcd_copy_suffix', $callback );

		$title = Title_Generator::generate(
			'Lesson',
			$this->topic_id,
			Compatibility::topic_child_post_types()
		);

		remove_filter( 'tlcd_copy_suffix', $callback );

		$this->assertSame( 'Lesson (copy)', $title );
	}

	/**
	 * post type ต้องอ่านจาก config ของ Tutor LMS ไม่ใช่ค่าตายตัว
	 *
	 * @return void
	 */
	public function test_topic_post_type_is_filterable_via_tutor() {
		$this->assertSame( 'topics', Compatibility::topic_post_type() );
		$this->assertContains( 'lesson', Compatibility::topic_child_post_types() );
		$this->assertContains( 'tutor_quiz', Compatibility::topic_child_post_types() );
		$this->assertContains( 'tutor_assignments', Compatibility::topic_child_post_types() );
		$this->assertContains( 'tutor_h5p_quiz', Compatibility::topic_child_post_types() );
	}
}
