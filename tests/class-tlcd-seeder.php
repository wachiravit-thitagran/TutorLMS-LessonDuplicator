<?php
/**
 * Seeder สำหรับชุดทดสอบ
 *
 * สร้างข้อมูลคอร์สในรูปแบบต่าง ๆ ที่ตรงกับโครงสร้างจริงของ Tutor LMS
 *
 *   courses (post_type: courses)
 *   └── topics (post_parent = course, menu_order = ลำดับ)
 *       └── lesson | tutor_quiz | tutor_assignments (post_parent = topic)
 *
 * สิทธิ์ของผู้สอนใน Tutor LMS มาจากสองทาง จึงต้อง seed ให้ครบทั้งคู่
 *   1. post_author ของคอร์ส (main instructor)
 *   2. usermeta `_tutor_instructor_course_id` (co-instructor)
 *
 * @package TLCD\Tests
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class TLCD_Seeder
 */
class TLCD_Seeder {

	const COURSE_POST_TYPE     = 'courses';
	const TOPIC_POST_TYPE      = 'topics';
	const LESSON_POST_TYPE     = 'lesson';
	const QUIZ_POST_TYPE       = 'tutor_quiz';
	const ASSIGNMENT_POST_TYPE = 'tutor_assignments';

	/**
	 * ตารางที่ชุดทดสอบสร้างขึ้นเอง (ไม่ใช่ของ Tutor LMS จริง) เพื่อลบคืนตอนจบคลาส
	 *
	 * @var string[]
	 */
	private static $seeded_tables = array();

	/**
	 * ลงทะเบียน post type ให้เหมือน Tutor LMS พอสำหรับการทดสอบ
	 *
	 * ใช้เมื่อรัน unit test โดยไม่ได้ติดตั้ง Tutor LMS จริง
	 *
	 * @return void
	 */
	public static function register_post_types() {
		$types = array(
			self::COURSE_POST_TYPE,
			self::TOPIC_POST_TYPE,
			self::LESSON_POST_TYPE,
			self::QUIZ_POST_TYPE,
			self::ASSIGNMENT_POST_TYPE,
		);

		foreach ( $types as $type ) {
			if ( post_type_exists( $type ) ) {
				continue;
			}

			register_post_type(
				$type,
				array(
					'public'       => false,
					'show_ui'      => false,
					'hierarchical' => true,
					'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'page-attributes' ),
				)
			);
		}
	}

	/**
	 * สร้างตารางคำถามของ Tutor LMS ถ้ายังไม่มี
	 *
	 * ต้องเรียกจาก wpSetUpBeforeClass() เท่านั้น (ดู TLCD_TestCase) เพราะต้องเป็น
	 * ตารางจริง ไม่ใช่ temporary table — Tutor LMS สร้างตารางจริงตอน activate และ
	 * ปลั๊กอินตรวจว่ามีตารางผ่าน `information_schema.tables` ซึ่งไม่เห็น temporary table
	 *
	 * @throws RuntimeException เมื่อสร้างตารางไม่สำเร็จ.
	 *
	 * @return void
	 */
	public static function ensure_quiz_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// จำไว้ว่าตารางไหน "ยังไม่มี" ก่อนเรียก เพื่อลบเฉพาะตารางที่ชุดทดสอบสร้างเอง.
		foreach ( array( 'tutor_quiz_questions', 'tutor_quiz_question_answers' ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;

			if ( ! self::table_exists( $table ) && ! in_array( $table, self::$seeded_tables, true ) ) {
				self::$seeded_tables[] = $table;
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$questions_created = $wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tutor_quiz_questions (
				question_id bigint(20) NOT NULL AUTO_INCREMENT,
				quiz_id bigint(20) DEFAULT NULL,
				question_title text,
				question_description longtext,
				answer_explanation longtext,
				question_type varchar(50) DEFAULT NULL,
				question_mark decimal(9,2) DEFAULT NULL,
				question_settings longtext,
				question_order int(11) DEFAULT NULL,
				PRIMARY KEY (question_id)
			) {$charset_collate}"
		);

		$answers_created = $wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tutor_quiz_question_answers (
				answer_id bigint(20) NOT NULL AUTO_INCREMENT,
				belongs_question_id bigint(20) DEFAULT NULL,
				belongs_question_type varchar(250) DEFAULT NULL,
				answer_title text,
				is_correct tinyint(4) DEFAULT NULL,
				image_id bigint(20) DEFAULT NULL,
				answer_two_gap_match text,
				answer_view_format varchar(250) DEFAULT NULL,
				answer_settings text,
				answer_order int(11) DEFAULT '0',
				PRIMARY KEY (answer_id)
			) {$charset_collate}"
		);
		// phpcs:enable

		if ( false === $questions_created || false === $answers_created ) {
			throw new RuntimeException( esc_html( 'สร้างตาราง Quiz สำหรับทดสอบไม่สำเร็จ: ' . $wpdb->last_error ) );
		}
	}

