import { expect, test } from '@playwright/test';

const required = [
	'TLCD_E2E_BASE_URL',
	'TLCD_E2E_USERNAME',
	'TLCD_E2E_PASSWORD',
	'TLCD_E2E_COURSE_ID',
];
const missing = required.filter( ( name ) => ! process.env[ name ] );

test.skip( missing.length > 0, `Missing E2E environment: ${ missing.join( ', ' ) }` );

test.beforeEach( async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( process.env.TLCD_E2E_USERNAME );
	await page.locator( '#user_pass' ).fill( process.env.TLCD_E2E_PASSWORD );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /wp-admin|tutor-dashboard/ );

	await page.goto(
		`/wp-admin/admin.php?page=create-course&course_id=${ encodeURIComponent(
			process.env.TLCD_E2E_COURSE_ID
		) }`
	);
	await expect( page.locator( '#tutor-course-builder' ) ).toBeVisible();
} );

test( 'injects duplicate controls into the real Tutor LMS Course Builder', async ( { page } ) => {
	await expect( page.locator( '.tlcd-btn[data-tlcd-type="topic"]' ).first() ).toBeVisible();
	await expect( page.locator( '.tlcd-btn[data-tlcd-type="content"]' ).first() ).toBeVisible();
} );

test( 'duplicates one content row end to end on a disposable staging course', async ( { page } ) => {
	test.skip(
		process.env.TLCD_E2E_ALLOW_MUTATION !== '1',
		'Set TLCD_E2E_ALLOW_MUTATION=1 only for a disposable staging course.'
	);

	const rows = page.locator( '.tlcd-btn[data-tlcd-type="content"]' );
	const before = await rows.count();

	expect( before ).toBeGreaterThan( 0 );
	await rows.first().click();

	await expect.poll( async () => rows.count(), { timeout: 20_000 } ).toBe( before + 1 );
	await expect( page.locator( '.tlcd-toast--success' ).last() ).toBeVisible();
} );
