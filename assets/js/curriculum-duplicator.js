/**
 * Tutor LMS Curriculum Duplicator
 *
 * แทรกปุ่ม "ทำสำเนา" เข้าไปในแถว Topic และ Lesson ของ Course Builder (React)
 *
 * หลักการทำงาน
 * 1) ดึงโครงสร้างหลักสูตร (topic/lesson id + ลำดับ) จาก REST endpoint ของปลั๊กอินเอง
 * 2) จับคู่แถวใน DOM กับข้อมูลด้วย "ลำดับในเอกสาร" แล้วตรวจทานด้วยจำนวนแถว
 *    เพื่อไม่ต้องพึ่ง class name ที่ Emotion generate ใหม่ทุก build
 * 3) หลังทำสำเนาสำเร็จ กระตุ้นให้ React Query ดึงข้อมูลใหม่ ถ้าไม่สำเร็จจึงเสนอให้โหลดหน้าใหม่
 *
 * @package TLCD
 */
( function () {
	'use strict';

	var config = window.tlcdConfig;

	if ( ! config || ! config.restUrl ) {
		return;
	}

	var SELECTORS = config.selectors || {};
	var STRINGS = config.strings || {};

	var MAX_MISMATCH_RETRIES = 5;

	var state = {
		curriculum: null,
		fetching: null,
		stale: true,
		mismatchCount: 0,
		givenUp: false,
		observer: null,
		enhanceTimer: null
	};

	var COPY_ICON =
		'<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">' +
		'<rect x="9" y="9" width="11" height="11" rx="2" stroke="currentColor" stroke-width="1.7"/>' +
		'<path d="M15 5.5A2.5 2.5 0 0 0 12.5 3H6.5A3.5 3.5 0 0 0 3 6.5v6A2.5 2.5 0 0 0 5.5 15"' +
		' stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>' +
		'</svg>';

	var SPINNER = '<span class="tlcd-spinner" aria-hidden="true"></span>';

	/* ------------------------------------------------------------------ */
	/* Utilities                                                           */
	/* ------------------------------------------------------------------ */

	function log() {
		if ( config.debug && window.console ) {
			var args = Array.prototype.slice.call( arguments );
			args.unshift( '[TLCD]' );
			window.console.log.apply( window.console, args );
		}
	}

	function qsa( selector, scope ) {
		if ( ! selector ) {
			return [];
		}

		return Array.prototype.slice.call( ( scope || document ).querySelectorAll( selector ) );
	}

	function getCourseId() {
		try {
			var fromUrl = new URLSearchParams( window.location.search ).get( 'course_id' );

			if ( fromUrl && parseInt( fromUrl, 10 ) > 0 ) {
				return parseInt( fromUrl, 10 );
			}
		} catch ( e ) {
			// ไม่ทำอะไร ใช้ค่าจาก config แทน.
		}

		return parseInt( config.courseId, 10 ) || 0;
	}

	function normalizeTitle( value ) {
		return String( value || '' )
			.replace( /\s+/g, ' ' )
			.trim()
			.toLowerCase();
	}

	/**
	 * ข้อความบนปุ่มตามชนิดเนื้อหา
	 */
	function labelFor( postType ) {
		var labels = config.contentLabels || {};

		return labels[ postType ] || STRINGS.duplicateLesson || STRINGS.duplicate;
	}

	/**
	 * บรรพบุรุษร่วมที่ใกล้ที่สุดของสอง element
	 */
	function commonAncestor( a, b ) {
		if ( ! a || ! b ) {
			return null;
		}

		var node = a;

		while ( node ) {
			if ( node.contains( b ) ) {
				return node;
			}

			node = node.parentElement;
		}

		return null;
	}

	/**
	 * แทรก node ก่อน reference โดยยกไปที่ระดับลูกโดยตรงของ container
	 */
	function insertBeforeChild( container, node, reference ) {
		var anchor = reference;

		while ( anchor && anchor.parentElement !== container ) {
			anchor = anchor.parentElement;
		}

		container.insertBefore( node, anchor || null );
	}

	/* ------------------------------------------------------------------ */
	/* REST                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * ประกอบ URL ให้รองรับทั้ง pretty permalink และ ?rest_route=
	 */
	function buildUrl( path ) {
		var base = String( config.restUrl );

		if ( base.indexOf( 'rest_route=' ) !== -1 ) {
			return base + encodeURIComponent( path );
		}

		return base.replace( /\/+$/, '' ) + path;
	}

	function request( path, options ) {
		var settings = options || {};

		return window
			.fetch( buildUrl( path ), {
				method: settings.method || 'GET',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce
				},
				body: settings.body ? JSON.stringify( settings.body ) : undefined
			} )
			.then( function ( response ) {
				return response
					.json()
					.catch( function () {
						return {};
					} )
					.then( function ( payload ) {
						if ( ! response.ok ) {
							var error = new Error( payload.message || STRINGS.genericError );
							error.code = payload.code;
							error.status = response.status;
							throw error;
						}

						return payload;
					} );
			} );
	}

	function fetchCurriculum( force ) {
		var courseId = getCourseId();

		if ( ! courseId ) {
			return Promise.resolve( null );
		}

		if ( state.fetching ) {
			return state.fetching;
		}

		if ( ! force && state.curriculum && ! state.stale ) {
			return Promise.resolve( state.curriculum );
		}

		state.fetching = request( '/courses/' + courseId + '/curriculum' )
			.then( function ( payload ) {
				state.curriculum = payload && payload.data ? payload.data : null;
				state.stale = false;
				state.fetching = null;

				return state.curriculum;
			} )
			.catch( function ( error ) {
				log( 'ดึงข้อมูลหลักสูตรไม่สำเร็จ', error );
				state.fetching = null;

				return null;
			} );

		return state.fetching;
	}

	/* ------------------------------------------------------------------ */
	/* Toast                                                               */
	/* ------------------------------------------------------------------ */

	function toast( message, type, action ) {
		var container = document.getElementById( 'tlcd-toast-container' );

		if ( ! container ) {
			container = document.createElement( 'div' );
			container.id = 'tlcd-toast-container';
			// ให้โปรแกรมอ่านหน้าจอประกาศผลลัพธ์ด้วย ไม่ใช่เห็นได้เฉพาะด้วยตา.
			container.setAttribute( 'role', 'status' );
			container.setAttribute( 'aria-live', 'polite' );
			container.setAttribute( 'aria-atomic', 'false' );
			document.body.appendChild( container );
		}

		var item = document.createElement( 'div' );
		item.className = 'tlcd-toast tlcd-toast--' + ( type || 'success' );

		var text = document.createElement( 'span' );
		text.textContent = message;
		item.appendChild( text );

		if ( action ) {
			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'tlcd-toast__action';
			button.textContent = action.label;
			button.addEventListener( 'click', action.onClick );
			item.appendChild( button );
		}

		container.appendChild( item );

		window.setTimeout( function () {
			if ( item.parentElement ) {
				item.parentElement.removeChild( item );
			}
		}, action ? 12000 : 4000 );
	}

	/* ------------------------------------------------------------------ */
	/* Refresh                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * กระตุ้นให้ React Query (TanStack) ดึงข้อมูล curriculum ใหม่
	 *
	 * focusManager ของ TanStack Query ฟัง event 'visibilitychange' อยู่แล้ว
	 * การยิง event เองจึงทำให้ query ที่ stale ถูก refetch โดยไม่ต้องโหลดหน้าใหม่
	 */
	function nudgeRefetch() {
		try {
			document.dispatchEvent( new Event( 'visibilitychange' ) );
			window.dispatchEvent( new Event( 'visibilitychange' ) );
			window.dispatchEvent( new Event( 'focus' ) );
		} catch ( e ) {
			log( 'nudgeRefetch ล้มเหลว', e );
		}
	}

	/**
	 * รอจนกว่าชื่อที่คาดหวังจะปรากฏใน DOM
	 */
	function waitForTitle( expected, timeout ) {
		var deadline = Date.now() + ( timeout || 6000 );
		var needle = normalizeTitle( expected );

		return new Promise( function ( resolve ) {
			function check() {
				var root = document.getElementById( 'tutor-course-builder' ) || document.body;

				if ( needle && normalizeTitle( root.textContent ).indexOf( needle ) !== -1 ) {
					resolve( true );

					return;
				}

				if ( Date.now() > deadline ) {
					resolve( false );

					return;
				}

				window.setTimeout( check, 300 );
			}

			check();
		} );
	}

	function afterDuplicate( payload, successMessage ) {
		var newTitle = payload && payload.data ? payload.data.title : '';

		state.stale = true;

		return fetchCurriculum( true )
			.then( function () {
				nudgeRefetch();

				return waitForTitle( newTitle );
			} )
			.then( function ( found ) {
				if ( found ) {
					toast( successMessage, 'success' );
					scheduleEnhance();

					return;
				}

				toast( STRINGS.refreshNeeded, 'warning', {
					label: STRINGS.reload,
					onClick: function () {
						window.location.reload();
					}
				} );
			} );
	}

	/* ------------------------------------------------------------------ */
	/* Duplicate actions                                                   */
	/* ------------------------------------------------------------------ */

	function setBusy( button, busy ) {
		if ( busy ) {
			button.dataset.tlcdBusy = '1';
			button.disabled = true;
			button.innerHTML = SPINNER;
			button.setAttribute( 'aria-busy', 'true' );
			button.setAttribute( 'aria-label', STRINGS.duplicating );
		} else {
			delete button.dataset.tlcdBusy;
			button.disabled = false;
			button.innerHTML = COPY_ICON;
			button.removeAttribute( 'aria-busy' );
			button.setAttribute( 'aria-label', button.dataset.tlcdLabel || STRINGS.duplicate );
		}
	}

	function handleClick( event ) {
		event.preventDefault();
		event.stopPropagation();

		var button = event.currentTarget;

		if ( button.dataset.tlcdBusy === '1' ) {
			return;
		}

		var type = button.dataset.tlcdType;
		var id = parseInt( button.dataset.tlcdId, 10 );

		if ( ! id || ! type ) {
			return;
		}

		var endpoint = 'topic' === type ? '/topics/' + id + '/duplicate' : '/contents/' + id + '/duplicate';
		var message = 'topic' === type ? STRINGS.topicSuccess : STRINGS.contentSuccess;

		setBusy( button, true );

		request( endpoint, { method: 'POST', body: {} } )
			.then( function ( payload ) {
				return afterDuplicate( payload, payload.message || message );
			} )
			.catch( function ( error ) {
				log( 'ทำสำเนาไม่สำเร็จ', error );
				toast( error.message || STRINGS.networkError, 'error' );

				// ข้อมูลอาจไม่ตรงแล้ว จึงบังคับดึงใหม่.
				state.stale = true;
				fetchCurriculum( true ).then( scheduleEnhance );
			} )
			.then( function () {
				setBusy( button, false );
			} );
	}

	/* ------------------------------------------------------------------ */
	/* Button injection                                                    */
	/* ------------------------------------------------------------------ */

	function createButton( type, id, label ) {
		var button = document.createElement( 'button' );

		button.type = 'button';
		button.className = 'tlcd-btn tlcd-btn--' + type;
		button.innerHTML = COPY_ICON;
		button.dataset.tlcdType = type;
		button.dataset.tlcdId = String( id );
		button.dataset.tlcdLabel = label;
		button.title = label;
		button.setAttribute( 'aria-label', label );
		button.addEventListener( 'click', handleClick );

		return button;
	}

	/**
	 * หาปุ่มของเราใน container โดยจำกัดเฉพาะชนิดที่ตรงกัน
	 *
	 * ต้องระบุ type ด้วย เพราะ container ของหัวข้ออาจครอบแถวเนื้อหาอยู่ในบาง layout
	 * ถ้าไม่ตรวจชนิด ปุ่มของบทเรียนจะถูกเขียนทับด้วย id ของหัวข้อ แล้วผู้ใช้จะกด
	 * "ทำสำเนาบทเรียน" แต่ได้สำเนาทั้งหัวข้อแทน
	 */
	function findOwnButton( container, type ) {
		var buttons = qsa( '.tlcd-btn[data-tlcd-type="' + type + '"]', container );

		for ( var i = 0; i < buttons.length; i++ ) {
			if ( buttons[ i ].parentElement === container ) {
				return buttons[ i ];
			}
		}

		return null;
	}

	function ensureButton( container, reference, type, id, label ) {
		if ( ! container || ! reference || ! id ) {
			return;
		}

		var existing = findOwnButton( container, type );

		if ( existing ) {
			// React อาจ re-render แถวเดิมด้วยข้อมูลใหม่ — อัปเดต id ให้ตรง.
			if ( existing.dataset.tlcdId !== String( id ) ) {
				existing.dataset.tlcdId = String( id );
			}

			if ( label && existing.dataset.tlcdLabel !== label ) {
				existing.dataset.tlcdLabel = label;
				existing.title = label;
				existing.setAttribute( 'aria-label', label );
			}

			return;
		}

		insertBeforeChild( container, createButton( type, id, label ), reference );
	}

	function removeButton( container, type ) {
		if ( ! container ) {
			return;
		}

		var existing = findOwnButton( container, type );

		if ( existing && existing.parentElement ) {
			existing.parentElement.removeChild( existing );
		}
	}

	/**
	 * หา element ที่ห่อกลุ่มปุ่มของหัวข้อ
	 */
	function findTopicActions( wrapper, deleteButton ) {
		var editButton = wrapper.querySelector( SELECTORS.topicEditButton );

		if ( editButton ) {
			var ancestor = commonAncestor( editButton, deleteButton );

			if ( ancestor && ancestor !== editButton && ancestor !== deleteButton ) {
				return ancestor;
			}
		}

		return deleteButton.parentElement;
	}

	/**
	 * ชื่อในแถว DOM ตรงกับชื่อในข้อมูลหรือไม่
	 *
	 * การจับคู่ด้วยลำดับอย่างเดียวไม่ปลอดภัยพอ ถ้า DOM กับข้อมูลเหลื่อมกันแม้แถวเดียว
	 * ปุ่มจะผูกกับ id ของรายการอื่น แล้วผู้ใช้จะได้สำเนาผิดรายการโดยไม่รู้ตัว
	 * จึงตรวจชื่อซ้ำอีกชั้นก่อนแทรกปุ่มเสมอ
	 */
	function titleMatches( rowNode, expectedTitle, titleSelector ) {
		if ( ! rowNode || ! expectedTitle ) {
			// ไม่มีชื่อให้เทียบ (เช่น รายการยังไม่ได้ตั้งชื่อ) — ไม่ถือว่าผิด.
			return true;
		}

		var titleNode = titleSelector ? rowNode.querySelector( titleSelector ) : null;
		var haystack = normalizeTitle( titleNode ? titleNode.textContent : rowNode.textContent );

		return haystack.indexOf( normalizeTitle( expectedTitle ) ) !== -1;
	}

	function enhance() {
		if ( state.givenUp || ! state.curriculum || ! state.curriculum.topics ) {
			return;
		}

		var allMatched = true;
		var topics = state.curriculum.topics;
		var duplicable = state.curriculum.duplicable_post_types ||
			[ state.curriculum.lesson_post_type || config.lessonPostType ];

		var topicDeleteButtons = qsa( SELECTORS.topicDeleteButton );
		var wrappers = [];

		topicDeleteButtons.forEach( function ( button ) {
			var wrapper =
				button.closest( SELECTORS.sortableAncestor ) ||
				button.closest( 'div[tabindex="-1"]' );

			if ( wrapper && wrappers.indexOf( wrapper ) === -1 ) {
				wrappers.push( { node: wrapper, deleteButton: button } );
			}
		} );

		if ( ! wrappers.length ) {
			return;
		}

		// จำนวนหัวข้อใน DOM ต้องตรงกับข้อมูล มิฉะนั้นการจับคู่ตามลำดับจะผิด.
		if ( wrappers.length !== topics.length ) {
			log( 'จำนวนหัวข้อไม่ตรงกัน', wrappers.length, topics.length );
			markStaleAndRefetch();

			return;
		}

		wrappers.forEach( function ( entry, topicIndex ) {
			var topic = topics[ topicIndex ];

			if ( ! topic ) {
				return;
			}

			var actions = findTopicActions( entry.node, entry.deleteButton );

			if ( ! titleMatches( entry.node, topic.title, SELECTORS.topicTitle ) ) {
				log( 'ชื่อหัวข้อไม่ตรงกับข้อมูล', topic.id, topic.title );
				removeButton( actions, 'topic' );
				allMatched = false;
				markStaleAndRefetch();

				return;
			}

			ensureButton( actions, entry.deleteButton, 'topic', topic.id, STRINGS.duplicateTopic );

			var contentButtons = qsa( SELECTORS.contentDeleteButton, entry.node ).filter( function ( button ) {
				// ปุ่มลบของหัวข้อไม่ได้อยู่ใน [data-actions] อยู่แล้ว แต่กันไว้อีกชั้น.
				return button !== entry.deleteButton;
			} );

			if ( ! contentButtons.length ) {
				return; // หัวข้อถูกย่อไว้ หรือยังไม่มีเนื้อหา.
			}

			if ( contentButtons.length !== topic.contents.length ) {
				log( 'จำนวนเนื้อหาในหัวข้อไม่ตรงกัน', topic.id, contentButtons.length, topic.contents.length );
				allMatched = false;
				markStaleAndRefetch();

				return;
			}

			contentButtons.forEach( function ( button, contentIndex ) {
				var content = topic.contents[ contentIndex ];
				var actionsBar = button.closest( SELECTORS.contentActions );

				if ( ! content ) {
					removeButton( actionsBar, 'content' );

					return;
				}

				// data-cy ของแถวคือ "delete-<post_type>" — ใช้ยืนยันว่าชนิดตรงกับข้อมูล.
				var rowType = String( button.getAttribute( 'data-cy' ) || '' ).replace( /^delete-/, '' );
				var typeMatches = ! rowType || rowType === content.post_type;
				var canDuplicate = content.duplicable !== false &&
					duplicable.indexOf( content.post_type ) !== -1;

				if ( ! canDuplicate || ! typeMatches ) {
					// ชนิดนี้ยังไม่รองรับ หรือแถวนี้ไม่ตรงกับข้อมูล —
					// ต้องเก็บปุ่มเก่าออก ไม่ให้ค้างอยู่กับ id ที่ไม่ตรงแล้ว.
					removeButton( actionsBar, 'content' );

					if ( ! typeMatches ) {
						allMatched = false;
						markStaleAndRefetch();
					}

					return;
				}

				var row = actionsBar ? actionsBar.parentElement : null;

				if ( ! titleMatches( row, content.title, SELECTORS.contentTitle ) ) {
					log( 'ชื่อรายการไม่ตรงกับข้อมูล', topic.id, content.id, content.title );
					removeButton( actionsBar, 'content' );
					allMatched = false;
					markStaleAndRefetch();

					return;
				}

				ensureButton( actionsBar, button, 'content', content.id, labelFor( content.post_type ) );
			} );
		} );

		if ( allMatched ) {
			state.mismatchCount = 0;
		}
	}

	var refetchTimer = null;

	/**
	 * โครงสร้างใน DOM ไม่ตรงกับข้อมูลที่มี — ดึงใหม่แล้วลองอีกครั้ง
	 *
	 * มีการจำกัดจำนวนครั้ง เพื่อไม่ให้วนยิง REST ไม่รู้จบกรณีโครงสร้าง
	 * Course Builder เปลี่ยนไปจนจับคู่ไม่ได้จริง ๆ
	 */
	function markStaleAndRefetch() {
		state.stale = true;
		state.mismatchCount += 1;

		if ( state.mismatchCount > MAX_MISMATCH_RETRIES ) {
			if ( ! state.givenUp ) {
				state.givenUp = true;

				if ( state.observer ) {
					state.observer.disconnect();
				}

				log( 'จับคู่โครงสร้างหลักสูตรไม่สำเร็จ — หยุดแทรกปุ่ม' );
				toast( STRINGS.staleCurriculum, 'warning', {
					label: STRINGS.reload,
					onClick: function () {
						window.location.reload();
					}
				} );
			}

			return;
		}

		if ( refetchTimer ) {
			return;
		}

		refetchTimer = window.setTimeout( function () {
			refetchTimer = null;
			fetchCurriculum( true ).then( function ( data ) {
				if ( data ) {
					scheduleEnhance();
				}
			} );
		}, 500 );
	}

	function runEnhance() {
		if ( state.observer ) {
			state.observer.disconnect();
		}

		try {
			enhance();
		} catch ( error ) {
			log( 'enhance ล้มเหลว', error );
		}

		if ( state.observer ) {
			state.observer.observe( document.body, { childList: true, subtree: true } );
		}
	}

	function scheduleEnhance() {
		if ( state.enhanceTimer ) {
			window.clearTimeout( state.enhanceTimer );
		}

		state.enhanceTimer = window.setTimeout( runEnhance, 150 );
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                                */
	/* ------------------------------------------------------------------ */

	function init() {
		if ( config.untested && window.console && window.console.warn ) {
			window.console.warn(
				'[TLCD] Tutor LMS ' +
					config.tutorVersion +
					' ใหม่กว่าเวอร์ชันที่ปลั๊กอินตรวจสอบไว้ (' +
					config.testedVersion +
					') — ปุ่มทำสำเนายังทำงาน แต่ควรทดสอบบน Staging ก่อน'
			);
		}

		if ( ! getCourseId() ) {
			log( 'ไม่พบ course_id — ข้ามการทำงาน' );

			return;
		}

		fetchCurriculum( true ).then( function ( data ) {
			if ( ! data ) {
				return;
			}

			state.observer = new MutationObserver( function () {
				scheduleEnhance();
			} );

			state.observer.observe( document.body, { childList: true, subtree: true } );

			scheduleEnhance();
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