	/**
	 * ลบเฉพาะตารางที่ชุดทดสอบสร้างขึ้นเอง
	 *
	 * เรียกจาก wpTearDownAfterClass() เพื่อไม่ให้ตารางค้างในฐานข้อมูลของชุดทดสอบ
	 * ถ้ารันแบบ integration แล้ว Tutor LMS สร้างตารางจริงไว้ก่อนแล้ว จะไม่แตะต้อง
	 *
	 * @return void
	 */
	public static function drop_quiz_tables() {
		global $wpdb;

		foreach ( array_reverse( self::$seeded_tables ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		self::$seeded_tables = array();
	}

	/**
	 * ตารางนี้มีอยู่จริงในฐานข้อมูลหรือไม่ (ไม่นับ temporary table)
	 *
	 * @param string $table ชื่อตารางเต็ม.
	 *
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
				$table
			)
		);

		return '1' === (string) $found;
	}

	/**
	 * สร้างคอร์ส
	 *
	 * @param array $args ตัวเลือกคอร์ส.
	 *     @type string $title  ชื่อคอร์ส.
	 *     @type string $status post_status.
	 *     @type int    $author post_author (main instructor).
	 *
	 * @return int Course ID
	 */
	public static function course( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'title'  => 'คอร์สทดสอบ',
				'status' => 'publish',
				'author' => 0,
			)
		);

		return (int) wp_insert_post(
			array(
				'post_type'   => self::COURSE_POST_TYPE,
				'post_title'  => $args['title'],
				'post_status' => $args['status'],
				'post_author' => (int) $args['author'],
			)
		);
	}

