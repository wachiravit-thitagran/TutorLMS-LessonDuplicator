/**
 * ตัวช่วยสำหรับชุดทดสอบ JavaScript
 *
 * สร้าง DOM ที่จำลองโครงสร้างจริงของ Course Builder ใน Tutor LMS 4.x
 * (อ้างอิงจาก TopicHeader.tsx และ TopicContent.tsx) แล้วรันสคริปต์ของปลั๊กอินลงไป
 *
 * โครงสร้างที่จำลอง
 *   [aria-roledescription="sortable"]      ขอบเขตของหัวข้อ (dnd-kit)
 *   ├── .actions                            กลุ่มปุ่มของหัวข้อ (edit / delete)
 *   └── .content-row                        แถวเนื้อหาแต่ละรายการ
 *       ├── p                               ชื่อรายการ
 *       └── [data-actions]                  กลุ่มปุ่มของรายการ
 */

import { JSDOM } from 'jsdom';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const SCRIPT_PATH = path.resolve( HERE, '../../assets/js/curriculum-duplicator.js' );

export const SCRIPT = fs.readFileSync( SCRIPT_PATH, 'utf8' );

export const DEFAULT_CONFIG = {
	restUrl: 'https://example.test/wp-json/tlcd/v1',
	nonce: 'test-nonce',
	courseId: 10,
	lessonPostType: 'lesson',
	debug: false,
	tutorVersion: '4.0.2',
	testedVersion: '4.0.2',
	untested: false,
	selectors: {
		topicDeleteButton: 'button[data-cy="delete-topic"]',
		topicEditButton: 'button[data-cy="edit-topic"]',
		sortableAncestor: '[aria-roledescription="sortable"]',
		contentActions: '[data-actions]',
		contentDeleteButton: '[data-actions] button[data-cy^="delete-"]',
		contentTitle: 'p',
		topicTitle: '',
	},
	contentLabels: {
		lesson: 'Duplicate lesson',
		tutor_quiz: 'Duplicate quiz',
		tutor_assignments: 'Duplicate assignment',
	},
	strings: {
		duplicate: 'Duplicate',
		duplicating: 'Duplicating…',
		contentSuccess: 'Duplicated successfully.',
		topicSuccess: 'Topic duplicated successfully.',
		genericError: 'Duplication failed. Please try again.',
		networkError: 'Could not reach the server.',
		refreshNeeded: 'Duplicated successfully, but the screen has not updated.',
		reload: 'Reload page',
		staleCurriculum: 'The curriculum data is out of sync. Please reload the page.',
		duplicateTopic: 'Duplicate topic with all its content',
		duplicateLesson: 'Duplicate lesson',
	},
};

/**
 * สร้าง HTML ของหัวข้อหนึ่งหัวข้อ
 *
 * @param {{title: string, contents: Array<{title: string, post_type: string}>}} topic ข้อมูลหัวข้อ
 * @return {string} HTML
 */
export function topicHtml( topic ) {
	const rows = topic.contents
		.map(
			( content ) => `
	<div class="content-row">
		<div class="icon-and-title"><p>${ content.title }</p></div>
		<div data-actions>
			<button data-cy="edit-${ content.post_type }">edit</button>
			<button data-cy="delete-${ content.post_type }">delete</button>
		</div>
	</div>`
		)
		.join( '' );

	return `
<div aria-roledescription="sortable">
	<div class="topic-header">
		<div class="topic-title">${ topic.title }</div>
		<div class="actions">
			<span><button data-cy="edit-topic">edit</button></span>
			<span><button data-cy="delete-topic">delete</button></span>
		</div>
	</div>
	<div class="topic-body">${ rows }</div>
</div>`;
}

/**
 * เตรียมหน้าจอจำลองแล้วรันสคริปต์ของปลั๊กอิน
 *
 * @param {Object}   options              ตัวเลือก
 * @param {Array}    options.domTopics    หัวข้อที่จะ render ลง DOM
 * @param {Object}   options.curriculum   ข้อมูลที่ REST จะตอบกลับ
 * @param {Function} [options.onDuplicate] callback เมื่อมีการยิงคำขอทำสำเนา
 * @param {Object}   [options.config]      config เพิ่มเติม
 * @param {number}   [options.settle]      เวลารอให้สคริปต์ทำงาน (ms)
 *
 * @return {Promise<{window: Window, calls: Array, close: Function}>} หน้าจอที่พร้อมตรวจ
 */
export async function mountBuilder( options ) {
	const {
		domTopics = [],
		curriculum = null,
		onDuplicate = null,
		config = {},
		settle = 500,
	} = options;

	const dom = new JSDOM(
		`<!DOCTYPE html><html><body><div id="tutor-course-builder">${ domTopics
			.map( topicHtml )
			.join( '' ) }</div></body></html>`,
		{
			url: 'https://example.test/wp-admin/admin.php?page=create-course&course_id=10',
			runScripts: 'outside-only',
		}
	);

	const { window } = dom;
	const calls = [];

	window.fetch = ( url, opts ) => {
		const method = ( opts && opts.method ) || 'GET';
		calls.push( { url: String( url ), method, body: opts && opts.body } );

		if ( String( url ).indexOf( '/curriculum' ) !== -1 ) {
			if ( ! curriculum ) {
				return Promise.resolve( {
					ok: false,
					status: 404,
					json: () => Promise.resolve( { code: 'not_found', message: 'not found' } ),
				} );
			}

			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( { success: true, data: curriculum } ),
			} );
		}

		if ( onDuplicate ) {
			return Promise.resolve( onDuplicate( String( url ) ) );
		}

		return Promise.resolve( {
			ok: true,
			status: 201,
			json: () =>
				Promise.resolve( {
					success: true,
					message: 'Duplicated successfully.',
					data: { title: 'A copy' },
				} ),
		} );
	};

	window.tlcdConfig = {
		...DEFAULT_CONFIG,
		...config,
		selectors: { ...DEFAULT_CONFIG.selectors, ...( config.selectors || {} ) },
		strings: { ...DEFAULT_CONFIG.strings, ...( config.strings || {} ) },
	};

	window.eval( SCRIPT );

	await wait( settle );

	return {
		window,
		calls,
		close: () => dom.window.close(),
	};
}

/**
 * รอตามเวลาที่กำหนด
 *
 * @param {number} ms มิลลิวินาที
 * @return {Promise<void>} promise
 */
export function wait( ms ) {
	return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
}

/**
 * อ่าน id ของปุ่มที่แทรกไว้ตามชนิด
 *
 * @param {Window} window  หน้าต่างจำลอง
 * @param {string} type    topic|content
 * @return {string[]} รายการ id
 */
export function buttonIds( window, type ) {
	return [ ...window.document.querySelectorAll( `.tlcd-btn[data-tlcd-type="${ type }"]` ) ].map(
		( button ) => button.dataset.tlcdId
	);
}
