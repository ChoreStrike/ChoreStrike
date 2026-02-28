import { defineConfig, devices } from '@playwright/test'

/**
 * Playwright configuration for E2E testing
 * @see https://playwright.dev/docs/test-configuration
 */
export default defineConfig({
	testDir: './tests/e2e',
	/* Run tests in files in parallel */
	fullyParallel: true,
	/* Fail the build on CI if you accidentally left test.only in the source code */
	forbidOnly: !!process.env.CI,
	/* Retry on CI and locally for stability */
	retries: 2,
	/* Opt out of parallel tests on CI */
	workers: process.env.CI ? 1 : undefined,
	/* Reporter to use */
	reporter: 'html',
	/* Global timeout for each test */
	timeout: 90000,
	/* Shared settings for all the projects below */
	use: {
		/* Base URL to use in actions like `await page.goto('/')` */
		baseURL: process.env.NEXTCLOUD_URL || 'http://localhost:8080',

		/* Collect trace when retrying the failed test */
		trace: 'on-first-retry',

		/* Screenshot on failure */
		screenshot: 'only-on-failure',

		/* Action timeout for clicks, fills, etc. */
		actionTimeout: 20000,

		/* Navigation timeout */
		navigationTimeout: 45000,
	},

	/* Configure projects for major browsers */
	projects: [
		{
			name: 'chromium',
			use: { 
				...devices['Desktop Chrome'],
				/* Faster timeouts for Chrome (more stable) */
				actionTimeout: 15000,
				navigationTimeout: 30000,
			},
		},
		{
			name: 'firefox',
			use: { 
				...devices['Desktop Firefox'],
				/* Longer timeouts for Firefox (slower but more stable) */
				actionTimeout: 25000,
				navigationTimeout: 60000,
			},
		},
		{
			name: 'webkit',
			use: { 
				...devices['Desktop Safari'],
				/* Standard timeouts for WebKit */
				actionTimeout: 20000,
				navigationTimeout: 45000,
			},
		},
	],
})
