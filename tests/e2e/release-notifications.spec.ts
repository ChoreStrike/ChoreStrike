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
 * Weekly-goal-reached notifications fire *synchronously on task completion*
 * (RoomService, post-commit) when the household crosses its weekly target —
 * no `trigger-jobs` needed (CS-PLAN-024 §2.3 trigger split). The target is
 * global config (`weekly_goal_target_percent`, default 80). Reaching 80 %
 * deterministically is day-of-week dependent, so the `family` seed profile
 * lowers it to a trivially-crossed value (TestDataService
 * `FAMILY_REWARD_CONFIG_OVERRIDES`, CS-PLAN-055 — the reward-config HTTP
 * surface was pruned, so the knob cannot be mutated over the API anymore).
 *
 * Verified:
 *  - Completing a task with the weekly goal achieved reports
 *    `rewards.weeklyGoal.achieved === true`.
 *  - After dismissing stale bell rows, a `weekly_goal` notification is newly
 *    delivered to the member's Nextcloud bell. Dismissal is required because
 *    the family seed's global 1 % target lets every family-seeded spec fire
 *    the same notification type, and reset()/re-seed never purges
 *    `oc_notifications` — a plain "exists" check would pass vacuously on a
 *    leftover row from an earlier spec or CI run. Dismissal must iterate
 *    because the OCS bell response is server-side-capped, so a single pass
 *    cannot prove the bell is stale-row free (CS-PLAN-058 gate round). The
 *    notification's objectId is the bare ISO weekStart with no house
 *    discriminator — harmless while the instance is bootstrap-single-house,
 *    but it means stale-type rows are the norm in the test matrix.
 *    CS-PLAN-055 review finding.
 *
 * Seed-mutating (completes a task): registered in `SEED_DEPENDENT_SPECS`
 * (chromium-serial-db).
 * Requires: Docker test environment with the `family` database profile.
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import type { Page } from '@playwright/test'

interface RoomTaskJson {
	id: number
	nextDue: string | null
	isActive: boolean
}

interface OcsNotification {
	notification_id: number
	app: string
	object_type: string
	subject: string
}

/** First active room task the owner may complete.
 *
 * `room_task#index` requires a `roomId`, so one is resolved via
 * `floors/with-rooms` first (same pattern as `admin-only-tasks.spec.ts`).
 */
async function pickCompletableRoomTask(page: Page): Promise<RoomTaskJson | null> {
	const floorsResponse = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms', {
		headers: { Accept: 'application/json' },
	})
	if (floorsResponse.status() !== 200) {
		return null
	}
	const floors = ((await floorsResponse.json()).data ?? []) as Array<{ rooms?: Array<{ id: number }> }>
	const roomIds = floors.flatMap((f) => f.rooms ?? []).map((r) => r.id)

	for (const roomId of roomIds) {
		const response = await page.request.get(
			`/apps/chorestrike/api/v1/room-tasks?roomId=${roomId}`,
			{ headers: { Accept: 'application/json' } },
		)
		if (response.status() !== 200) {
			continue
		}
		const tasks = ((await response.json()).data || []) as RoomTaskJson[]
		const found = tasks.find((rt) => rt.isActive)
		if (found) {
			return found
		}
	}
	return null
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
 * Dismiss the user's existing weekly_goal bell rows so the post-completion
 * assertion can only be satisfied by THIS run's dispatch (see file header).
 *
 * The OCS bell response is server-side-capped at a fixed number of rows
 * (observed: exactly 25, oldest-first truncation), so one pass cannot clear
 * a bell that accumulated more stale weekly_goal rows than the window holds
 * (seed-mutating specs and earlier container runs leave them; reset() never
 * purges oc_notifications). The completion's new row then pushes the hidden
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
			// The family seed lowers the weekly target to a trivially-crossed
			// value, so one completion on the fresh house achieves it.
			const target = await pickCompletableRoomTask(page)
			expect(target, 'family seed must provide a completable occurrence').not.toBeNull()

			// Dismiss stale weekly-goal rows (other family-seeded specs and
			// earlier CI runs leave them; reset() never purges the bell — see
			// header) so only THIS completion's dispatch can satisfy the check.
			await dismissWeeklyGoalNotifications(page)

			const completeResponse = await page.request.post(
				`/apps/chorestrike/api/v1/room-tasks/${target.id}/complete`,
				{ headers: { Accept: 'application/json' } },
			)
			expect(completeResponse.status()).toBe(200)
			const body = await completeResponse.json()
			expect(body.success).toBe(true)
			expect(body.data?.rewards?.weeklyGoal?.achieved).toBe(true)

			// A weekly-goal notification must have reached mom's Nextcloud
			// bell (language-independent contract: app + object type; stale
			// rows were dismissed above, so this is THIS run's dispatch).
			const notifications = await fetchChoreStrikeNotifications(page)
			const weeklyGoal = notifications.filter((n) => n.object_type === 'weekly_goal')
			expect(weeklyGoal).toHaveLength(1)
		} finally {
			await context.close()
		}
	})
})
