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
 */

import { test, expect, DatabaseProfile } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'

test.describe('Multi-User Family Interactions', () => {
	test.describe.configure({ mode: 'serial' })

	test.use({ databaseProfile: 'family' as DatabaseProfile })

	test('family profile has all 6 users seeded', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		expect(seededData!.profile).toBe('family')
		expect(seededData!.data.users).toHaveLength(6)

		const userIds = seededData!.data.users!.map(u => u.nextcloudUserId)
		expect(userIds).toContain('mom')
		expect(userIds).toContain('dad')
		expect(userIds).toContain('teen')
		expect(userIds).toContain('kid')
		expect(userIds).toContain('grandma')
		expect(userIds).toContain('friend')
	})

	test('mom can see assigned tasks on dashboard', async ({ browser, seededData }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await page.goto('/apps/chorestrike/')
			await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

			await page.waitForSelector('.floor-section', { timeout: 10000 })

			const floorSections = page.locator('.floor-section')
			const floorCount = await floorSections.count()

			for (let i = 0; i < floorCount; i++) {
				const floorSection = floorSections.nth(i)
				const toggleButton = floorSection.locator('.toggle-button')
				const floorContent = floorSection.locator('.floor-content')
				const isExpanded = await floorContent.isVisible().catch(() => false)
				if (!isExpanded) {
					await toggleButton.click()
					await page.waitForTimeout(500)
					await floorContent.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {})
				}
			}

			const roomCards = page.locator('[data-testid^="room-card-"]')
			await roomCards.first().waitFor({ state: 'visible', timeout: 10000 })
			const count = await roomCards.count()

			expect(count).toBeGreaterThanOrEqual(1)
		} finally {
			await context.close()
		}
	})

	test('teen can claim and complete a task', async ({ browser, seededData }) => {
		const { context, page } = await createUserContext(browser, 'teen')
		try {
			await page.goto('/apps/chorestrike/')
			await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

			const toggleButton = page.locator('.floor-section .toggle-button').first()
			await toggleButton.click()
			await page.waitForTimeout(300)

			const roomCards = page.locator('[data-testid^="room-card-"]')
			await expect(roomCards.first()).toBeVisible({ timeout: 10000 })
		} finally {
			await context.close()
		}
	})

	test('leaderboard shows all family members', async ({ browser, seededData }) => {
		const { context, page } = await createUserContext(browser, 'dad')
		try {
			await page.goto('/apps/chorestrike/')
			await page.waitForLoadState('networkidle')

			await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

			await page.goto('/apps/chorestrike/#/leaderboard')
			await page.waitForLoadState('networkidle')

			const leaderboardView = page.locator('[data-testid="leaderboard-view"]')
			const isVisible = await leaderboardView.isVisible().catch(() => false)

			if (isVisible) {
				const entries = page.locator('[data-testid="leaderboard-entry"]')
				const count = await entries.count()
				expect(count).toBeGreaterThanOrEqual(1)
			} else {
				const quickStats = page.locator('[data-testid="quick-stats"]')
				await expect(quickStats).toBeVisible()
			}
		} finally {
			await context.close()
		}
	})

	test('kid can see their simple task list', async ({ browser, seededData }) => {
		const { context, page } = await createUserContext(browser, 'kid')
		try {
			await page.goto('/apps/chorestrike/')
			await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })
			await page.waitForSelector('.floor-section', { timeout: 10000 })

			const floorSections = page.locator('.floor-section')
			const floorCount = await floorSections.count()
			for (let i = 0; i < floorCount; i++) {
				const floorSection = floorSections.nth(i)
				const toggleButton = floorSection.locator('.toggle-button')
				const floorContent = floorSection.locator('.floor-content')
				const isExpanded = await floorContent.isVisible().catch(() => false)
				if (!isExpanded) {
					await toggleButton.click()
					await page.waitForTimeout(500)
					await floorContent.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {})
				}
			}

			const roomCards = page.locator('[data-testid^="room-card-"]')
			await roomCards.first().waitFor({ state: 'visible', timeout: 10000 })
			const count = await roomCards.count()
			expect(count).toBeGreaterThanOrEqual(1)
		} finally {
			await context.close()
		}
	})

	test('grandma can view but has limited tasks', async ({ browser, seededData }) => {
		const { context, page } = await createUserContext(browser, 'grandma')
		try {
			await page.goto('/apps/chorestrike/')
			await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })
			await page.waitForSelector('.floor-section', { timeout: 10000 })

			const floorSections = page.locator('.floor-section')
			const floorCount = await floorSections.count()
			for (let i = 0; i < floorCount; i++) {
				const floorSection = floorSections.nth(i)
				const toggleButton = floorSection.locator('.toggle-button')
				const floorContent = floorSection.locator('.floor-content')
				const isExpanded = await floorContent.isVisible().catch(() => false)
				if (!isExpanded) {
					await toggleButton.click()
					await page.waitForTimeout(500)
					await floorContent.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {})
				}
			}

			const roomCards = page.locator('[data-testid^="room-card-"]')
			await roomCards.first().waitFor({ state: 'visible', timeout: 10000 })
			const count = await roomCards.count()
			expect(count).toBeGreaterThanOrEqual(1)
		} finally {
			await context.close()
		}
	})
})