	/**
	 * สร้างหัวข้อในคอร์ส
	 *
	 * @param int   $course_id Course ID.
	 * @param array $args      { title, summary, order, status }.
	 *
	 * @return int Topic ID
	 */
	public static function topic( $course_id, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'title'   => 'หัวข้อทดสอบ',
				'summary' => '',
				'order'   => 0,
				'status'  => 'publish',
				'author'  => 0,
			)
		);

		return (int) wp_insert_post(
			array(
				'post_type'    => self::TOPIC_POST_TYPE,
				'post_title'   => $args['title'],
				'post_content' => $args['summary'],
				'post_status'  => $args['status'],
				'post_parent'  => (int) $course_id,
				'post_author'  => (int) $args['author'],
				'menu_order'   => (int) $args['order'],
			)
		);
	}

	/**
	 * สร้างบทเรียนในหัวข้อ
	 *
	 * @param int   $topic_id Topic ID.
	 * @param array $args     { title, content, order, status, meta, video, attachments, thumbnail, preview }.
	 *
	 * @return int Lesson ID
	 */
	public static function lesson( $topic_id, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'title'       => 'บทเรียนทดสอบ',
				'content'     => 'เนื้อหาบทเรียน',
				'excerpt'     => '',
				'order'       => 0,
				'status'      => 'publish',
				'meta'        => array(),
				'video'       => null,
				'attachments' => null,
				'thumbnail'   => 0,
				'preview'     => null,
				'author'      => 0,
			)
		);

		$lesson_id = (int) wp_insert_post(
			array(
				'post_type'    => self::LESSON_POST_TYPE,
				'post_title'   => $args['title'],
				'post_content' => $args['content'],
				'post_excerpt' => $args['excerpt'],
				'post_status'  => $args['status'],
				'post_parent'  => (int) $topic_id,
				'post_author'  => (int) $args['author'],
				'menu_order'   => (int) $args['order'],
			)
		);

		$course_id = (int) wp_get_post_parent_id( (int) $topic_id );

		if ( $course_id ) {
			update_post_meta( $lesson_id, '_tutor_course_id_for_lesson', $course_id );
		}

		if ( null !== $args['video'] ) {
			update_post_meta( $lesson_id, '_video', $args['video'] );
		}

		if ( null !== $args['attachments'] ) {
			update_post_meta( $lesson_id, '_tutor_attachments', $args['attachments'] );
		}

		if ( $args['thumbnail'] ) {
			update_post_meta( $lesson_id, '_thumbnail_id', (int) $args['thumbnail'] );
		}

		if ( null !== $args['preview'] ) {
			update_post_meta( $lesson_id, '_is_preview', $args['preview'] );
		}

		foreach ( (array) $args['meta'] as $key => $value ) {
			update_post_meta( $lesson_id, $key, $value );
		}

		return $lesson_id;
	}

	/**
	 * สร้างแบบทดสอบพร้อมคำถามและตัวเลือก
	 *
	 * @param int   $topic_id Topic ID.
	 * @param array $args     { title, order, status, options, questions }.
	 *
	 * @return int Quiz ID
	 */
	public static function quiz( $topic_id, array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'title'     => 'แบบทดสอบทดสอบ',
				'content'   => '',
				'order'     => 0,
				'status'    => 'publish',
				'options'   => array(
					'time_limit'    => array(
						'time_value' => 0,
						'time_type'  => 'minutes',
					),
					'passing_grade' => 80,
				),
				'questions' => array(),
				'author'    => 0,
			)
		);

		$quiz_id = (int) wp_insert_post(
			array(
				'post_type'    => self::QUIZ_POST_TYPE,
				'post_title'   => $args['title'],
				'post_content' => $args['content'],
				'post_status'  => $args['status'],
				'post_parent'  => (int) $topic_id,
				'post_author'  => (int) $args['author'],
				'menu_order'   => (int) $args['order'],
			)
		);

		update_post_meta( $quiz_id, 'tutor_quiz_option', $args['options'] );

		$order = 0;

		foreach ( (array) $args['questions'] as $question ) {
			$question = wp_parse_args(
				$question,
				array(
					'title'       => 'คำถามทดสอบ',
					'description' => '',
					'explanation' => '',
					'type'        => 'single_choice',
					'mark'        => 1,
					'settings'    => array( 'question_type' => 'single_choice' ),
					'answers'     => array(),
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$wpdb->prefix . 'tutor_quiz_questions',
				array(
					'quiz_id'              => $quiz_id,
					'question_title'       => $question['title'],
					'question_description' => $question['description'],
					'answer_explanation'   => $question['explanation'],
					'question_type'        => $question['type'],
					'question_mark'        => $question['mark'],
					'question_settings'    => maybe_serialize( $question['settings'] ),
					'question_order'       => ++$order,
				)
			);

			$question_id  = (int) $wpdb->insert_id;
			$answer_order = 0;

			foreach ( (array) $question['answers'] as $answer ) {
				$answer = wp_parse_args(
					$answer,
					array(
						'title'   => 'ตัวเลือก',
						'correct' => 0,
					)
				);

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->insert(
					$wpdb->prefix . 'tutor_quiz_question_answers',
					array(
						'belongs_question_id'   => $question_id,
						'belongs_question_type' => $question['type'],
						'answer_title'          => $answer['title'],
						'is_correct'            => (int) $answer['correct'],
						'answer_order'          => ++$answer_order,
					)
				);
			}
		}

		return $quiz_id;
	}

	/**
	 * สร้างงานที่มอบหมาย
	 *
	 * @param int   $topic_id Topic ID.
	 * @param array $args     { title, order, status, options, attachments }.
	 *
	 * @return int Assignment ID
	 */
	public static function assignment( $topic_id, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'title'       => 'งานที่มอบหมายทดสอบ',
				'content'     => 'รายละเอียดงาน',
				'order'       => 0,
				'status'      => 'publish',
				'options'     => array(
					'total_mark'             => 10,
					'pass_mark'              => 5,
					'upload_files_limit'     => 1,
					'upload_file_size_limit' => 2,
				),
				'attachments' => array(),
				'author'      => 0,
			)
		);

		$assignment_id = (int) wp_insert_post(
			array(
				'post_type'    => self::ASSIGNMENT_POST_TYPE,
				'post_title'   => $args['title'],
				'post_content' => $args['content'],
				'post_status'  => $args['status'],
				'post_parent'  => (int) $topic_id,
				'post_author'  => (int) $args['author'],
				'menu_order'   => (int) $args['order'],
			)
		);

		update_post_meta( $assignment_id, 'assignment_option', $args['options'] );

		if ( $args['attachments'] ) {
			update_post_meta( $assignment_id, '_tutor_assignment_attachments', $args['attachments'] );
		}

		$course_id = (int) wp_get_post_parent_id( (int) $topic_id );

		if ( $course_id ) {
			update_post_meta( $assignment_id, '_tutor_course_id_for_assignments', $course_id );
		}

		return $assignment_id;
	}

	/**
	 * ผูกผู้ใช้เป็นผู้สอนร่วมของคอร์ส (แบบเดียวกับที่ Tutor LMS ทำ)
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 *
	 * @return void
	 */
	public static function attach_instructor( $user_id, $course_id ) {
		add_user_meta( (int) $user_id, '_tutor_instructor_course_id', (int) $course_id );
		update_user_meta( (int) $user_id, '_is_tutor_instructor', time() );
		update_user_meta( (int) $user_id, '_tutor_instructor_status', 'approved' );
	}

	/**
	 * บันทึกความก้าวหน้าของผู้เรียนแบบเดียวกับ Tutor LMS
	 *
	 * ใช้ยืนยันว่าการทำสำเนาไม่ได้ลากข้อมูลผู้เรียนติดมาด้วย
	 *
	 * @param int $user_id   User ID.
	 * @param int $lesson_id Lesson ID.
	 *
	 * @return void
	 */
	public static function complete_lesson( $user_id, $lesson_id ) {
		update_user_meta( (int) $user_id, '_tutor_completed_lesson_id_' . (int) $lesson_id, time() );
		update_post_meta( (int) $lesson_id, '_tutor_completed_' . (int) $user_id, time() );
		update_post_meta( (int) $lesson_id, '_lesson_reading_info', array( (int) $user_id => 42 ) );
	}

	/**
	 * ชุดข้อมูลมาตรฐาน: คอร์สหนึ่งคอร์สที่มีเนื้อหาครบทุกแบบ
	 *
	 * โครงสร้างที่ได้
	 *   คอร์ส
	 *   ├── หัวข้อที่ 1 (บทเรียน A ธรรมดา, B มีวิดีโอ+ไฟล์แนบ, C ไม่มี meta)
	 *   ├── หัวข้อที่ 2 (ว่าง)
	 *   └── หัวข้อที่ 3 (บทเรียน D, แบบทดสอบ, งานที่มอบหมาย)
	 *
	 * @param array $args { author }.
	 *
	 * @return array โครงสร้าง ID ทั้งหมด
	 */
	public static function full_course( array $args = array() ) {
		$args   = wp_parse_args( $args, array( 'author' => 0 ) );
		$author = (int) $args['author'];

		$course_id = self::course(
			array(
				'title'  => 'คอร์สตัวอย่างสำหรับทดสอบ',
				'author' => $author,
			)
		);

		$topic_one = self::topic(
			$course_id,
			array(
				'title'   => 'หัวข้อที่ 1',
				'summary' => 'สรุปหัวข้อที่ 1',
				'order'   => 1,
				'author'  => $author,
			)
		);

		$lesson_a = self::lesson(
			$topic_one,
			array(
				'title'   => 'บทเรียน A',
				'content' => '<p>เนื้อหาข้อความล้วน</p>',
				'excerpt' => 'สรุปย่อ A',
				'order'   => 1,
				'author'  => $author,
			)
		);

		$lesson_b = self::lesson(
			$topic_one,
			array(
				'title'       => 'บทเรียน B',
				'content'     => '<p>บทเรียนที่มีวิดีโอ</p>',
				'order'       => 2,
				'author'      => $author,
				'video'       => array(
					'source'          => 'youtube',
					'source_youtube'  => 'https://www.youtube.com/watch?v=tlcd-test',
					'source_video_id' => '',
					'runtime'         => array(
						'hours'   => '00',
						'minutes' => '12',
						'seconds' => '30',
					),
				),
				'attachments' => array( 101, 102 ),
				'preview'     => '1',
			)
		);

		$lesson_c = self::lesson(
			$topic_one,
			array(
				'title'   => 'บทเรียน C',
				'content' => '',
				'order'   => 3,
				'author'  => $author,
			)
		);

		$topic_empty = self::topic(
			$course_id,
			array(
				'title'  => 'หัวข้อที่ 2 (ว่าง)',
				'order'  => 2,
				'author' => $author,
			)
		);

		$topic_mixed = self::topic(
			$course_id,
			array(
				'title'  => 'หัวข้อที่ 3',
				'order'  => 3,
				'author' => $author,
			)
		);

		$lesson_d = self::lesson(
			$topic_mixed,
			array(
				'title'  => 'บทเรียน D',
				'order'  => 1,
				'author' => $author,
			)
		);

		$quiz_id = self::quiz(
			$topic_mixed,
			array(
				'title'     => 'แบบทดสอบท้ายบท',
				'order'     => 2,
				'author'    => $author,
				'questions' => array(
					array(
						'title'   => 'ข้อ 1 ตอบถูกข้อเดียว',
						'type'    => 'single_choice',
						'answers' => array(
							array(
								'title'   => 'ตัวเลือกที่ถูก',
								'correct' => 1,
							),
							array( 'title' => 'ตัวเลือกที่ผิด' ),
						),
					),
					array(
						'title'   => 'ข้อ 2 ตอบถูกหลายข้อ',
						'type'    => 'multiple_choice',
						'mark'    => 2,
						'answers' => array(
							array(
								'title'   => 'ก',
								'correct' => 1,
							),
							array(
								'title'   => 'ข',
								'correct' => 1,
							),
							array( 'title' => 'ค' ),
						),
					),
				),
			)
		);

		$assignment_id = self::assignment(
			$topic_mixed,
			array(
				'title'  => 'งานส่งท้ายบท',
				'order'  => 3,
				'author' => $author,
			)
		);

		return array(
			'course_id'     => $course_id,
			'topic_one'     => $topic_one,
			'topic_empty'   => $topic_empty,
			'topic_mixed'   => $topic_mixed,
			'lesson_a'      => $lesson_a,
			'lesson_b'      => $lesson_b,
			'lesson_c'      => $lesson_c,
			'lesson_d'      => $lesson_d,
			'quiz_id'       => $quiz_id,
			'assignment_id' => $assignment_id,
		);
	}
}
