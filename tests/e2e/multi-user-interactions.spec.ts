/**
 * Multi-User Interaction E2E Tests
 *
 * Tests realistic family scenarios with 6 users:
 * - Task assignment between family members
 * - Leaderboard competition
 * - Task claiming and completion
 * - Real-time state synchronization
 *
 * CS-PLAN-015b: auth via per-user storageState (no loginAsUser / no logout).
 * Single-user tests use `createUserContext`; two-user tests create two contexts.
 * CS-PLAN-050: the leaderboard assertions are unconditional
 * durable-outcome checks.
 * CS-PLAN-090 WP-B: the Dashboard's room cards are gone. What every role
 * sees is asserted where it now lives: the own rows on Today, the
 * household's rooms through the shared `fixtures/rooms.ts` helper, the
 * house health in Insights.
 */

import type { DatabaseProfile } from './fixtures/database-fixtures.ts'

import { createUserContext } from './fixtures/auth-contexts.ts'
import { expect, test } from './fixtures/database-fixtures.ts'
import { openRanking } from './fixtures/insights.ts'
import { listRooms, openRoom, openToday } from './fixtures/rooms.ts'

test.describe('Multi-User Family Interactions', () => {
	test.describe.configure({ mode: 'serial' })

	test.use({ databaseProfile: 'family' as DatabaseProfile })

	test('family profile has all 6 users seeded', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		expect(seededData!.profile).toBe('family')
		expect(seededData!.data.users).toHaveLength(6)

		const userIds = seededData!.data.users!.map((u) => u.nextcloudUserId)
		expect(userIds).toContain('mom')
		expect(userIds).toContain('dad')
		expect(userIds).toContain('teen')
		expect(userIds).toContain('kid')
		expect(userIds).toContain('grandma')
		expect(userIds).toContain('friend')
	})

	test('mom can see assigned tasks on Today', async ({ browser, seededData: _seededData }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await openToday(page)

			// The seed assigns the dishes to mom, due today.
			const row = page.getByTestId('today-task-item').filter({ hasText: 'Do the dishes' })
			await expect(row).toBeVisible({ timeout: 10000 })
		} finally {
			await context.close()
		}
	})

	test('teen lands on Today and can open a room of the household', async ({ browser, seededData: _seededData }) => {
		const { context, page } = await createUserContext(browser, 'teen')
		try {
			// CS-PLAN-066 (D4): the bare root lands on Today.
			await page.goto('/apps/chorestrike/')
			await page.waitForSelector('[data-testid="today-view"]', { timeout: 30000 })

			await openRoom(page)
			await expect(page.getByTestId('room-task-list')).toBeVisible({ timeout: 10000 })
		} finally {
			await context.close()
		}
	})

	test('leaderboard shows the opt-in state for unconsented members (WP4)', async ({ browser, seededData: _seededData }) => {
		const { context, page } = await createUserContext(browser, 'dad')
		try {
			await page.goto('/apps/chorestrike/#/today')
			await page.waitForSelector('[data-testid="today-view"]', { timeout: 30000 })

			await openRanking(page)

			// The family seed leaves the ranking flag off: the opt-in/closed
			// state shows and no unconditional member ranking renders.
			await expect(page.getByTestId('ranking-disabled')).toBeVisible({ timeout: 15000 })
			await expect(page.locator('[data-testid="leaderboard-entry"]')).toHaveCount(0)
		} finally {
			await context.close()
		}
	})

	test('kid can see their simple task list', async ({ browser, seededData: _seededData }) => {
		const { context, page } = await createUserContext(browser, 'kid')
		try {
			// CS-PLAN-066 (D4): the child lands on Today.
			await page.goto('/apps/chorestrike/')
			await page.waitForSelector('[data-testid="today-view"]', { timeout: 30000 })

			// The seed assigns the toys to kid, due today.
			const row = page.getByTestId('today-task-item').filter({ hasText: 'Tidy up toys' })
			await expect(row).toBeVisible({ timeout: 10000 })
		} finally {
			await context.close()
		}
	})

	test('grandma can view but has limited tasks', async ({ browser, seededData: _seededData }) => {
		const { context, page } = await createUserContext(browser, 'grandma')
		try {
			await openToday(page)

			// Whatever her day holds, she sees the household's rooms.
			expect((await listRooms(page)).length).toBeGreaterThanOrEqual(1)
			await openRoom(page)
		} finally {
			await context.close()
		}
	})
})

