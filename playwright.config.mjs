import { defineConfig } from '@playwright/test';

export default defineConfig( {
	testDir: './tests/e2e',
	timeout: 60_000,
	expect: {
		timeout: 10_000,
	},
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? 'github' : 'list',
	use: {
		baseURL: process.env.TLCD_E2E_BASE_URL,
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
	},
} );
