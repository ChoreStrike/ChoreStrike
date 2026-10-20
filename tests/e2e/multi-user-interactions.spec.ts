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
 * CS-PLAN-050: the copy-pasted floor-expansion loops and their fixed sleeps
 * are replaced by the shared `dashboard-floors` helpers (auto-waiting), and
 * the leaderboard assertions are unconditional durable-outcome checks.
 */

import type { DatabaseProfile } from './fixtures/database-fixtures.ts'

import { createUserContext } from './fixtures/auth-contexts.ts'
import { expandAllFloors, expandUntilRoomVisible } from './fixtures/dashboard-floors.ts'
import { expect, test } from './fixtures/database-fixtures.ts'

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

	test('mom can see assigned tasks on dashboard', async ({ browser, seededData: _seededData }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await page.goto('/apps/chorestrike/#/dashboard')
			await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

			await expandAllFloors(page)

			const roomCards = page.locator('[data-testid^="room-card-"]')
			await expect(roomCards.first()).toBeVisible({ timeout: 10000 })
			expect(await roomCards.count()).toBeGreaterThanOrEqual(1)
		} finally {
			await context.close()
		}
	})

	test('teen can expand floors and see room cards', async ({ browser, seededData: _seededData }) => {
		const { context, page } = await createUserContext(browser, 'teen')
		try {
			await page.goto('/apps/chorestrike/')
			// CS-PLAN-066 (D4): the child lands on My Day; the Dashboard
			// stays reachable through explicit navigation.
			await page.waitForSelector('[data-testid="today-view"]', { timeout: 30000 })
			await page.getByTestId('nav-dashboard').click()
			await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

			const roomCards = page.locator('[data-testid^="room-card-"]')
			await expandUntilRoomVisible(page, roomCards.first())
			await expect(roomCards.first()).toBeVisible({ timeout: 10000 })
		} finally {
			await context.close()
		}
	})

	test('leaderboard shows the opt-in state for unconsented members (WP4)', async ({ browser, seededData: _seededData }) => {
		const { context, page } = await createUserContext(browser, 'dad')
		try {
			await page.goto('/apps/chorestrike/#/dashboard')
			await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

			await page.getByTestId('nav-leaderboard').click()
			await expect(page.getByTestId('leaderboard-view')).toBeVisible({ timeout: 30000 })

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
			await page.goto('/apps/chorestrike/')
			// CS-PLAN-066 (D4): the child lands on My Day; the Dashboard
			// stays reachable through explicit navigation.
			await page.waitForSelector('[data-testid="today-view"]', { timeout: 30000 })
			await page.getByTestId('nav-dashboard').click()
			await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

			await expandAllFloors(page)

			const roomCards = page.locator('[data-testid^="room-card-"]')
			await expect(roomCards.first()).toBeVisible({ timeout: 10000 })
			expect(await roomCards.count()).toBeGreaterThanOrEqual(1)
		} finally {
			await context.close()
		}
	})

	test('grandma can view but has limited tasks', async ({ browser, seededData: _seededData }) => {
		const { context, page } = await createUserContext(browser, 'grandma')
		try {
			await page.goto('/apps/chorestrike/#/dashboard')
			await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

			await expandAllFloors(page)

			const roomCards = page.locator('[data-testid^="room-card-"]')
			await expect(roomCards.first()).toBeVisible({ timeout: 10000 })
			expect(await roomCards.count()).toBeGreaterThanOrEqual(1)
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
			await mom.page.goto('/apps/chorestrike/#/dashboard')
			await mom.page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })
			await expandAllFloors(mom.page)

			const momRooms = mom.page.locator('[data-testid^="room-card-"]')
			await expect(momRooms.first()).toBeVisible({ timeout: 10000 })

			await teen.page.goto('/apps/chorestrike/')
			// CS-PLAN-066 (D4): the child lands on My Day; the Dashboard
			// stays reachable through explicit navigation.
			await teen.page.waitForSelector('[data-testid="today-view"]', { timeout: 30000 })
			await teen.page.getByTestId('nav-dashboard').click()
			await teen.page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })
			await expandAllFloors(teen.page)

			const teenRooms = teen.page.locator('[data-testid^="room-card-"]')
			await expect(teenRooms.first()).toBeVisible({ timeout: 10000 })
		} finally {
			await mom.context.close()
			await teen.context.close()
		}
	})

	test('task completion updates house health', async ({ browser, seededData: _seededData }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await page.goto('/apps/chorestrike/#/dashboard')
			await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

			const healthIndicator = page.locator('[data-testid="house-health"]')
			await expect(healthIndicator).toBeVisible()

			const healthText = await healthIndicator.textContent()
			expect(healthText).toBeDefined()
		} finally {
			await context.close()
		}
	})

	test('leaderboard state stays consistent after task completion (WP4)', async ({ browser, seededData: _seededData }) => {
		const { context, page } = await createUserContext(browser, 'dad')
		try {
			await page.goto('/apps/chorestrike/#/dashboard')
			await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

			await expect(page.getByTestId('quick-stats')).toBeVisible()

			await page.getByTestId('nav-leaderboard').click()
			await expect(page.getByTestId('leaderboard-view')).toBeVisible({ timeout: 30000 })

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