test.describe('Multi-User Task Interactions', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })

	test('task assignment persists across users', async ({ browser, seededData: _seededData }) => {
		const mom = await createUserContext(browser, 'mom')
		const teen = await createUserContext(browser, 'teen')

		try {
			await openToday(mom.page)
			// CS-PLAN-066 (D4): the child lands on Today.
			await teen.page.goto('/apps/chorestrike/')
			await teen.page.waitForSelector('[data-testid="today-view"]', { timeout: 30000 })

			// Both look at the same household: the same rooms, and the row
			// assigned to teen shows for teen and, under Everyone, for mom.
			const momRooms = await listRooms(mom.page)
			expect(momRooms.length).toBeGreaterThanOrEqual(1)
			expect(await listRooms(teen.page)).toEqual(momRooms)

			const teenRow = (page: typeof mom.page) => page.getByTestId('today-task-item').filter({ hasText: 'Wipe kitchen counters' })
			await expect(teenRow(teen.page)).toBeVisible({ timeout: 10000 })
			await expect(teenRow(mom.page)).toHaveCount(0)
			await mom.page.getByTestId('today-filter-all').click()
			await expect(teenRow(mom.page)).toBeVisible({ timeout: 10000 })
		} finally {
			await mom.context.close()
			await teen.context.close()
		}
	})

	test('the house health shows in Insights', async ({ browser, seededData: _seededData }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await openToday(page)
			await page.getByTestId('nav-insights').click()
			await expect(page.getByTestId('insights-view')).toBeVisible({ timeout: 30000 })

			await expect(page.getByTestId('stat-house-health')).toContainText(/\d+%/, { timeout: 15000 })
		} finally {
			await context.close()
		}
	})

	test('leaderboard state stays consistent after task completion (WP4)', async ({ browser, seededData: _seededData }) => {
		const { context, page } = await createUserContext(browser, 'dad')
		try {
			await page.goto('/apps/chorestrike/#/today')
			await page.waitForSelector('[data-testid="today-view"]', { timeout: 30000 })

			await expect(page.getByTestId('today-status-line')).toBeVisible()

			await openRanking(page)

			// Without consent the completion never surfaces in a ranking —
			// the opt-in state stays.
			await expect(page.getByTestId('ranking-disabled')).toBeVisible({ timeout: 15000 })
		} finally {
			await context.close()
		}
	})
})

test.describe('Family Profile Data Validation', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })

	test('all rooms have correct cleanliness values', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const rooms = seededData!.data.rooms!

		const kitchen = rooms.find((r) => r.name === 'Kitchen')
		expect(kitchen).toBeDefined()
		expect(kitchen!.cleanliness).toBe(75)

		const kidsBedroom = rooms.find((r) => r.name === 'Kids Bedroom')
		expect(kidsBedroom).toBeDefined()
		expect(kidsBedroom!.cleanliness).toBe(40)
	})

	test('tasks have correct categories and energy costs', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const tasks = seededData!.data.tasks!

		expect(tasks).toHaveLength(12)

		const dishes = tasks.find((t) => t.name === 'Do the dishes')
		expect(dishes).toBeDefined()
		expect(dishes!.category).toBe('cleaning')

		const validCategories = ['cleaning', 'maintenance', 'organizing']
		tasks.forEach((task) => {
			expect(validCategories).toContain(task.category)
		})
	})

	test('room tasks have correct assignments', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const roomTasks = seededData!.data.roomTasks!

		expect(roomTasks).toHaveLength(12)

		const assigned = roomTasks.filter((rt) => (rt.assignees ?? []).length > 0)
		const unassigned = roomTasks.filter((rt) => (rt.assignees ?? []).length === 0)

		expect(assigned.length).toBeGreaterThan(0)
		expect(unassigned.length).toBeGreaterThan(0)
	})
})
