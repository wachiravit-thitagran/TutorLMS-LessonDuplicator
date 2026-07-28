<?php
/**
 * ชุดทดสอบ Quiz และ Assignment Duplicator (v1.1)
 *
 * @package TLCD\Tests
 */

use TLCD\Services\Assignment_Duplicator;
use TLCD\Services\Duplicator_Factory;
use TLCD\Services\Quiz_Duplicator;

/**
 * Class Test_Quiz_Duplicator
 */
class Test_Quiz_Duplicator extends TLCD_TestCase {

	/**
	 * โครงสร้างข้อมูลที่ seed ไว้
	 *
	 * @var array
	 */
	private $data;

	/**
	 * ตั้งค่าเริ่มต้น
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->data = TLCD_Seeder::full_course( array( 'author' => $this->admin_id() ) );
	}

	/**
	 * ตารางจำลองที่ seeder สร้างต้องถูก compatibility guard ตรวจพบ
	 *
	 * @return void
	 */
	public function test_detects_existing_quiz_tables() {
		global $wpdb;

		$questions = Quiz_Duplicator::questions_table();
		$answers   = Quiz_Duplicator::answers_table();

		$this->assertTrue(
			Quiz_Duplicator::tables_exist(),
			sprintf(
				'questions=%s answers=%s tables=%s last_error=%s',
				(string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $questions ) ),
				(string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $answers ) ),
				implode( ',', (array) $wpdb->get_col( 'SHOW TABLES' ) ),
				$wpdb->last_error
			)
		);
	}

	/**
	 * แบบทดสอบต้องถูกคัดลอกพร้อมคำถามครบทุกข้อ
	 *
	 * @return void
	 */
	public function test_duplicates_quiz_with_all_questions() {
		$new_id = ( new Quiz_Duplicator() )->duplicate( $this->data['quiz_id'], $this->data['topic_mixed'] );

		$this->assertNotWPError( $new_id );
		$this->assertSame( 2, $this->count_quiz_questions( $new_id ) );
		$this->assertSame( 'แบบทดสอบท้ายบท – Copy', get_the_title( $new_id ) );
	}

	/**
	 * เนื้อหาของคำถามต้องเหมือนต้นฉบับและเรียงลำดับเดิม
	 *
	 * @return void
	 */
	public function test_copies_question_content_and_order() {
		$new_id = ( new Quiz_Duplicator() )->duplicate( $this->data['quiz_id'], $this->data['topic_mixed'] );

		$source_questions = $this->get_quiz_questions( $this->data['quiz_id'] );
		$copied_questions = $this->get_quiz_questions( $new_id );

		$this->assertCount( count( $source_questions ), $copied_questions );

		foreach ( $source_questions as $index => $source ) {
			$copy = $copied_questions[ $index ];

			$this->assertSame( $source['question_title'], $copy['question_title'] );
			$this->assertSame( $source['question_type'], $copy['question_type'] );
			$this->assertSame( $source['question_mark'], $copy['question_mark'] );
			$this->assertSame( $source['question_settings'], $copy['question_settings'] );
			$this->assertSame( $source['question_order'], $copy['question_order'] );
			$this->assertNotSame( $source['question_id'], $copy['question_id'] );
		}
	}

	/**
	 * คำถามใหม่ต้องผูกกับแบบทดสอบใหม่ ไม่ใช่ของเดิม
	 *
	 * @return void
	 */
	public function test_copied_questions_belong_to_new_quiz() {
		$new_id = ( new Quiz_Duplicator() )->duplicate( $this->data['quiz_id'], $this->data['topic_mixed'] );

		foreach ( $this->get_quiz_questions( $new_id ) as $question ) {
			$this->assertSame( (int) $new_id, (int) $question['quiz_id'] );
		}

		$this->assertSame(
			2,
			$this->count_quiz_questions( $this->data['quiz_id'] ),
			'แบบทดสอบต้นฉบับต้องมีคำถามเท่าเดิม'
		);
	}

	/**
	 * ตัวเลือกคำตอบต้อง map ไปยัง question_id ใหม่ให้ถูกต้อง
	 *
	 * @return void
	 */
	public function test_copies_answers_and_remaps_question_ids() {
		$new_id = ( new Quiz_Duplicator() )->duplicate( $this->data['quiz_id'], $this->data['topic_mixed'] );

		$copied_questions = $this->get_quiz_questions( $new_id );

		$first_answers  = $this->get_question_answers( $copied_questions[0]['question_id'] );
		$second_answers = $this->get_question_answers( $copied_questions[1]['question_id'] );

		$this->assertCount( 2, $first_answers );
		$this->assertCount( 3, $second_answers );

		$this->assertSame( 'ตัวเลือกที่ถูก', $first_answers[0]['answer_title'] );
		$this->assertSame( 1, (int) $first_answers[0]['is_correct'] );
		$this->assertSame( 0, (int) $first_answers[1]['is_correct'] );

		$correct = array_filter(
			$second_answers,
			static function ( $answer ) {
				return 1 === (int) $answer['is_correct'];
			}
		);

		$this->assertCount( 2, $correct, 'คำถามแบบหลายคำตอบต้องคงจำนวนข้อที่ถูกไว้' );
	}

	/**
	 * การตั้งค่าแบบทดสอบต้องถูกคัดลอก
	 *
	 * @return void
	 */
	public function test_copies_quiz_options() {
		$new_id = ( new Quiz_Duplicator() )->duplicate( $this->data['quiz_id'], $this->data['topic_mixed'] );

		$options = get_post_meta( $new_id, 'tutor_quiz_option', true );

		$this->assertSame( 80, $options['passing_grade'] );
		$this->assertSame( 'minutes', $options['time_limit']['time_type'] );
	}

	/**
	 * แบบทดสอบที่ยังไม่มีคำถามต้องคัดลอกได้โดยไม่ error
	 *
	 * @return void
	 */
	public function test_duplicates_quiz_without_questions() {
		$empty_quiz = TLCD_Seeder::quiz(
			$this->data['topic_mixed'],
			array(
				'title'     => 'แบบทดสอบว่าง',
				'order'     => 9,
				'questions' => array(),
			)
		);

		$new_id = ( new Quiz_Duplicator() )->duplicate( $empty_quiz, $this->data['topic_mixed'] );

		$this->assertNotWPError( $new_id );
		$this->assertSame( 0, $this->count_quiz_questions( $new_id ) );
	}

	/**
	 * ผลการทำแบบทดสอบของผู้เรียนต้องไม่ถูกคัดลอก
	 *
	 * @return void
	 */
	public function test_does_not_copy_quiz_attempts() {
		update_post_meta( $this->data['quiz_id'], '_tutor_quiz_attempt_cache', 'ข้อมูลผู้เรียน' );
		update_post_meta( $this->data['quiz_id'], '_tutor_attempt_summary', 'ข้อมูลผู้เรียน' );

		$new_id = ( new Quiz_Duplicator() )->duplicate( $this->data['quiz_id'], $this->data['topic_mixed'] );

		$this->assertSame( '', get_post_meta( $new_id, '_tutor_quiz_attempt_cache', true ) );
		$this->assertSame( '', get_post_meta( $new_id, '_tutor_attempt_summary', true ) );
	}

	/**
	 * ถ้าคัดลอกคำถามล้มเหลว ต้องไม่เหลือโพสต์แบบทดสอบค้างไว้
	 *
	 * @return void
	 */
	public function test_cleans_up_when_question_copy_fails() {
		$before_posts = $this->count_quiz_posts();
		$callback     = static function ( $query ) {
			if ( false !== strpos( $query, 'information_schema.tables' ) && false !== strpos( $query, 'tutor_quiz_questions' ) ) {
				return 'SELECT 0';
			}

			return $query;
		};

		add_filter( 'query', $callback );
		$result = ( new Quiz_Duplicator() )->duplicate( $this->data['quiz_id'], $this->data['topic_mixed'] );
		remove_filter( 'query', $callback );

		$this->assertWPError( $result );
		$this->assertSame( 'tlcd_quiz_tables_missing', $result->get_error_code() );
		$this->assertSame(
			$before_posts,
			$this->count_quiz_posts(),
			'ต้องไม่เหลือโพสต์แบบทดสอบที่สร้างค้างไว้'
		);
	}

	/**
	 * SQL error ตอนอ่านคำถามต้องไม่ถูกตีความว่าเป็นแบบทดสอบว่าง
	 *
	 * @return void
	 */
	public function test_rolls_back_when_question_query_fails() {
		global $wpdb;

		$before_posts = $this->count_quiz_posts();
		$table        = $wpdb->prefix . 'tutor_quiz_questions';
		$callback     = static function ( $query ) use ( $table ) {
			if ( false !== strpos( $query, "SELECT * FROM {$table}" ) ) {
				return 'SELECT broken question query';
			}

			return $query;
		};

		add_filter( 'query', $callback );
		$wpdb->suppress_errors( true );
		$result = ( new Quiz_Duplicator() )->duplicate( $this->data['quiz_id'], $this->data['topic_mixed'] );
		$wpdb->suppress_errors( false );
		remove_filter( 'query', $callback );

		$this->assertWPError( $result );
		$this->assertSame( 'tlcd_quiz_question_read_failed', $result->get_error_code() );
		$this->assertSame( $before_posts, $this->count_quiz_posts() );
	}

	/**
	 * SQL error ตอนอ่านตัวเลือกต้อง rollback คำถามและโพสต์ที่สร้างแล้ว
	 *
	 * @return void
	 */
	public function test_rolls_back_when_answer_query_fails() {
		global $wpdb;

		$before_posts     = $this->count_quiz_posts();
		$before_questions = $this->count_quiz_questions( $this->data['quiz_id'] );
		$table            = $wpdb->prefix . 'tutor_quiz_question_answers';
		$callback         = static function ( $query ) use ( $table ) {
			if ( false !== strpos( $query, "SELECT * FROM {$table}" ) ) {
				return 'SELECT broken answer query';
			}

			return $query;
		};

		add_filter( 'query', $callback );
		$wpdb->suppress_errors( true );
		$result = ( new Quiz_Duplicator() )->duplicate( $this->data['quiz_id'], $this->data['topic_mixed'] );
		$wpdb->suppress_errors( false );
		remove_filter( 'query', $callback );

		$this->assertWPError( $result );
		$this->assertSame( 'tlcd_quiz_answer_read_failed', $result->get_error_code() );
		$this->assertSame( $before_posts, $this->count_quiz_posts() );
		$this->assertSame( $before_questions, $this->count_quiz_questions( $this->data['quiz_id'] ) );
	}

	/**
	 * งานที่มอบหมายต้องคัดลอกพร้อมการตั้งค่า
	 *
	 * @return void
	 */
	public function test_duplicates_assignment_with_options() {
		$new_id = ( new Assignment_Duplicator() )->duplicate(
			$this->data['assignment_id'],
			$this->data['topic_mixed']
		);

		$this->assertNotWPError( $new_id );
		$this->assertSame( 'งานส่งท้ายบท – Copy', get_the_title( $new_id ) );

		$options = get_post_meta( $new_id, 'assignment_option', true );

		$this->assertSame( 10, $options['total_mark'] );
		$this->assertSame( 5, $options['pass_mark'] );
	}

	/**
	 * meta ที่อ้างคอร์สของงานที่มอบหมายต้องชี้ไปคอร์สปลายทาง
	 *
	 * @return void
	 */
	public function test_assignment_course_reference_points_to_destination() {
		$new_id = ( new Assignment_Duplicator() )->duplicate(
			$this->data['assignment_id'],
			$this->data['topic_mixed']
		);

		$this->assertSame(
			(int) $this->data['course_id'],
			(int) get_post_meta( $new_id, '_tutor_course_id_for_assignments', true )
		);
	}

	/**
	 * factory ต้องเลือกคลาสให้ถูกกับ post type
	 *
	 * @return void
	 */
	public function test_factory_resolves_correct_duplicator() {
		$this->assertInstanceOf(
			'TLCD\Services\Lesson_Duplicator',
			Duplicator_Factory::for_post( $this->data['lesson_a'] )
		);
		$this->assertInstanceOf(
			'TLCD\Services\Quiz_Duplicator',
			Duplicator_Factory::for_post( $this->data['quiz_id'] )
		);
		$this->assertInstanceOf(
			'TLCD\Services\Assignment_Duplicator',
			Duplicator_Factory::for_post( $this->data['assignment_id'] )
		);
	}

	/**
	 * ชนิดที่ไม่รองรับต้องคืน null ไม่ใช่โยน error
	 *
	 * @return void
	 */
	public function test_factory_returns_null_for_unsupported_type() {
		$this->assertNull( Duplicator_Factory::for_post_type( 'tutor_zoom_meeting' ) );
		$this->assertNull( Duplicator_Factory::for_post_type( 'page' ) );
	}

	/**
	 * นับจำนวนโพสต์แบบทดสอบ
	 *
	 * @return int
	 */
	private function count_quiz_posts() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'trash'",
				'tutor_quiz'
			)
		);
		// phpcs:enable
	}
}
