import { defineConfig, devices } from '@playwright/test'
import { userStatePath } from './tests/e2e/fixtures/auth-contexts'
import { assertCaptureTarget } from './tests/docs-screenshots/scenes'

/**
 * Documentation screenshot capture (CS-PLAN-087 WP-B). Run it through
 * `npm run docs:screenshots`, which stages an isolated row first. A separate
 * config and test directory keep the capture out of the canonical gate's
 * discovery (scripts/run-e2e-gate.js); the `setup` project is the E2E one.
 *
 * The loopback check runs at config load, before `setup` touches anything.
 */
const baseURL = assertCaptureTarget(process.env.NEXTCLOUD_URL)

export default defineConfig({
	testDir: './tests/docs-screenshots',
	outputDir: 'build/docs-screenshots/results/',
	fullyParallel: false,
	forbidOnly: true,
	retries: 0,
	workers: 1,
	reporter: [['list']],
	timeout: 120000,
	use: {
		baseURL,
		locale: 'en-US',
		colorScheme: 'light',
		reducedMotion: 'reduce',
		trace: 'retain-on-failure',
		actionTimeout: 20000,
		navigationTimeout: 45000,
	},
	projects: [
		{
			name: 'setup',
			testDir: './tests/e2e',
			testMatch: /auth\.setup\.ts/,
			timeout: 240000,
		},
		{
			name: 'docs',
			dependencies: ['setup'],
			testMatch: /capture\.spec\.ts/,
			use: {
				...devices['Desktop Chrome'],
				storageState: userStatePath('admin'),
			},
		},
	],
})
