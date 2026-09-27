/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Release E2E regression — Notifications (CS-PLAN-024, Phase 5).
 *
 * Covers the last unmarked release-test-plan.md §13 row: the weekly-goal
 * notification. The daily/streak reminder already has a home
 * (`time-travel-streak-notification`, CS-023); title-unlock fires
 * synchronously too but is exercised indirectly by `title-selection`.
 *
 * A current week remains provisional even after enough on-time work. A past
 * week with saved on-time work emits its one-time bell when the timeline job
 * observes closure. The opt-in family seed supplies that evidence and the
 * existing test-mode OCC seam triggers the real job without waiting a week.
 *
 * Verified:
 *  - After dismissing stale bell rows, a closed-week `weekly_goal` bell is newly
 *    delivered to the member's Nextcloud bell. Dismissal is required because
 *    reset()/re-seed never purges `oc_notifications` — a plain "exists" check
 *    would pass vacuously on a
 *    leftover row from an earlier spec or CI run. Dismissal must iterate
 *    because the OCS bell response is server-side-capped, so a single pass
 *    cannot prove the bell is stale-row free (CS-PLAN-058 gate round). The
 *    notification's objectId is the bare ISO weekStart with no house
 *    discriminator — harmless while the instance is bootstrap-single-house,
 *    but it means stale-type rows are the norm in the test matrix.
 *    CS-PLAN-055 review finding.
 *
 * Seed-mutating (opt-in past-week evidence): registered in `SEED_DEPENDENT_SPECS`
 * (chromium-serial-db).
 * Requires: Docker test environment with the `family` database profile.
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import { containerDate, runOcc } from './fixtures/occ-runner'
import type { Page } from '@playwright/test'

interface OcsNotification {
	notification_id: number
	app: string
	object_type: string
	subject: string
}

/** ChoreStrike notifications currently in the acting user's Nextcloud bell. */
async function fetchChoreStrikeNotifications(page: Page): Promise<OcsNotification[]> {
	const response = await page.request.get(
		'/ocs/v2.php/apps/notifications/api/v2/notifications?format=json',
		{ headers: { 'OCS-APIRequest': 'true' } },
	)
	expect(response.status()).toBe(200)
	const body = await response.json()
	const all: OcsNotification[] = body?.ocs?.data || []
	return all.filter((n) => n.app === 'chorestrike')
}

/**
 * Dismiss the user's existing weekly_goal bell rows so the closure-job
 * assertion can only be satisfied by THIS run's dispatch (see file header).
 *
 * The OCS bell response is server-side-capped at a fixed number of rows
 * (observed: exactly 25, oldest-first truncation), so one pass cannot clear
 * a bell that accumulated more stale weekly_goal rows than the window holds
 * (seed-mutating specs and earlier container runs leave them; reset() never
 * purges oc_notifications). The job's new row then pushes the hidden
 * stale rows back into the checked window and the assertion counts them.
 * Delete round after round until a fetched window shows no stale rows, and
 * fail loudly if that never converges.
 */
async function dismissWeeklyGoalNotifications(page: Page): Promise<void> {
	for (let round = 0; round < 10; round++) {
		const stale = (await fetchChoreStrikeNotifications(page)).filter(
			(n) => n.object_type === 'weekly_goal',
		)
		if (stale.length === 0) {
			return
		}
		for (const notification of stale) {
			const response = await page.request.delete(
				`/ocs/v2.php/apps/notifications/api/v2/notifications/${notification.notification_id}`,
				{ headers: { 'OCS-APIRequest': 'true' } },
			)
			expect(response.status()).toBe(200)
		}
	}
	throw new Error('dismissWeeklyGoalNotifications: bell did not converge to zero stale weekly_goal rows within 10 rounds')
}

test.describe('Release: Notifications — weekly goal', () => {
	test.use({ databaseProfile: 'family' })

	test('reaching the weekly goal delivers a de-duped bell notification', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const seed = await page.request.post('/apps/chorestrike/api/v1/test/seed/family?closedWeekEvidence=1')
			expect(seed.status()).toBe(200)
			// Resetting app data does not purge the Nextcloud bell.
			await dismissWeeklyGoalNotifications(page)
			const asOf = containerDate(0)
			runOcc(`chorestrike:trigger-jobs timeline --as-of=${asOf}`)

			// A weekly-goal notification must have reached mom's Nextcloud
			// bell (language-independent contract: app + object type; stale
			// rows were dismissed above, so this is THIS run's dispatch).
			const notifications = await fetchChoreStrikeNotifications(page)
			const weeklyGoal = notifications.filter((n) => n.object_type === 'weekly_goal')
			expect(weeklyGoal).toHaveLength(1)
			runOcc(`chorestrike:trigger-jobs timeline --as-of=${asOf}`)
			const replay = (await fetchChoreStrikeNotifications(page)).filter((n) => n.object_type === 'weekly_goal')
			expect(replay).toHaveLength(1)
		} finally {
			await context.close()
		}
	})
})
