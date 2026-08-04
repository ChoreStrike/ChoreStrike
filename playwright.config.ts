import { defineConfig, devices } from '@playwright/test'

/**
 * Playwright configuration for E2E testing
 * @see https://playwright.dev/docs/test-configuration
 *
 * CS-PLAN-015b Phase 2: seed-dependent specs (error-handling, validation,
 * multi-user-interactions, task-completion-full-flow) run in a dedicated
 * serial project (`chromium-serial-db`) so the parallel pool never touches
 * the seed/reset endpoints concurrently.
 */

const SEED_DEPENDENT_SPECS = [
	/error-handling\.spec\.ts/,
	/validation\.spec\.ts/,
	/multi-user-interactions\.spec\.ts/,
	/task-completion-full-flow\.spec\.ts/,
	/3-floor-house\.spec\.ts/,
	// CS-PLAN-023: time-travel specs re-seed the family profile and mutate
	// job state via `occ chorestrike:trigger-jobs` — serial pool only.
	/time-travel-overdue\.spec\.ts/,
	/time-travel-streak-notification\.spec\.ts/,
]

// Owner-scoped scheduling specs run as `dad` (an owner) — admin is only a
// member, so owner-only data (member-availability grid) is empty under admin.
// Playwright matches the regex against the full file path, so the patterns
// anchor on a preceding path separator + end-of-string. A bare
// `/scheduling\.spec\.ts/` also matched flex-window-scheduling.spec.ts; the
// separator anchor excludes it unless listed explicitly. All three scheduling
// specs run here.
const SCHEDULING_OWNER_SPECS = [
	/[/\\]scheduling\.spec\.ts$/,
	/[/\\]flex-window-scheduling\.spec\.ts$/,
	/[/\\]availability-settings-flow\.spec\.ts$/,
]

export default defineConfig({
	testDir: './tests/e2e',
	outputDir: 'test-results/',
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
		// Auth setup: logs in each role once and saves storageState.
		// Browser projects depend on it so the authenticated session is
		// ready before any spec runs. See tests/e2e/auth.setup.ts.
		{
			name: 'setup',
			testMatch: /.*\.setup\.ts/,
		},
		{
			name: 'chromium',
			dependencies: ['setup'],
			testIgnore: [...SEED_DEPENDENT_SPECS, ...SCHEDULING_OWNER_SPECS],
			use: {
				...devices['Desktop Chrome'],
				storageState: '.playwright/.auth/admin.json',
				actionTimeout: 15000,
				navigationTimeout: 30000,
			},
		},
		{
			name: 'firefox',
			dependencies: ['setup'],
			testIgnore: [...SEED_DEPENDENT_SPECS, ...SCHEDULING_OWNER_SPECS],
			use: {
				...devices['Desktop Firefox'],
				storageState: '.playwright/.auth/admin.json',
				actionTimeout: 25000,
				navigationTimeout: 60000,
			},
		},
		{
			name: 'webkit',
			dependencies: ['setup'],
			testIgnore: [...SEED_DEPENDENT_SPECS, ...SCHEDULING_OWNER_SPECS],
			use: {
				...devices['Desktop Safari'],
				storageState: '.playwright/.auth/admin.json',
				actionTimeout: 20000,
				navigationTimeout: 45000,
			},
		},
		// Seed-dependent specs run serially in chromium only. The seed
		// assertions are browser-agnostic data checks; running them in one
		// worker prevents parallel specs from wiping each other's data.
		{
			name: 'chromium-serial-db',
			dependencies: ['setup'],
			testMatch: SEED_DEPENDENT_SPECS,
			fullyParallel: false,
			workers: 1,
			use: {
				...devices['Desktop Chrome'],
				storageState: '.playwright/.auth/admin.json',
				actionTimeout: 15000,
				navigationTimeout: 30000,
			},
		},
		{
			name: 'chromium-scheduling-owner',
			dependencies: ['setup'],
			testMatch: SCHEDULING_OWNER_SPECS,
			fullyParallel: false,
			workers: 1,
			use: {
				...devices['Desktop Chrome'],
				storageState: '.playwright/.auth/dad.json',
				actionTimeout: 15000,
				navigationTimeout: 30000,
			},
		},
	],
})