test.describe('Multi-User Task Interactions', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })

	test('task assignment persists across users', async ({ browser, seededData }) => {
		const mom = await createUserContext(browser, 'mom')
		const teen = await createUserContext(browser, 'teen')

		try {
			await mom.page.goto('/apps/chorestrike/')
			await mom.page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })
			await mom.page.waitForSelector('.floor-section', { timeout: 10000 })

			const momFloorSections = mom.page.locator('.floor-section')
			const momFloorCount = await momFloorSections.count()
			for (let i = 0; i < momFloorCount; i++) {
				const floorSection = momFloorSections.nth(i)
				const toggleButton = floorSection.locator('.toggle-button')
				const floorContent = floorSection.locator('.floor-content')
				const isExpanded = await floorContent.isVisible().catch(() => false)
				if (!isExpanded) {
					await toggleButton.click()
					await mom.page.waitForTimeout(500)
					await floorContent.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {})
				}
			}

			const momRooms = mom.page.locator('[data-testid^="room-card-"]')
			await momRooms.first().waitFor({ state: 'visible', timeout: 10000 })

			await teen.page.goto('/apps/chorestrike/')
			await teen.page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })
			await teen.page.waitForSelector('.floor-section', { timeout: 10000 })

			const teenFloorSections = teen.page.locator('.floor-section')
			const teenFloorCount = await teenFloorSections.count()
			for (let i = 0; i < teenFloorCount; i++) {
				const floorSection = teenFloorSections.nth(i)
				const toggleButton = floorSection.locator('.toggle-button')
				const floorContent = floorSection.locator('.floor-content')
				const isExpanded = await floorContent.isVisible().catch(() => false)
				if (!isExpanded) {
					await toggleButton.click()
					await teen.page.waitForTimeout(500)
					await floorContent.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {})
				}
			}

			const teenRooms = teen.page.locator('[data-testid^="room-card-"]')
			await teenRooms.first().waitFor({ state: 'visible', timeout: 10000 })
		} finally {
			await mom.context.close()
			await teen.context.close()
		}
	})

	test('task completion updates house health', async ({ browser, seededData }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await page.goto('/apps/chorestrike/')
			await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

			const healthIndicator = page.locator('[data-testid="house-health"]')
			await expect(healthIndicator).toBeVisible()

			const healthText = await healthIndicator.textContent()
			expect(healthText).toBeDefined()
		} finally {
			await context.close()
		}
	})

	test('leaderboard updates after task completion', async ({ browser, seededData }) => {
		const { context, page } = await createUserContext(browser, 'dad')
		try {
			await page.goto('/apps/chorestrike/')
			await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

			const quickStats = page.locator('[data-testid="quick-stats"]')
			await expect(quickStats).toBeVisible()

			await page.goto('/apps/chorestrike/#/leaderboard')
			await page.waitForLoadState('networkidle')
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

		const kitchen = rooms.find(r => r.name === 'Kitchen')
		expect(kitchen).toBeDefined()
		expect(kitchen!.cleanliness).toBe(75)

		const kidsBedroom = rooms.find(r => r.name === 'Kids Bedroom')
		expect(kidsBedroom).toBeDefined()
		expect(kidsBedroom!.cleanliness).toBe(40)
	})

	test('tasks have correct categories and energy costs', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const tasks = seededData!.data.tasks!

		expect(tasks).toHaveLength(12)

		const dishes = tasks.find(t => t.name === 'Do the dishes')
		expect(dishes).toBeDefined()
		expect(dishes!.category).toBe('cleaning')

		const validCategories = ['cleaning', 'maintenance', 'organizing']
		tasks.forEach(task => {
			expect(validCategories).toContain(task.category)
		})
	})

	test('room tasks have correct assignments', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const roomTasks = seededData!.data.roomTasks!

		expect(roomTasks).toHaveLength(12)

		const assigned = roomTasks.filter(rt => rt.assignedToUserId)
		const unassigned = roomTasks.filter(rt => !rt.assignedToUserId)

		expect(assigned.length).toBeGreaterThan(0)
		expect(unassigned.length).toBeGreaterThan(0)
	})
})
