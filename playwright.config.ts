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
	// CS-PLAN-024: release regression specs mutate seed state (task lifecycle,
	// role changes, global reward config, template imports, coin spend,
	// weekly-goal completion) — serial pool only. Separator-anchored per the
	// SCHEDULING_OWNER_SPECS convention so a substring can never match twice.
	/[/\\]release-task-lifecycle\.spec\.ts$/,
	/[/\\]release-visibility-roles\.spec\.ts$/,
	/[/\\]release-task-templates\.spec\.ts$/,
	/[/\\]release-boost-shop\.spec\.ts$/,
	/[/\\]release-notifications\.spec\.ts$/,
	// CS-PLAN-027: setup-wizard re-seeds the family profile (catalog is
	// read-only, but the `seededData` fixture wipes + reseeds the DB) and the
	// template spec inserts rows — serial pool only, like the other release-*.
	/[/\\]release-setup-wizard\.spec\.ts$/,
	// CS-PLAN-038: today-view completes a seeded task and members-owner
	// invites/reorders/removes members (plus an occ email provisioning
	// side effect) — serial pool only.
	/[/\\]today-view\.spec\.ts$/,
	/[/\\]members-owner\.spec\.ts$/,
	// CS-PLAN-040: insights-flow completes a seeded task and creates
	// overcapacity tasks — mutates seed state, serial pool only.
	/[/\\]insights-flow\.spec\.ts$/,
	// CS-PLAN-042: the structure specs mutate house floors/rooms through the
	// owner-gated Settings surface. Concurrent structural edits shift list
	// positions and expansion state under other workers, so they run one
	// after another here (their describes pin the owner identity `mom`).
	/[/\\]room-management\.spec\.ts$/,
	// CS-PLAN-068 (parent decision D5): the canonical household journey
	// replaces the retired gui-scratch-flow spec. It reseeds with the
	// `emptyhouse` profile, bootstraps a fresh owner and mutates app state
	// exclusively through the real UI — serial pool, one DB owner.
	/[/\\]core-household-journey\.spec\.ts$/,
	// CS-PLAN-044: the dashboard private-filter spec creates room tasks and
	// resolves room IDs from the seed while it holds the form open. Run
	// concurrently with a reseed (e.g. today-view's `seededData` fixture),
	// the room-task POST hits a replaced room ID and 404s — serial pool only.
	/[/\\]dashboard\.spec\.ts$/,
	// CS-PLAN-051: the checklist spec mutates seed state (adds checklist
	// items + a sub-task to the first seeded task row) and resolves rows via
	// seed-coupled `.task-item` locators. Under full-matrix load a parallel
	// serial-pool reseed replaced the expanded row mid-test, so the item
	// POST landed on a re-rendered list — serial pool only, like the other
	// seed-mutating specs.
	/[/\\]task-checklist-and-features\.spec\.ts$/,
	// CS-PLAN-062: the setup-wizard journey reseeds with the `emptyhouse`
	// profile (reset only) and bootstraps a fresh owner — any parallel spec
	// seeing that wiped state would lose its seed — serial pool only.
	/[/\\]setup-wizard-journey\.spec\.ts$/,
	// CS-PLAN-066: both WP7 exit specs complete seeded tasks and (family-
	// core-ux) create/edit tasks through the real HTTP boundary — they also
	// assert derived projections that any parallel reseed would corrupt.
	/[/\\]family-core-ux\.spec\.ts$/,
	/[/\\]workflow-state-reconciliation\.spec\.ts$/,
]

// Owner-scoped scheduling specs run as `dad` (an owner) — admin is only a
// member, so owner-only data (member-availability grid) is empty under admin.
// Playwright matches the regex against the full file path, so the patterns
// anchor on a preceding path separator + end-of-string. A bare
// `/scheduling\.spec\.ts/` also matched flex-window-scheduling.spec.ts; the
// separator anchor excludes it unless listed explicitly. All four scheduling
// specs run here — CS-PLAN-065 adds the apply-flow exit journey.
const SCHEDULING_OWNER_SPECS = [
	/[/\\]scheduling\.spec\.ts$/,
	/[/\\]flex-window-scheduling\.spec\.ts$/,
	/[/\\]availability-settings-flow\.spec\.ts$/,
	/[/\\]scheduling-apply-flow\.spec\.ts$/,
]

// CS-PLAN-032: the member self-availability spec mutates the admin user's own
// weekday availability and asserts single-save semantics. It runs exactly once
// in the default `chromium` project (admin is a plain member under the family
// seed); firefox and webkit ignore it so the same mutation never executes
// three times concurrently against the shared backend.
const SELF_AVAILABILITY_MEMBER_SPECS = [
	/[/\\]self-availability-member\.spec\.ts$/,
]

export default defineConfig({
	testDir: './tests/e2e',
	outputDir: 'build/test-results/',
	/* Run tests in files in parallel */
	fullyParallel: true,
	/* Fail the build on CI if you accidentally left test.only in the source code */
	forbidOnly: !!process.env.CI,
	/* Retry on CI and locally for stability */
	retries: 2,
	/* Opt out of parallel tests on CI */
	workers: process.env.CI ? 1 : undefined,
	/* Reporter to use — HTML report lands in the ignored build/ tree
	   (CS-PLAN-069 WP4: no Playwright artifacts in the repository root). */
	reporter: [['html', { outputFolder: 'build/playwright-report' }]],
	/* Global timeout for each test */
	timeout: 90000,
	/* Shared settings for all the projects below */
	use: {
		/* Base URL to use in actions like `await page.goto('/')` */
		baseURL: process.env.NEXTCLOUD_URL || 'http://localhost:8080',

		/* Failure evidence must exist even when the gate disables retries. */
		trace: 'retain-on-failure',

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
		// The login loop runs up to seven sequential form logins; the global
		// 90s test timeout is too tight for a slow Nextcloud response burst
		// (CS-PLAN-068: one-off setup timeout killed the whole pool).
		{
			name: 'setup',
			testMatch: /.*\.setup\.ts/,
			timeout: 240000,
		},
		{
			name: 'chromium',
			dependencies: ['setup'],
			testIgnore: [...SEED_DEPENDENT_SPECS, ...SCHEDULING_OWNER_SPECS],
			use: {
				...devices['Desktop Chrome'],
				storageState: 'build/.playwright-auth/admin.json',
				actionTimeout: 15000,
				navigationTimeout: 30000,
			},
		},
		{
			name: 'firefox',
			dependencies: ['setup'],
			testIgnore: [...SEED_DEPENDENT_SPECS, ...SCHEDULING_OWNER_SPECS, ...SELF_AVAILABILITY_MEMBER_SPECS],
			use: {
				...devices['Desktop Firefox'],
				storageState: 'build/.playwright-auth/admin.json',
				actionTimeout: 25000,
				navigationTimeout: 60000,
			},
		},
		{
			name: 'webkit',
			dependencies: ['setup'],
			testIgnore: [...SEED_DEPENDENT_SPECS, ...SCHEDULING_OWNER_SPECS, ...SELF_AVAILABILITY_MEMBER_SPECS],
			use: {
				...devices['Desktop Safari'],
				storageState: 'build/.playwright-auth/admin.json',
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
				storageState: 'build/.playwright-auth/admin.json',
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
				storageState: 'build/.playwright-auth/dad.json',
				actionTimeout: 15000,
				navigationTimeout: 30000,
			},
		},
	],
})
