/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * E2E: Time-Travel — streak-freeze consumption + at-risk notification via
 * `occ chorestrike:trigger-jobs --as-of` (CS-PLAN-023, E2E #2)
 *
 * The `family` seed carries mom in the time-travel streak state: a live
 * 5-day streak, zero freezes and no completions. Running the jobs with
 * `--as-of=<tomorrow>` makes "yesterday" today, so within seconds:
 *
 *   1. `daily-notification` fires the streak-at-risk reminder (mom: streak
 *      > 0, no completions "yesterday", no freezes left), and
 *   2. `streak-freeze` finds no freeze to consume and resets the streak.
 *
 * The order matters and mirrors the deterministic `all` ordering of the
 * trigger command: the reminder must fire while the streak is positive.
 * No real multi-day wait, no clock trickery.
 *
 * Requires: Docker test environment with the `family` database profile.
 * @see docs/plans/active/cs-plan-023-time-travel-testing.md §4
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import { runOcc, containerDate } from './fixtures/occ-runner'

interface LeaderboardEntry {
	id: number
	nextcloudUserId: string
	displayName: string
	currentStreak: number
	streakFreezes: number
}

interface OcsNotification {
	notification_id: number
	app: string
	user: string
	object_type: string
	object_id: string
	subject: string
}

/**
 * Fetch mom's leaderboard entry (carries currentStreak + streakFreezes).
 */
async function fetchMomEntry(page: import('@playwright/test').Page): Promise<LeaderboardEntry> {
	const response = await page.request.get('/apps/chorestrike/api/v1/game/leaderboard')
	expect(response.status()).toBe(200)

	const body = await response.json()
	expect(body.success).toBe(true)

	const entries: LeaderboardEntry[] = body.data || []
	const mom = entries.find(entry => entry.nextcloudUserId === 'mom')
	expect(mom).toBeDefined()
	return mom!
}

test.describe('Time-Travel: streak freeze + at-risk notification', () => {
	test.use({ databaseProfile: 'family' })

	test('seeded mom starts with a live streak and zero freezes', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const mom = await fetchMomEntry(page)
			expect(mom.currentStreak).toBe(5)
			expect(mom.streakFreezes).toBe(0)
		} finally {
			await context.close()
		}
	})

	test('trigger-jobs --as-of consumes the streak and delivers the at-risk notification', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const momBefore = await fetchMomEntry(page)
			expect(momBefore.currentStreak).toBe(5)
			expect(momBefore.streakFreezes).toBe(0)

			// "yesterday" = the container's today: as-of is derived from the
			// container clock to avoid host/container date skew.
			const tomorrow = containerDate(1)

			// 1) at-risk pass first — the reminder fires while streak > 0.
			runOcc(`chorestrike:trigger-jobs daily-notification --as-of=${tomorrow}`)
			// 2) freeze pass — zero freezes left, so the streak is reset.
			runOcc(`chorestrike:trigger-jobs streak-freeze --as-of=${tomorrow}`)

			const momAfter = await fetchMomEntry(page)
			expect(momAfter.currentStreak).toBe(0)

			// The at-risk reminder must be in mom's Nextcloud bell
			// (language-independent contract: app + object type).
			const notificationsResponse = await page.request.get(
				'/ocs/v2.php/apps/notifications/api/v2/notifications?format=json',
				{ headers: { 'OCS-APIRequest': 'true' } },
			)
			expect(notificationsResponse.status()).toBe(200)

			const ocsBody = await notificationsResponse.json()
			const notifications: OcsNotification[] = ocsBody?.ocs?.data || []
			const atRisk = notifications.find(
				notification => notification.app === 'chorestrike' && notification.object_type === 'streak',
			)
			expect(atRisk).toBeDefined()
		} finally {
			await context.close()
		}
	})
})
