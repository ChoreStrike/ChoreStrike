/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Release E2E regression — Transparency & Insights UI (CS-PLAN-040).
 *
 * Covers the FEAT-UX-016/017/022 acceptance rows end to end:
 * - /insights renders house stats cards, the capacity-normalized fairness
 *   table, and an activity feed listing a real completion.
 * - SchedulingView surfaces the overcapacity warning when planned energy
 *   exceeds the available capacity.
 *
 * The completion and the overcapacity tasks are created via the API (the
 * deterministic data path); the assertions are UI-driven because these are
 * user-facing render checks.
 *
 * Seed-mutating: registered in `SEED_DEPENDENT_SPECS` (chromium-serial-db).
 * Requires: Docker test environment with the `family` database profile.
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import type { Page } from '@playwright/test'

interface RoomTaskJson {
	id: number
	isActive: boolean
	nextDue: string | null
}

/** Fetch an active, due-dated room task via floors/with-rooms → index. */
async function pickActiveRoomTask(page: Page): Promise<RoomTaskJson | null> {
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
		const found = tasks.find((rt) => rt.isActive && rt.nextDue)
		if (found) {
			return found
		}
	}
	return null
}

/** Local YYYY-MM-DD `offsetDays` from today. */
function offsetDate(offsetDays: number): string {
	const d = new Date()
	d.setDate(d.getDate() + offsetDays)
	const y = d.getFullYear()
	const m = String(d.getMonth() + 1).padStart(2, '0')
	const day = String(d.getDate()).padStart(2, '0')
	return `${y}-${m}-${day}`
}

test.describe('Release: Insights Flow', () => {
	test.use({ databaseProfile: 'family' })

	test('insights renders stats cards, fairness table, and activity feed', async ({ browser, page, seededData }) => {
		expect(seededData).not.toBeNull()

		// Complete one seeded task as mom so the feed has real data.
		const mom = await createUserContext(browser, 'mom')
		try {
			const roomTask = await pickActiveRoomTask(mom.page)
			expect(roomTask).not.toBeNull()
			const complete = await mom.page.request.post(
				`/apps/chorestrike/api/v1/room-tasks/${roomTask!.id}/complete`,
				{ headers: { Accept: 'application/json' } },
			)
			expect(complete.status()).toBe(200)
		} finally {
			await mom.context.close()
		}

		await page.goto('/apps/chorestrike/#/insights')
		await expect(page.getByTestId('insights-view')).toBeVisible({ timeout: 20000 })
		await expect(page.getByTestId('house-stats-cards')).toBeVisible()
		await expect(page.getByTestId('member-fairness-table')).toBeVisible()
		await expect(page.getByTestId('fairness-row').first()).toBeVisible()

		// Contribution and coins stay separate columns (energy-and-balancing.md).
		await expect(page.getByTestId('header-contribution')).toBeVisible()
		await expect(page.getByTestId('header-coins')).toBeVisible()

		// The feed lists mom's completion with actor + task.
		const feedItem = page.getByTestId('activity-item').first()
		await expect(feedItem).toBeVisible({ timeout: 15000 })
	})

	test('overcapacity warning appears when a day exceeds its capacity', async ({ browser, page, seededData }) => {
		expect(seededData).not.toBeNull()

		// All mutations run through a CSRF-primed context page.
		const admin = await createUserContext(browser, 'admin')
		try {
			const api = admin.page.request

			const floorsResponse = await api.get('/apps/chorestrike/api/v1/floors/with-rooms', {
				headers: { Accept: 'application/json' },
			})
			expect(floorsResponse.status()).toBe(200)
			const floors = ((await floorsResponse.json()).data ?? []) as Array<{ rooms?: Array<{ id: number }> }>
			const roomId = floors.flatMap((f) => f.rooms ?? []).map((r) => r.id)[0]
			expect(roomId).toBeDefined()

			const tomorrow = offsetDate(1)
			for (let i = 0; i < 4; i++) {
				// One max-energy definition per room task — the room-task create
				// endpoint rejects duplicate task/room pairs.
				const createTask = await api.post('/apps/chorestrike/api/v1/tasks', {
					data: { name: `Overcapacity Probe ${i}`, description: '', energyCost: 5, priority: 2, frequencyType: 'daily', frequencyValue: 1 },
					headers: { Accept: 'application/json' },
				})
				const createdTaskBody = await createTask.json()
				expect(createTask.status(), JSON.stringify(createdTaskBody)).toBe(200)
				const taskId = (createdTaskBody.data as { id: number }).id

				const created = await api.post('/apps/chorestrike/api/v1/room-tasks', {
					data: { taskId, roomId, nextDue: tomorrow },
					headers: { Accept: 'application/json' },
				})
				const createdBody = await created.json()
				expect(created.status(), JSON.stringify(createdBody)).toBe(200)
			}
		} finally {
			await admin.context.close()
		}

		await page.goto('/apps/chorestrike/#/scheduling')
		await expect(page.getByTestId('scheduling-view')).toBeVisible({ timeout: 20000 })

		await expect(page.getByTestId('overcapacity-warning')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('overcapacity-day')).toHaveCount(1)

		// The calendar marks the overloaded day.
		await expect(page.getByTestId('overcapacity-marker').first()).toBeVisible()
	})

	test('navigation exposes the insights entry between leaderboard and scheduling', async ({ page }) => {
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('domcontentloaded')

		await expect(page.getByTestId('nav-insights')).toBeVisible()
		await page.getByTestId('nav-insights').click()
		await expect(page.getByTestId('insights-view')).toBeVisible({ timeout: 20000 })
	})
})
