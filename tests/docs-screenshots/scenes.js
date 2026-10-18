/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Documentation screenshot scenes (CS-PLAN-087 WP-B, TECH-DOCS-007).
 *
 * `npm run docs:screenshots` captures every scene into docs/screenshots/;
 * the README and the user guide reference only these outputs. Scenes run in
 * order on an isolated staged row with the `family` seed: later scenes see
 * what earlier ones changed (a completed task), and `emptyHousehold` wipes
 * the household, so it comes last.
 */

'use strict'

/** Accounts of the `family` seed — the only identities a scene may use. */
const SEEDED_ROLES = ['mom', 'dad', 'teen', 'kid', 'grandma', 'friend']

/** Name of the seeded demo household, checked before anything is captured. */
const SEEDED_HOUSE_NAME = 'Test Family Home'

/** Name the screenshots show; set on the staged row only. */
const PRESENTED_HOUSE_NAME = 'Our Family Home'

const DESKTOP = { width: 1280, height: 800 }
const PHONE = { width: 390, height: 844 }

/** Steps before navigation (`prepare`) and after the ready view (`action`). */
const PREPARES = ['emptyHousehold']
const ACTIONS = ['planPreview', 'notificationSettings', 'completeTask']

const SCENES = [
	{ output: 'my-day.png', role: 'teen', route: '/today', ready: 'today-view', viewport: DESKTOP },
	{ output: 'dashboard.png', role: 'mom', route: '/dashboard', ready: 'dashboard-view', viewport: DESKTOP },
	{ output: 'weekly-planner.png', role: 'mom', route: '/scheduling', ready: 'scheduling-view', viewport: DESKTOP, action: 'planPreview' },
	{ output: 'reward-catalog.png', role: 'teen', route: '/rewards', ready: 'rewards-view', viewport: DESKTOP },
	{ output: 'guide-settings.png', role: 'teen', route: '/settings', ready: 'settings-view', viewport: DESKTOP, action: 'notificationSettings' },
	{ output: 'guide-my-day-phone.png', role: 'teen', route: '/today', ready: 'today-view', viewport: PHONE },
	{ output: 'guide-undo-phone.png', role: 'teen', route: '/today', ready: 'today-view', viewport: PHONE, action: 'completeTask' },
	{ output: 'guide-setup-wizard.png', role: 'mom', route: '/dashboard', ready: 'setup-wizard', viewport: DESKTOP, prepare: 'emptyHousehold' },
]

/**
 * Refuse every capture target that is not a loopback HTTP origin, so a real
 * instance can never be photographed (D-087-03).
 *
 * @param {string|undefined} baseUrl Playwright base URL
 * @return {string} The checked origin
 */
function assertCaptureTarget(baseUrl) {
	let url
	try {
		url = new URL(baseUrl)
	} catch {
		throw new Error('Documentation capture needs a loopback NEXTCLOUD_URL')
	}
	if (url.protocol !== 'http:' || !['127.0.0.1', 'localhost', '[::1]'].includes(url.hostname)
		|| url.username || url.password) {
		throw new Error('Documentation capture runs only against a loopback test instance')
	}
	return url.origin
}

module.exports = { SEEDED_ROLES, SEEDED_HOUSE_NAME, PRESENTED_HOUSE_NAME, DESKTOP, PHONE, PREPARES, ACTIONS, SCENES, assertCaptureTarget }
