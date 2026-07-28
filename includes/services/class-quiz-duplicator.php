<?php
/**
 * Quiz Duplicator Service
 *
 * Tutor LMS เก็บคำถามและตัวเลือกของแบบทดสอบไว้ในตารางของตัวเอง ไม่ได้อยู่ใน post meta
 *
 *   {prefix}tutor_quiz_questions        — question_id (PK), quiz_id, ...
 *   {prefix}tutor_quiz_question_answers — answer_id (PK), belongs_question_id, ...
 *
 * การทำสำเนาจึงต้องคัดลอกทั้งสองตารางแล้ว map question_id เดิมไปยัง question_id ใหม่
 * ส่วนตาราง attempt (`tutor_quiz_attempts`, `tutor_quiz_attempt_answers`) เป็นข้อมูล
 * ของผู้เรียน ห้ามคัดลอกเด็ดขาด
 *
 * @package TLCD
 */

namespace TLCD\Services;

use TLCD\Compatibility;
use TLCD\Logger;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Class Quiz_Duplicator
 */
class Quiz_Duplicator extends Content_Duplicator {

	/**
	 * คอลัมน์ของตารางคำถามที่คัดลอก (ไม่รวม question_id และ quiz_id)
	 *
	 * @var string[]
	 */
	private static $question_columns = array(
		'question_title',
		'question_description',
		'answer_explanation',
		'question_type',
		'question_mark',
		'question_settings',
		'question_order',
	);

	/**
	 * คอลัมน์ของตารางตัวเลือกที่คัดลอก (ไม่รวม answer_id และ belongs_question_id)
	 *
	 * @var string[]
	 */
	private static $answer_columns = array(
		'belongs_question_type',
		'answer_title',
		'is_correct',
		'image_id',
		'answer_two_gap_match',
		'answer_view_format',
		'answer_settings',
		'answer_order',
	);

	/**
	 * ID ที่สร้างในตารางของ Tutor ระหว่าง operation นี้ (ใช้ทำความสะอาดเมื่อล้มเหลว)
	 *
	 * @var array{questions: int[], answers: int[]}
	 */
	private $created = array(
		'questions' => array(),
		'answers'   => array(),
	);

	/**
	 * post type ของแบบทดสอบ
	 *
	 * @return string
	 */
	public function post_type() {
		return Compatibility::quiz_post_type();
	}

	/**
	 * ชื่อสั้นของชนิดเนื้อหา
	 *
	 * @return string
	 */
	protected function type_slug() {
		return 'quiz';
	}

	/**
	 * meta key ที่ต้องคัดลอก
	 *
	 * @return string[]
	 */
	protected function meta_allowlist() {
		return Post_Meta_Copier::quiz_allowlist();
	}

	/**
	 * ข้อความ error เมื่อไม่พบต้นฉบับ
	 *
	 * @return string
	 */
	protected function not_found_message() {
		return __( 'The source quiz was not found.', 'tutor-lms-curriculum-duplicator' );
	}

	/**
	 * ข้อความ error เมื่อชนิดไม่ตรง
	 *
	 * @return string
	 */
	protected function wrong_type_message() {
		return __( 'The selected item is not a quiz.', 'tutor-lms-curriculum-duplicator' );
	}

	/**
	 * ชื่อตารางคำถาม
	 *
	 * @return string
	 */
	public static function questions_table() {
		global $wpdb;

		return $wpdb->prefix . 'tutor_quiz_questions';
	}

	/**
	 * ชื่อตารางตัวเลือก
	 *
	 * @return string
	 */
	public static function answers_table() {
		global $wpdb;

		return $wpdb->prefix . 'tutor_quiz_question_answers';
	}

	/**
	 * ตารางของ Tutor พร้อมใช้งานหรือไม่
	 *
	 * @return bool
	 */
	public static function tables_exist() {
		global $wpdb;

		foreach ( array( self::questions_table(), self::answers_table() ) as $table ) {
			// ใช้ equality กับ information_schema เพื่อไม่ให้ `_` ใน prefix ถูกตีความเป็น wildcard.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$found = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
					$table
				)
			);

