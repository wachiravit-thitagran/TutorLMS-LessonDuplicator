<?php
/**
 * ชุดทดสอบการแปลภาษา
 *
 * ยืนยันสามเรื่องที่พังเงียบได้ง่ายที่สุด
 *   1. ทุกข้อความในซอร์สใช้ text domain ที่ถูกต้อง (พิมพ์ผิดตัวเดียวก็ไม่ถูกแปล)
 *   2. ไฟล์ .mo ที่ commit ไว้โหลดได้จริงและแปลถูก
 *   3. .pot ตรงกับซอร์ส ณ ปัจจุบัน
 *
 * @package TLCD\Tests
 */

use TLCD\Services\Title_Generator;

/**
 * Class Test_I18n
 */
class Test_I18n extends TLCD_TestCase {

	const DOMAIN = 'tutor-lms-curriculum-duplicator';

	/**
	 * โฟลเดอร์รากของปลั๊กอิน
	 *
	 * @return string
	 */
	private function plugin_dir() {
		return dirname( __DIR__ );
	}

	/**
	 * ไฟล์ PHP ทั้งหมดที่เป็นซอร์สของปลั๊กอิน (ไม่รวมชุดทดสอบและเครื่องมือ)
	 *
	 * @return string[]
	 */
	private function source_files() {
		$root  = $this->plugin_dir();
		$files = array( $root . '/tutor-lms-curriculum-duplicator.php', $root . '/uninstall.php' );

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root . '/includes', RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * ทุกการเรียกฟังก์ชันแปลภาษาต้องใช้ text domain ของปลั๊กอินนี้
	 *
	 * @return void
	 */
	public function test_every_string_uses_the_plugin_text_domain() {
		$wrong = array();

		foreach ( $this->source_files() as $file ) {
			$source = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			preg_match_all(
				"/\b(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e|_x|_ex|_n|_nx)\(\s*'(?:[^'\\\\]|\\\\.)*'\s*(?:,\s*'(?:[^'\\\\]|\\\\.)*'\s*)*,\s*'([^']*)'\s*\)/",
				$source,
				$matches
			);

			foreach ( $matches[1] as $domain ) {
				if ( self::DOMAIN !== $domain ) {
					$wrong[] = basename( $file ) . ' → ' . $domain;
				}
			}
		}

		$this->assertSame( array(), $wrong, 'พบ text domain ที่ไม่ถูกต้อง' );
	}

	/**
	 * ต้องไม่มีข้อความที่ลืมห่อด้วยฟังก์ชันแปลภาษา
	 *
	 * ตรวจเฉพาะข้อความที่ส่งเข้า WP_Error เพราะเป็นข้อความที่ผู้ใช้เห็นแน่นอน
	 *
	 * @return void
	 */
	public function test_no_untranslated_error_messages() {
		$untranslated = array();

		foreach ( $this->source_files() as $file ) {
			$source = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			// new WP_Error( 'code', 'ข้อความดิบ' ) — พารามิเตอร์ที่สองต้องไม่ใช่สตริงตรง ๆ.
			preg_match_all(
				"/new WP_Error\(\s*'[^']*'\s*,\s*'([^']+)'/",
				$source,
				$matches
			);

			foreach ( $matches[1] as $message ) {
				$untranslated[] = basename( $file ) . ' → ' . $message;
			}
		}

		$this->assertSame( array(), $untranslated, 'พบข้อความที่ยังไม่ได้ห่อด้วย __()' );
	}

	/**
	 * ข้อความต้นทางต้องเป็นภาษาอังกฤษตามมาตรฐานของ WordPress
	 *
	 * ถ้ามีอักษรไทยหลุดเข้าไปใน msgid แปลว่าลืมแปลจุดนั้น
	 *
	 * @return void
	 */
	public function test_source_strings_are_english() {
		$thai = array();

		foreach ( $this->source_files() as $file ) {
			$source = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			preg_match_all(
				"/\b(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e|_x|_ex|_n|_nx)\(\s*'((?:[^'\\\\]|\\\\.)*)'/",
				$source,
				$matches
			);

			foreach ( $matches[1] as $msgid ) {
				if ( preg_match( '/[\x{0E00}-\x{0E7F}]/u', $msgid ) ) {
					$thai[] = basename( $file ) . ' → ' . $msgid;
				}
			}
		}

		$this->assertSame( array(), $thai, 'msgid ต้องเป็นภาษาอังกฤษ ส่วนภาษาไทยอยู่ในไฟล์ .po' );
	}

	/**
	 * ไฟล์แปลภาษาไทยต้องมีอยู่จริงและ commit ไว้
	 *
	 * @return void
	 */
	public function test_thai_translation_files_exist() {
		$languages = $this->plugin_dir() . '/languages/';

		$this->assertFileExists( $languages . self::DOMAIN . '.pot' );
		$this->assertFileExists( $languages . self::DOMAIN . '-th.po' );
		$this->assertFileExists( $languages . self::DOMAIN . '-th.mo', 'ต้อง commit ไฟล์ .mo ด้วย เพราะ WordPress อ่านเฉพาะ .mo' );
	}

	/**
	 * ไฟล์ .mo ต้องเป็น binary ที่ WordPress อ่านได้
	 *
	 * @return void
	 */
	public function test_thai_mo_file_is_valid() {
		$mo_path = $this->plugin_dir() . '/languages/' . self::DOMAIN . '-th.mo';

		require_once ABSPATH . WPINC . '/pomo/mo.php';

		$mo = new MO();

		$this->assertTrue( $mo->import_from_file( $mo_path ), 'WordPress ต้องอ่านไฟล์ .mo นี้ได้' );
		$this->assertNotEmpty( $mo->entries, 'ไฟล์ .mo ต้องมีข้อความอยู่ข้างใน' );
		$this->assertGreaterThanOrEqual( 60, count( $mo->entries ), 'จำนวนข้อความที่แปลน้อยผิดปกติ' );
	}

	/**
	 * เมื่อโหลดไฟล์แปลแล้ว ข้อความต้องออกมาเป็นภาษาไทยจริง
	 *
	 * @return void
	 */
	public function test_thai_translation_is_applied() {
		$this->load_thai();

		$this->assertSame(
			'ทำสำเนาบทเรียน',
			__( 'Duplicate lesson', 'tutor-lms-curriculum-duplicator' )
		);
		$this->assertSame(
			'คุณไม่มีสิทธิ์แก้ไขหัวข้อนี้',
			__( 'You do not have permission to edit this topic.', 'tutor-lms-curriculum-duplicator' )
		);
		$this->assertSame(
			'– สำเนา',
			__( '– Copy', 'tutor-lms-curriculum-duplicator' )
		);

		$this->unload_thai();
	}

	/**
	 * ชื่อสำเนาต้องเปลี่ยนตามภาษาที่ผู้ใช้ตั้งไว้
	 *
	 * นี่คือจุดที่ผู้ใช้เห็นผลของการแปลชัดที่สุด
	 *
	 * @return void
	 */
	public function test_copy_suffix_follows_locale() {
		$this->assertSame( '– Copy', Title_Generator::suffix() );

		$this->load_thai();
		$this->assertSame( '– สำเนา', Title_Generator::suffix() );

		$course_id = TLCD_Seeder::course();
		$topic_id  = TLCD_Seeder::topic( $course_id, array( 'order' => 1 ) );
		$lesson_id = TLCD_Seeder::lesson( $topic_id, array( 'title' => 'บทเรียนทดสอบ' ) );

		$new_id = ( new \TLCD\Services\Lesson_Duplicator() )->duplicate( $lesson_id, $topic_id );

		$this->assertSame( 'บทเรียนทดสอบ – สำเนา', get_the_title( $new_id ) );

		$this->unload_thai();
	}

	/**
	 * ต้องตัด suffix ภาษาไทยออกได้ เมื่อทำสำเนาซ้ำในเว็บภาษาไทย
	 *
	 * @return void
	 */
	public function test_thai_suffix_is_stripped_on_repeat() {
		$this->load_thai();

		$this->assertSame( 'บทเรียน', Title_Generator::base_title( 'บทเรียน – สำเนา' ) );
		$this->assertSame( 'บทเรียน', Title_Generator::base_title( 'บทเรียน – สำเนา 3' ) );
		$this->assertSame( 'บทเรียน – สำเนา 2', Title_Generator::compose( 'บทเรียน', 2 ) );

		$this->unload_thai();
	}

	/**
	 * ทุกข้อความในซอร์สต้องมีคำแปลภาษาไทยครบ
	 *
	 * @return void
	 */
	public function test_thai_catalog_covers_every_source_string() {
		require_once ABSPATH . WPINC . '/pomo/po.php';

		$po = new PO();
		$po->import_from_file( $this->plugin_dir() . '/languages/' . self::DOMAIN . '-th.po' );

		$translated = array();
		foreach ( $po->entries as $entry ) {
			if ( '' !== $entry->singular && '' !== implode( '', $entry->translations ) ) {
				$translated[] = $entry->singular;
			}
		}

		$missing = array();

		foreach ( $this->source_files() as $file ) {
			$source = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			preg_match_all(
				"/\b(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e|_x|_ex|_n|_nx)\(\s*'((?:[^'\\\\]|\\\\.)*)'/",
				$source,
				$matches
			);

			foreach ( $matches[1] as $msgid ) {
				$msgid = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $msgid );

				if ( '' !== trim( $msgid ) && ! in_array( $msgid, $translated, true ) ) {
					$missing[] = $msgid;
				}
			}
		}

		$this->assertSame(
			array(),
			array_values( array_unique( $missing ) ),
			'ยังมีข้อความที่ไม่มีคำแปลภาษาไทย — รัน `python3 bin/make-pot.py` แล้วเติมคำแปล'
		);
	}

