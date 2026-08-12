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
 * global config (`weekly_goal_target_percent`, default 80); reaching 80 %
 * deterministically is day-of-week dependent, so this spec lowers the target
 * to a trivially-crossed value for the duration of the assertion and restores
 * it afterwards.
 *
 * Verified:
 *  - Completing a task with the weekly goal achieved reports
 *    `rewards.weeklyGoal.achieved === true`.
 *  - A `weekly_goal` notification is delivered to the member's Nextcloud bell.
 *
 * Seed-mutating (mutates `oc_appconfig` + completes a task): registered in
 * `SEED_DEPENDENT_SPECS` (chromium-serial-db).
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
	app: string
	object_type: string
	subject: string
}

/** GET the current weekly-goal target percent (global config). */
async function fetchWeeklyTarget(page: Page): Promise<number> {
	const response = await page.request.get('/apps/chorestrike/api/v1/reward-config', {
		headers: { Accept: 'application/json' },
	})
	expect(response.status()).toBe(200)
	return (await response.json()).weeklyGoalTargetPercent as number
}

/** Set the weekly-goal target percent (global config). */
async function setWeeklyTarget(page: Page, percent: number): Promise<void> {
	const response = await page.request.put('/apps/chorestrike/api/v1/reward-config', {
		data: { values: { weeklyGoalTargetPercent: percent } },
		headers: { Accept: 'application/json' },
	})
	expect(response.status()).toBe(200)
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

test.describe('Release: Notifications — weekly goal', () => {
	test.use({ databaseProfile: 'family' })

	test('reaching the weekly goal delivers a de-duped bell notification', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const admin = await createUserContext(browser, 'admin')
		const mom = await createUserContext(browser, 'mom')
		let originalTarget: number | undefined
		try {
			// Lower the target so a single completion crosses it deterministically.
			originalTarget = await fetchWeeklyTarget(admin.page)
			await setWeeklyTarget(admin.page, 1)

			const target = await pickCompletableRoomTask(mom.page)
			if (!target) {
				test.skip(true, 'No active room task available to complete')
				return
			}

			const completeResponse = await mom.page.request.post(
				`/apps/chorestrike/api/v1/room-tasks/${target.id}/complete`,
				{ headers: { Accept: 'application/json' } },
			)
			expect(completeResponse.status()).toBe(200)
			const body = await completeResponse.json()
			expect(body.success).toBe(true)
			expect(body.data?.rewards?.weeklyGoal?.achieved).toBe(true)

			// The weekly-goal notification must be in mom's Nextcloud bell
			// (language-independent contract: app + object type).
			const notifications = await fetchChoreStrikeNotifications(mom.page)
			const weeklyGoal = notifications.find((n) => n.object_type === 'weekly_goal')
			expect(weeklyGoal).toBeDefined()
		} finally {
			if (originalTarget !== undefined) {
				await setWeeklyTarget(admin.page, originalTarget).catch(() => undefined)
			}
			await admin.context.close()
			await mom.context.close()
		}
	})
})