			if ( '1' !== (string) $found ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * คัดลอกคำถามและตัวเลือกทั้งหมด
	 *
	 * @param int      $new_id    Quiz ID ใหม่.
	 * @param \WP_Post $source    Quiz ต้นฉบับ.
	 * @param int      $topic_id  Topic ปลายทาง.
	 * @param int      $course_id Course ปลายทาง.
	 *
	 * @return true|WP_Error
	 */
	protected function after_insert( $new_id, $source, $topic_id, $course_id ) {
		unset( $topic_id, $course_id );

		$this->created = array(
			'questions' => array(),
			'answers'   => array(),
		);

		if ( ! self::tables_exist() ) {
			return new WP_Error(
				'tlcd_quiz_tables_missing',
				__( 'The Tutor LMS quiz question tables were not found, so the quiz cannot be duplicated.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 409 )
			);
		}

		$result = $this->copy_questions( (int) $source->ID, (int) $new_id );

		if ( is_wp_error( $result ) ) {
			$this->cleanup();

			return $result;
		}

		/**
		 * Action หลังคัดลอกคำถามของแบบทดสอบเสร็จ
		 *
		 * @param int   $new_id    Quiz ID ใหม่.
		 * @param int   $source_id Quiz ID ต้นฉบับ.
		 * @param array $map       [ question_id เดิม => question_id ใหม่ ].
		 */
		do_action( 'tlcd/quiz/questions_copied', $new_id, (int) $source->ID, $result );

		return true;
	}

	/**
	 * คัดลอกคำถามจากแบบทดสอบต้นฉบับไปยังสำเนา
	 *
	 * @param int $source_quiz_id Quiz ต้นฉบับ.
	 * @param int $new_quiz_id    Quiz สำเนา.
	 *
	 * @return array|WP_Error map ของ question_id เดิม => ใหม่
	 */
	private function copy_questions( $source_quiz_id, $new_quiz_id ) {
		global $wpdb;

		$questions_table = self::questions_table();
		$answers_table   = self::answers_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
		$questions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$questions_table} WHERE quiz_id = %d ORDER BY question_order ASC, question_id ASC",
				$source_quiz_id
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( $wpdb->last_error ) {
			Logger::error(
				'quiz_question_read',
				$wpdb->last_error,
				array( 'source_quiz_id' => $source_quiz_id )
			);

			return new WP_Error(
				'tlcd_quiz_question_read_failed',
				__( 'Could not read the source quiz questions.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 500 )
			);
		}

		if ( ! is_array( $questions ) || ! $questions ) {
			return array();
		}

		$map = array();

		foreach ( $questions as $question ) {
			$row = array( 'quiz_id' => $new_quiz_id );

			foreach ( self::$question_columns as $column ) {
				if ( array_key_exists( $column, $question ) ) {
					$row[ $column ] = $question[ $column ];
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$inserted = $wpdb->insert( $questions_table, $row );

			if ( ! $inserted ) {
				return new WP_Error(
					'tlcd_quiz_question_insert_failed',
					__( 'Could not copy the quiz questions.', 'tutor-lms-curriculum-duplicator' ),
					array( 'status' => 500 )
				);
			}

			$new_question_id = (int) $wpdb->insert_id;

			$map[ (int) $question['question_id'] ] = $new_question_id;
			$this->created['questions'][]          = $new_question_id;
		}

		$source_question_ids = array_keys( $map );
		$placeholders        = implode( ', ', array_fill( 0, count( $source_question_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
		$answers = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$answers_table}
				 WHERE belongs_question_id IN ( {$placeholders} )
				 ORDER BY answer_order ASC, answer_id ASC",
				$source_question_ids
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( $wpdb->last_error ) {
			Logger::error(
				'quiz_answer_read',
				$wpdb->last_error,
				array( 'source_quiz_id' => $source_quiz_id )
			);

			return new WP_Error(
				'tlcd_quiz_answer_read_failed',
				__( 'Could not read the source quiz answer options.', 'tutor-lms-curriculum-duplicator' ),
				array( 'status' => 500 )
			);
		}

		foreach ( (array) $answers as $answer ) {
			$source_question_id = (int) $answer['belongs_question_id'];

			if ( ! isset( $map[ $source_question_id ] ) ) {
				continue;
			}

			$row = array( 'belongs_question_id' => $map[ $source_question_id ] );

			foreach ( self::$answer_columns as $column ) {
				if ( array_key_exists( $column, $answer ) ) {
					$row[ $column ] = $answer[ $column ];
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$inserted = $wpdb->insert( $answers_table, $row );

			if ( ! $inserted ) {
				return new WP_Error(
					'tlcd_quiz_answer_insert_failed',
					__( 'Could not copy the quiz answer options.', 'tutor-lms-curriculum-duplicator' ),
					array( 'status' => 500 )
				);
			}

			$this->created['answers'][] = (int) $wpdb->insert_id;
		}

		return $map;
	}

	/**
	 * ลบโพสต์แบบทดสอบและข้อมูลคำถามที่สร้างระหว่าง operation.
	 *
	 * @param int $new_id Quiz ID.
	 *
	 * @return void
	 */
	protected function delete_copy( $new_id ) {
		self::delete_questions( $new_id );
		parent::delete_copy( $new_id );
	}

	/**
	 * ลบแถวที่เพิ่งสร้างในตารางของ Tutor
	 *
	 * @return void
	 */
	private function cleanup() {
		global $wpdb;

		foreach ( $this->created['answers'] as $answer_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( self::answers_table(), array( 'answer_id' => $answer_id ) );
		}

		foreach ( $this->created['questions'] as $question_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( self::questions_table(), array( 'question_id' => $question_id ) );
		}

		$this->created = array(
			'questions' => array(),
			'answers'   => array(),
		);
	}

	/**
	 * ลบคำถามและตัวเลือกทั้งหมดของแบบทดสอบ (ใช้ตอน rollback ของ Topic)
	 *
	 * @param int $quiz_id Quiz ID.
	 *
	 * @return void
	 */
	public static function delete_questions( $quiz_id ) {
		global $wpdb;

		$quiz_id = absint( $quiz_id );

		if ( ! $quiz_id || ! self::tables_exist() ) {
			return;
		}

		$questions_table = self::questions_table();
		$answers_table   = self::answers_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$question_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT question_id FROM {$questions_table} WHERE quiz_id = %d", $quiz_id )
		);

		foreach ( (array) $question_ids as $question_id ) {
			$wpdb->delete( $answers_table, array( 'belongs_question_id' => (int) $question_id ) );
		}

		$wpdb->delete( $questions_table, array( 'quiz_id' => $quiz_id ) );
		// phpcs:enable
	}
}
