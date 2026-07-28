/**
 * ชุดทดสอบฝั่ง JavaScript
 *
 * จุดที่เสี่ยงที่สุดของปลั๊กอินนี้คือการจับคู่แถวใน DOM กับข้อมูลจาก REST
 * เพราะ Course Builder ไม่ได้ใส่ post ID ลงใน DOM ถ้าจับคู่ผิดผู้ใช้จะได้
 * สำเนาผิดรายการโดยไม่รู้ตัว ชุดทดสอบนี้จึงเน้นกรณีที่ DOM กับข้อมูลไม่ตรงกัน
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { buttonIds, mountBuilder, wait } from './helpers.mjs';

const DOM_TOPICS = [
	{
		title: 'Topic 1',
		contents: [
			{ title: 'Lesson A', post_type: 'lesson' },
			{ title: 'Quiz 1', post_type: 'tutor_quiz' },
			{ title: 'Zoom Call', post_type: 'tutor_zoom_meeting' },
		],
	},
	{
		title: 'Topic 2',
		contents: [ { title: 'Assignment 1', post_type: 'tutor_assignments' } ],
	},
];

const CURRICULUM = {
	course_id: 10,
	lesson_post_type: 'lesson',
	duplicable_post_types: [ 'lesson', 'tutor_quiz', 'tutor_assignments' ],
	topics: [
		{
			id: 101,
			title: 'Topic 1',
			contents: [
				{ id: 201, title: 'Lesson A', post_type: 'lesson', duplicable: true },
				{ id: 202, title: 'Quiz 1', post_type: 'tutor_quiz', duplicable: true },
				{ id: 203, title: 'Zoom Call', post_type: 'tutor_zoom_meeting', duplicable: false },
			],
		},
		{
			id: 102,
			title: 'Topic 2',
			contents: [
				{ id: 204, title: 'Assignment 1', post_type: 'tutor_assignments', duplicable: true },
			],
		},
	],
};

function clone( value ) {
	return JSON.parse( JSON.stringify( value ) );
}

describe( 'การแทรกปุ่ม', () => {
	it( 'แทรกปุ่มให้ทุกหัวข้อพร้อม id ที่ถูกต้อง', async () => {
		const { window, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum: CURRICULUM,
		} );

		assert.deepEqual( buttonIds( window, 'topic' ), [ '101', '102' ] );
		close();
	} );

	it( 'แทรกปุ่มเฉพาะเนื้อหาชนิดที่รองรับ', async () => {
		const { window, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum: CURRICULUM,
		} );

		assert.deepEqual(
			buttonIds( window, 'content' ),
			[ '201', '202', '204' ],
			'Zoom ยังไม่รองรับ จึงต้องไม่มีปุ่ม'
		);
		close();
	} );

	it( 'ใช้ป้ายกำกับตามชนิดของเนื้อหา', async () => {
		const { window, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum: CURRICULUM,
		} );

		const buttons = [ ...window.document.querySelectorAll( '.tlcd-btn[data-tlcd-type="content"]' ) ];

		assert.equal( buttons[ 0 ].getAttribute( 'aria-label' ), 'Duplicate lesson' );
		assert.equal( buttons[ 1 ].getAttribute( 'aria-label' ), 'Duplicate quiz' );
		assert.equal( buttons[ 2 ].getAttribute( 'aria-label' ), 'Duplicate assignment' );
		close();
	} );

	it( 'วางปุ่มหัวข้อไว้ในกลุ่มปุ่มของหัวข้อ ก่อนปุ่มลบ', async () => {
		const { window, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum: CURRICULUM,
		} );

		const button = window.document.querySelector( '.tlcd-btn[data-tlcd-type="topic"]' );

		assert.equal( button.parentElement.className, 'actions' );
		assert.ok(
			button.nextElementSibling.querySelector( '[data-cy="delete-topic"]' ),
			'ปุ่มต้องอยู่ก่อนปุ่มลบ'
		);
		close();
	} );

	it( 'ไม่แทรกปุ่มซ้ำเมื่อ React re-render', async () => {
		const { window, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum: CURRICULUM,
		} );

		// จำลอง re-render ด้วยการแตะ DOM ให้ MutationObserver ทำงานอีกรอบ
		window.document.querySelector( '.topic-body' ).setAttribute( 'data-touched', '1' );
		await wait( 400 );

		assert.equal( buttonIds( window, 'topic' ).length, 2 );
		assert.equal( buttonIds( window, 'content' ).length, 3 );
		close();
	} );

	it( 'ยังแทรกปุ่มหัวข้อได้เมื่อหัวข้อถูกย่อไว้', async () => {
		const { window, close } = await mountBuilder( {
			domTopics: [
				{ title: 'Topic 1', contents: [] },
				{ title: 'Topic 2', contents: [] },
			],
			curriculum: CURRICULUM,
		} );

		assert.equal( buttonIds( window, 'topic' ).length, 2 );
		assert.equal( buttonIds( window, 'content' ).length, 0 );
		close();
	} );
} );

describe( 'การป้องกันการจับคู่ผิดรายการ', () => {
	it( 'ไม่แทรกปุ่มเมื่อจำนวนหัวข้อไม่ตรงกัน', async () => {
		const curriculum = clone( CURRICULUM );
		curriculum.topics.pop();

		const { window, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum,
		} );

		assert.equal( window.document.querySelectorAll( '.tlcd-btn' ).length, 0 );
		close();
	} );

	it( 'ไม่แทรกปุ่มเมื่อจำนวนเนื้อหาในหัวข้อไม่ตรงกัน', async () => {
		const curriculum = clone( CURRICULUM );
		curriculum.topics[ 0 ].contents.pop();

		const { window, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum,
		} );

		const firstTopicRow = window.document.querySelector( '[data-actions]' );

		assert.equal( firstTopicRow.querySelector( '.tlcd-btn' ), null );
		close();
	} );

	it( 'ไม่แทรกปุ่มเมื่อชื่อรายการไม่ตรงกับข้อมูล', async () => {
		const curriculum = clone( CURRICULUM );
		curriculum.topics[ 0 ].contents[ 0 ].title = 'A completely different lesson';

		const { window, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum,
		} );

		const firstRow = window.document.querySelector( '[data-actions]' );

		assert.equal( firstRow.querySelector( '.tlcd-btn' ), null );
		close();
	} );

	it( 'ไม่แทรกปุ่มเมื่อชนิดของแถวไม่ตรงกับข้อมูล', async () => {
		const curriculum = clone( CURRICULUM );
		curriculum.topics[ 0 ].contents[ 0 ].post_type = 'tutor_quiz';

		const { window, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum,
		} );

		const firstRow = window.document.querySelector( '[data-actions]' );

		assert.equal( firstRow.querySelector( '.tlcd-btn' ), null );
		close();
	} );

	it( 'ไม่แทรกปุ่มเมื่อชื่อหัวข้อไม่ตรงกับข้อมูล', async () => {
		const curriculum = clone( CURRICULUM );
		curriculum.topics[ 0 ].title = 'Renamed elsewhere';
		curriculum.topics[ 1 ].title = 'Also renamed';

		const { window, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum,
		} );

		assert.equal( buttonIds( window, 'topic' ).length, 0 );
		close();
	} );

	it( 'หยุดยิง REST เมื่อจับคู่ไม่ได้ซ้ำ ๆ แทนที่จะวนไม่รู้จบ', async () => {
		const curriculum = clone( CURRICULUM );
		curriculum.topics.pop();

		const { calls, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum,
			settle: 6000,
		} );

		const curriculumCalls = calls.filter( ( call ) => call.url.indexOf( '/curriculum' ) !== -1 );

		assert.ok(
			curriculumCalls.length <= 8,
			`ยิง REST ${ curriculumCalls.length } ครั้ง — ควรหยุดหลังลองไม่เกิน 5 ครั้ง`
		);
		close();
	} );
} );

describe( 'การกดปุ่ม', () => {
	it( 'ยิงไปยัง endpoint ของเนื้อหาด้วย POST', async () => {
		const { window, calls, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum: CURRICULUM,
		} );

		calls.length = 0;
		window.document
			.querySelector( '.tlcd-btn[data-tlcd-type="content"]' )
			.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true } ) );
		await wait( 200 );

		const duplicate = calls.find( ( call ) => call.url.indexOf( '/duplicate' ) !== -1 );

		assert.ok( duplicate, 'ต้องมีคำขอทำสำเนา' );
		assert.equal( duplicate.method, 'POST' );
		assert.ok( duplicate.url.endsWith( '/contents/201/duplicate' ), duplicate.url );
		close();
	} );

	it( 'ยิงไปยัง endpoint ของหัวข้อด้วย POST', async () => {
		const { window, calls, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum: CURRICULUM,
		} );

		calls.length = 0;
		window.document
			.querySelector( '.tlcd-btn[data-tlcd-type="topic"]' )
			.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true } ) );
		await wait( 200 );

		const duplicate = calls.find( ( call ) => call.url.indexOf( '/duplicate' ) !== -1 );

		assert.ok( duplicate.url.endsWith( '/topics/101/duplicate' ), duplicate.url );
		close();
	} );

	it( 'แนบ REST nonce ไปกับคำขอเสมอ', async () => {
		let headers = null;

		const { window, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum: CURRICULUM,
		} );

		const original = window.fetch;
		window.fetch = ( url, opts ) => {
			headers = opts.headers;

			return original( url, opts );
		};

		window.document
			.querySelector( '.tlcd-btn[data-tlcd-type="content"]' )
			.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true } ) );
		await wait( 200 );

		assert.equal( headers[ 'X-WP-Nonce' ], 'test-nonce' );
		close();
	} );

	it( 'ปิดปุ่มระหว่างประมวลผลเพื่อกันการกดซ้ำ', async () => {
		const { window, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum: CURRICULUM,
		} );

		const button = window.document.querySelector( '.tlcd-btn[data-tlcd-type="content"]' );

		button.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true } ) );

		assert.equal( button.disabled, true );
		assert.equal( button.dataset.tlcdBusy, '1' );
		assert.equal( button.getAttribute( 'aria-busy' ), 'true' );

		// ปล่อยให้คำขอทำงานจบก่อนปิดหน้าต่าง ไม่งั้นจะเหลือ promise ค้าง
		await wait( 7500 );
		close();
	} );

	it( 'ยิงคำขอครั้งเดียวแม้จะกดรัว ๆ', async () => {
		const { window, calls, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum: CURRICULUM,
		} );

		calls.length = 0;
		const button = window.document.querySelector( '.tlcd-btn[data-tlcd-type="content"]' );

		for ( let i = 0; i < 5; i++ ) {
			button.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true } ) );
		}

		await wait( 200 );

		const duplicateCalls = calls.filter( ( call ) => call.url.indexOf( '/duplicate' ) !== -1 );

		assert.equal( duplicateCalls.length, 1, 'กด 5 ครั้งต้องยิงคำขอเดียว' );
		close();
	} );

	it( 'เปิดปุ่มกลับมาหลังทำงานเสร็จ', async () => {
		const { window, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum: CURRICULUM,
		} );

		const button = window.document.querySelector( '.tlcd-btn[data-tlcd-type="content"]' );

		button.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true } ) );
		await wait( 7500 );

		assert.equal( button.disabled, false );
		close();
	} );
} );

describe( 'การแจ้งผลและการจัดการข้อผิดพลาด', () => {
	it( 'แสดงข้อความผิดพลาดจากเซิร์ฟเวอร์ ไม่ใช่ข้อความทั่วไป', async () => {
		const { window, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum: CURRICULUM,
			onDuplicate: () => ( {
				ok: false,
				status: 403,
				json: () =>
					Promise.resolve( {
						code: 'tlcd_forbidden',
						message: 'You do not have permission to edit this topic.',
					} ),
			} ),
		} );

		window.document
			.querySelector( '.tlcd-btn[data-tlcd-type="content"]' )
			.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true } ) );
		await wait( 300 );

		const toast = window.document.getElementById( 'tlcd-toast-container' );

		assert.ok( toast, 'ต้องมีกล่องข้อความ' );
		assert.match( toast.textContent, /You do not have permission to edit this topic\./ );
		assert.ok( toast.querySelector( '.tlcd-toast--error' ), 'ต้องเป็นข้อความแบบ error' );
		close();
	} );

	it( 'แจ้งผลสำเร็จเมื่อรายการใหม่ปรากฏบนหน้าจอแล้ว', async () => {
		const { window, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum: CURRICULUM,
			// คืนชื่อที่มีอยู่ใน DOM อยู่แล้ว เพื่อจำลองว่า React วาดรายการใหม่เสร็จ
			onDuplicate: () => ( {
				ok: true,
				status: 201,
				json: () =>
					Promise.resolve( {
						success: true,
						message: 'Lesson duplicated successfully.',
						data: { title: 'Lesson A' },
					} ),
			} ),
		} );

		window.document
			.querySelector( '.tlcd-btn[data-tlcd-type="content"]' )
			.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true } ) );
		await wait( 600 );

		const toast = window.document.getElementById( 'tlcd-toast-container' );

		assert.ok( toast, 'ต้องมีกล่องข้อความ' );
		assert.match( toast.textContent, /Lesson duplicated successfully\./ );
		assert.ok( toast.querySelector( '.tlcd-toast--success' ), 'ต้องเป็นข้อความแบบสำเร็จ' );
		close();
	} );

	it( 'กล่องข้อความต้องประกาศให้โปรแกรมอ่านหน้าจอรับรู้', async () => {
		const { window, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum: CURRICULUM,
			onDuplicate: () => ( {
				ok: false,
				status: 403,
				json: () => Promise.resolve( { code: 'tlcd_forbidden', message: 'Denied.' } ),
			} ),
		} );

		window.document
			.querySelector( '.tlcd-btn[data-tlcd-type="content"]' )
			.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true } ) );
		await wait( 300 );

		const toast = window.document.getElementById( 'tlcd-toast-container' );

		assert.equal( toast.getAttribute( 'role' ), 'status' );
		assert.equal( toast.getAttribute( 'aria-live' ), 'polite' );
		close();
	} );

	it( 'ไม่ล้มทั้งหน้าเมื่อเซิร์ฟเวอร์ตอบไม่ใช่ JSON', async () => {
		const { window, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum: CURRICULUM,
			onDuplicate: () => ( {
				ok: false,
				status: 500,
				json: () => Promise.reject( new Error( 'not json' ) ),
			} ),
		} );

		const button = window.document.querySelector( '.tlcd-btn[data-tlcd-type="content"]' );

		button.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true } ) );
		await wait( 300 );

		const toast = window.document.getElementById( 'tlcd-toast-container' );

		assert.ok( toast.textContent.length > 0, 'ต้องยังแจ้งผู้ใช้ได้' );
		close();
	} );

	it( 'ไม่ทำอะไรเลยเมื่อไม่มี config', async () => {
		const { window, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum: CURRICULUM,
			config: { restUrl: '' },
		} );

		assert.equal( window.document.querySelectorAll( '.tlcd-btn' ).length, 0 );
		close();
	} );

	it( 'ไม่แทรกปุ่มเมื่อดึงข้อมูลหลักสูตรไม่สำเร็จ', async () => {
		const { window, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum: null,
		} );

		assert.equal( window.document.querySelectorAll( '.tlcd-btn' ).length, 0 );
		close();
	} );
} );

describe( 'ความปลอดภัยของการแสดงผล', () => {
	it( 'ไม่ตีความ HTML ในข้อความที่มาจากเซิร์ฟเวอร์', async () => {
		const { window, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum: CURRICULUM,
			onDuplicate: () => ( {
				ok: false,
				status: 400,
				json: () =>
					Promise.resolve( {
						code: 'bad',
						message: '<img src=x onerror="window.__tlcdXss=1">',
					} ),
			} ),
		} );

		window.document
			.querySelector( '.tlcd-btn[data-tlcd-type="content"]' )
			.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true } ) );
		await wait( 300 );

		const toast = window.document.getElementById( 'tlcd-toast-container' );

		assert.equal( toast.querySelector( 'img' ), null, 'ต้องไม่สร้าง element จากข้อความ' );
		assert.equal( window.__tlcdXss, undefined, 'ต้องไม่รันสคริปต์ที่แฝงมา' );
		close();
	} );

	it( 'ไม่ตีความ HTML ในชื่อรายการที่มาจากเซิร์ฟเวอร์', async () => {
		const curriculum = clone( CURRICULUM );
		curriculum.topics[ 0 ].contents[ 0 ].title = '<img src=x onerror="window.__tlcdXss2=1">';

		const { window, close } = await mountBuilder( {
			domTopics: DOM_TOPICS,
			curriculum,
		} );

		assert.equal( window.__tlcdXss2, undefined );
		close();
	} );
} );
