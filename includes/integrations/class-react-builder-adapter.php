<?php
/**
 * Adapter สำหรับ Course Builder แบบ React (Tutor LMS 3.x / 4.x)
 *
 * Course Builder รุ่นนี้เป็น React application ที่ไม่มี PHP hook สำหรับแทรกปุ่มในแถว
 * Curriculum จึงต้องแทรกปุ่มจากฝั่ง JavaScript
 *
 * จุดยึดที่ใช้คือ attribute `data-cy` ซึ่ง Tutor LMS ใส่ไว้สำหรับชุดทดสอบ Cypress
 * ของตัวเอง (edit-topic / delete-topic / delete-lesson ฯลฯ) ซึ่งเสถียรกว่า
 * class name ที่ถูก generate จาก Emotion ในแต่ละ build
 *
 * @package TLCD
 */

namespace TLCD\Integrations;

use TLCD\Api\Rest_Controller;
use TLCD\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Class React_Builder_Adapter
 */
class React_Builder_Adapter implements Adapter_Interface {

	/**
	 * ชื่อ adapter
	 *
	 * @return string
	 */
	public function get_name() {
		return 'react-builder';
	}

	/**
	 * รองรับ context นี้หรือไม่
	 *
	 * @param string $context react|legacy.
	 *
	 * @return bool
	 */
	public function supports( $context ) {
		return Compatibility::BUILDER_REACT === $context;
	}

	/**
	 * โหลด CSS/JS และส่ง config ให้ฝั่ง client
	 *
	 * @param int $course_id Course ID ปัจจุบัน.
	 *
	 * @return void
	 */
	public function register_assets( $course_id ) {
		wp_enqueue_style(
			'tlcd-curriculum-duplicator',
			TLCD_URL . 'assets/css/curriculum-duplicator.css',
			array(),
			TLCD_VERSION
		);

		wp_enqueue_script(
			'tlcd-curriculum-duplicator',
			TLCD_URL . 'assets/js/curriculum-duplicator.js',
			array(),
			TLCD_VERSION,
			true
		);

		wp_localize_script(
			'tlcd-curriculum-duplicator',
			'tlcdConfig',
			$this->get_config( $course_id )
		);
	}

	/**
	 * Config ที่ส่งไปให้ JavaScript
	 *
	 * @param int $course_id Course ID.
	 *
	 * @return array
	 */
	private function get_config( $course_id ) {
		$config = array(
			'restUrl'        => esc_url_raw( rest_url( Rest_Controller::NAMESPACE_V1 ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'courseId'       => (int) $course_id,
			'lessonPostType' => Compatibility::lesson_post_type(),
			'debug'          => (bool) ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
			'tutorVersion'   => Compatibility::tutor_version(),
			'testedVersion'  => Compatibility::tested_version(),
			'untested'       => Compatibility::is_untested_newer(),
			'selectors'      => array(

				/*
				 * จุดยึดใน DOM — ถ้า Tutor LMS เปลี่ยนโครงสร้างในอนาคต
				 * ปรับได้ผ่าน filter `tlcd_builder_config` โดยไม่ต้องแก้ JavaScript
				 */
				'topicDeleteButton'   => 'button[data-cy="delete-topic"]',
				'topicEditButton'     => 'button[data-cy="edit-topic"]',
				'sortableAncestor'    => '[aria-roledescription="sortable"]',
				'contentActions'      => '[data-actions]',
				'contentDeleteButton' => '[data-actions] button[data-cy^="delete-"]',
				'contentTitle'        => 'p',

				/*
				 * หัวข้อไม่มี element ที่ระบุชื่อได้แน่นอน (เป็น div ที่ใส่ข้อความตรง ๆ)
				 * เว้นว่างไว้เพื่อให้ฝั่ง JavaScript เทียบกับ textContent ของทั้งบล็อกแทน
				 */
				'topicTitle'          => '',
			),
			'contentLabels'  => array(
				Compatibility::lesson_post_type()     => __( 'Duplicate lesson', 'tutor-lms-curriculum-duplicator' ),
				Compatibility::quiz_post_type()       => __( 'Duplicate quiz', 'tutor-lms-curriculum-duplicator' ),
				Compatibility::assignment_post_type() => __( 'Duplicate assignment', 'tutor-lms-curriculum-duplicator' ),
			),
			'strings'        => array(
				'duplicate'       => __( 'Duplicate', 'tutor-lms-curriculum-duplicator' ),
				'duplicating'     => __( 'Duplicating…', 'tutor-lms-curriculum-duplicator' ),
				'contentSuccess'  => __( 'Duplicated successfully.', 'tutor-lms-curriculum-duplicator' ),
				'topicSuccess'    => __( 'Topic duplicated successfully.', 'tutor-lms-curriculum-duplicator' ),
				'genericError'    => __( 'Duplication failed. Please try again.', 'tutor-lms-curriculum-duplicator' ),
				'networkError'    => __( 'Could not reach the server.', 'tutor-lms-curriculum-duplicator' ),
				'refreshNeeded'   => __( 'Duplicated successfully, but the screen has not updated.', 'tutor-lms-curriculum-duplicator' ),
				'reload'          => __( 'Reload page', 'tutor-lms-curriculum-duplicator' ),
				'staleCurriculum' => __( 'The curriculum data is out of sync. Please reload the page.', 'tutor-lms-curriculum-duplicator' ),
				'duplicateTopic'  => __( 'Duplicate topic with all its content', 'tutor-lms-curriculum-duplicator' ),
				'duplicateLesson' => __( 'Duplicate lesson', 'tutor-lms-curriculum-duplicator' ),
			),
		);

		/**
		 * Filter config ที่ส่งให้ JavaScript
		 *
		 * @param array $config    Config.
		 * @param int   $course_id Course ID.
		 */
		return apply_filters( 'tlcd_builder_config', $config, $course_id );
	}
}