	/**
	 * placeholder ของคำแปลต้องตรงกับต้นฉบับ มิฉะนั้น sprintf จะพัง
	 *
	 * @return void
	 */
	public function test_translations_keep_the_same_placeholders() {
		require_once ABSPATH . WPINC . '/pomo/po.php';

		$po = new PO();
		$po->import_from_file( $this->plugin_dir() . '/languages/' . self::DOMAIN . '-th.po' );

		$broken = array();

		foreach ( $po->entries as $entry ) {
			$translation = isset( $entry->translations[0] ) ? $entry->translations[0] : '';

			if ( '' === $entry->singular || '' === $translation ) {
				continue;
			}

			preg_match_all( '/%[0-9]*\$?[sd]/', $entry->singular, $source_tokens );
			preg_match_all( '/%[0-9]*\$?[sd]/', $translation, $target_tokens );

			sort( $source_tokens[0] );
			sort( $target_tokens[0] );

			if ( $source_tokens[0] !== $target_tokens[0] ) {
				$broken[] = $entry->singular;
			}
		}

		$this->assertSame( array(), $broken, 'คำแปลมี placeholder ไม่ตรงกับต้นฉบับ' );
	}

	/**
	 * ปลั๊กอินต้องลงทะเบียนโหลดไฟล์แปลภาษา
	 *
	 * @return void
	 */
	public function test_plugin_registers_textdomain_loading() {
		$this->assertNotFalse(
			has_action( 'init', array( tlcd(), 'load_textdomain' ) ),
			'ต้อง hook load_plugin_textdomain ไว้ที่ init'
		);
	}

	/**
	 * โหลดแคตตาล็อกภาษาไทยเข้า WordPress
	 *
	 * @return void
	 */
	private function load_thai() {
		unload_textdomain( self::DOMAIN );

		load_textdomain(
			self::DOMAIN,
			$this->plugin_dir() . '/languages/' . self::DOMAIN . '-th.mo'
		);
	}

	/**
	 * เอาแคตตาล็อกภาษาไทยออก เพื่อไม่ให้กระทบเทสต์อื่น
	 *
	 * @return void
	 */
	private function unload_thai() {
		unload_textdomain( self::DOMAIN );
	}

	/**
	 * คืนสถานะภาษาเดิมเสมอ
	 *
	 * @return void
	 */
	public function tear_down() {
		unload_textdomain( self::DOMAIN );

		parent::tear_down();
	}
}
