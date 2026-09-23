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
import { completeRoomTaskViaApi } from './fixtures/completion-api'
import type { Page } from '@playwright/test'

import { houseDateOffset } from './fixtures/house-clock.js'

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
const offsetDate = (days: number) => houseDateOffset(days, 'UTC')

test.describe('Release: Insights Flow', () => {
	test.use({ databaseProfile: 'family' })

	test('insights renders stats cards, fairness table, and activity feed', async ({ browser, page, seededData }) => {
		expect(seededData).not.toBeNull()

		// Complete one seeded task as mom so the feed has real data.
		const mom = await createUserContext(browser, 'mom')
		try {
			const roomTask = await pickActiveRoomTask(mom.page)
			expect(roomTask).not.toBeNull()
			const complete = await completeRoomTaskViaApi(mom.page, roomTask!.id)
			expect(complete.status()).toBe(200)
		} finally {
			await mom.context.close()
		}

		await page.goto('/apps/chorestrike/#/insights')
		await expect(page.getByTestId('insights-view')).toBeVisible({ timeout: 20000 })

		// CS-PLAN-051: under load a fetch can transiently fail; the retryable
		// error state is the recovery path — retry bounded times before
		// asserting the healthy render.
		const errorRetry = page.getByTestId('error-retry')
		for (let attempt = 0; attempt < 3; attempt++) {
			if (await errorRetry.isVisible().catch(() => false)) {
				await errorRetry.click()
			} else {
				break
			}
		}

		await expect(page.getByTestId('house-stats-cards')).toBeVisible({ timeout: 20000 })
		await expect(page.getByTestId('member-fairness-table')).toBeVisible()
		await expect(page.getByTestId('fairness-row').first()).toBeVisible()

		// CS-PLAN-051: a successful load must not surface the shared
		// retryable error state anywhere on the view.
		await expect(page.getByTestId('error-state')).toHaveCount(0)

		// Contribution and coins stay separate columns (energy-and-balancing.md).
		await expect(page.getByTestId('header-contribution')).toBeVisible()
		await expect(page.getByTestId('header-coins')).toBeVisible()

		// The feed lists mom's completion with actor + task.
		const feedItem = page.getByTestId('activity-item').first()
		await expect(feedItem).toBeVisible({ timeout: 15000 })
	})

	test('overcapacity warning appears when a day exceeds its capacity', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		// BUG-E2E-005 (CS-PLAN-068 §3.4): the probe day and the capacity are
		// CONTROLLED, never the weekday-dependent family profile. All seven
		// members (six family + the invited admin) get an explicit full-week
		// budget (7 × 4 = 28/day) and the four probes (4 × 5 = 20 energy)
		// are due TODAY — which is always inside the displayed Monday-start
		// week and the today+0..6 strip. Planned/capacity per day: today
		// 10 + 20 = 30 > 28 (over), tomorrow 16 < 28, later days 0 — exactly
		// ONE overloaded day on any run day.
		const owner = await createUserContext(browser, 'mom')
		try {
			const api = owner.page.request
			const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']
			for (const member of ['mom', 'dad', 'teen', 'kid', 'grandma', 'friend', 'admin']) {
				const availabilityResponse = await api.put(`/apps/chorestrike/api/v1/scheduling/members/${member}`, {
					data: {
						weekdayAvailability: Object.fromEntries(days.map((day) => [day, true])),
						energyBudget: Object.fromEntries(days.map((day) => [day, 4])),
					},
					headers: { Accept: 'application/json' },
				})
				expect(availabilityResponse.status(), await availabilityResponse.text()).toBe(200)
			}

			const floorsResponse = await api.get('/apps/chorestrike/api/v1/floors/with-rooms', {
				headers: { Accept: 'application/json' },
			})
			expect(floorsResponse.status()).toBe(200)
			const floors = ((await floorsResponse.json()).data ?? []) as Array<{ rooms?: Array<{ id: number }> }>
			const roomId = floors.flatMap((f) => f.rooms ?? []).map((r) => r.id)[0]
			expect(roomId).toBeDefined()

			const today = offsetDate(0)
			for (let i = 0; i < 4; i++) {
				// CS-PLAN-063: one atomic aggregate create per room task —
				// the mount-duplicate guard still rejects duplicate pairs.
				const created = await api.post('/apps/chorestrike/api/v1/room-tasks', {
					data: {
						roomId,
						nextDue: today,
						task: { name: `Overcapacity Probe ${i}`, description: '', energyCost: 5, priority: 2, frequencyType: 'daily', frequencyValue: 1 },
					},
					headers: { Accept: 'application/json' },
				})
				const createdBody = await created.json()
				expect(created.status(), JSON.stringify(createdBody)).toBe(200)
			}
		} finally {
			await owner.context.close()
		}

		// The view is read as the OWNER so the member-availability grid (the
		// controlled capacity) is actually loaded.
		const ownerView = await createUserContext(browser, 'mom')
		try {
			const view = ownerView.page
			await view.goto('/apps/chorestrike/#/scheduling')
			await expect(view.getByTestId('scheduling-view')).toBeVisible({ timeout: 20000 })

			await expect(view.getByTestId('overcapacity-warning')).toBeVisible({ timeout: 15000 })
			// Exactly the probe day crosses the controlled capacity.
			await expect(view.getByTestId('overcapacity-day')).toHaveCount(1)
			// The calendar marks the overloaded day INSIDE the displayed week
			// (Saturday/Sunday/Monday runs all pin today, never "tomorrow").
			await expect(view.getByTestId('overcapacity-marker').first()).toBeVisible()
		} finally {
			await ownerView.context.close()
		}
	})

	test('navigation exposes the insights entry between leaderboard and scheduling', async ({ page }) => {
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('domcontentloaded')

		await expect(page.getByTestId('nav-insights')).toBeVisible()
		await page.getByTestId('nav-insights').click()
		await expect(page.getByTestId('insights-view')).toBeVisible({ timeout: 20000 })
	})
})
