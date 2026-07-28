import globals from 'globals';

/**
 * ปลั๊กอินนี้ส่ง JavaScript แบบ ES5 ตรง ๆ ไปยังเบราว์เซอร์ (ไม่มีขั้นตอน build)
 * จึงจำกัดไวยากรณ์ไว้ที่ ES5 เพื่อให้ปลอดภัยกับเบราว์เซอร์เก่าที่ WordPress ยังรองรับ
 */
export default [
	{
		files: [ 'tests/js/**/*.mjs', 'tests/e2e/**/*.mjs', 'playwright.config.mjs' ],
		languageOptions: {
			ecmaVersion: 2022,
			sourceType: 'module',
			globals: { ...globals.node },
		},
		rules: {
			'no-unused-vars': [ 'error', { args: 'none', caughtErrors: 'none' } ],
			'no-undef': 'error',
			eqeqeq: [ 'error', 'always' ],
		},
	},
	{
		files: [ 'assets/js/**/*.js' ],
		languageOptions: {
			ecmaVersion: 2017,
			sourceType: 'script',
			globals: {
				...globals.browser,
				tlcdConfig: 'readonly',
			},
		},
		rules: {
			// `catch ( e ) {}` ที่ไม่ใช้ตัวแปรเป็นรูปแบบปกติของโค้ดชุดนี้
			'no-unused-vars': [ 'error', { args: 'none', caughtErrors: 'none' } ],
			'no-undef': 'error',
			eqeqeq: [ 'error', 'always' ],
			'no-console': 'off',
			'no-var': 'off',
		},
	},
];
