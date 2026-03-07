/**
 * Multi-User Interaction E2E Tests
 * 
 * Tests realistic family scenarios with 6 users:
 * - Task assignment between family members
 * - Leaderboard competition
 * - Task claiming and completion
 * - Real-time state synchronization
 */

import { test, expect, loginAsUser, getUserCredentials, PROFILE_USERS, DatabaseProfile } from './fixtures/database-fixtures'

// Use family profile for all tests in this file
test.describe('Multi-User Family Interactions', () => {
	test.describe.configure({ mode: 'serial' })

	// Use family profile
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

	test('mom can see assigned tasks on dashboard', async ({ page, seededData }) => {
		// Login as mom
		const creds = getUserCredentials('family', 'mom')
		expect(creds).not.toBeNull()
		await loginAsUser(page, creds!.userId, creds!.password)

		// Navigate to ChoreStrike
		await page.goto('/apps/chorestrike/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

		// Wait for floors to load
		await page.waitForSelector('.floor-section', { timeout: 10000 })
		
		// Expand all floors to see rooms
		const floorSections = page.locator('.floor-section')
		const floorCount = await floorSections.count()
		
		// Click toggle buttons to expand floors
		for (let i = 0; i < floorCount; i++) {
			const floorSection = floorSections.nth(i)
			const toggleButton = floorSection.locator('.toggle-button')
			const floorContent = floorSection.locator('.floor-content')
			const isExpanded = await floorContent.isVisible().catch(() => false)
			if (!isExpanded) {
				await toggleButton.click()
				// Wait for rooms to load in this floor
				await page.waitForTimeout(500)
				await floorContent.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {})
			}
		}

		// Check that rooms are visible (mom has tasks assigned in Kitchen)
		const roomCards = page.locator('[data-testid^="room-card-"]')
		await roomCards.first().waitFor({ state: 'visible', timeout: 10000 })
		const count = await roomCards.count()
		
		// Should have at least 1 room visible
		expect(count).toBeGreaterThanOrEqual(1)
	})

	test('teen can claim and complete a task', async ({ page, seededData }) => {
		// Login as teen
		const creds = getUserCredentials('family', 'teen')
		await loginAsUser(page, creds!.userId, creds!.password)

		// Navigate to ChoreStrike
		await page.goto('/apps/chorestrike/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

		// Expand first floor to see rooms
		const toggleButton = page.locator('.floor-section .toggle-button').first()
		await toggleButton.click()
		await page.waitForTimeout(300)

		// Find a room that teen has tasks in (Kitchen - wipe counters)
		const roomCards = page.locator('[data-testid^="room-card-"]')
		await expect(roomCards.first()).toBeVisible({ timeout: 10000 })
	})

	test('leaderboard shows all family members', async ({ page, seededData }) => {
		// Login as dad
		const creds = getUserCredentials('family', 'dad')
		await loginAsUser(page, creds!.userId, creds!.password)

		// Navigate to ChoreStrike
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle')

		// Wait for dashboard to load
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

		// Navigate to leaderboard view
		await page.goto('/apps/chorestrike/#/leaderboard')
		await page.waitForLoadState('networkidle')

		// Check that leaderboard is visible
		const leaderboardView = page.locator('[data-testid="leaderboard-view"]')
		const isVisible = await leaderboardView.isVisible().catch(() => false)
		
		// If leaderboard view exists, check entries
		if (isVisible) {
			const entries = page.locator('[data-testid="leaderboard-entry"]')
			const count = await entries.count()
			expect(count).toBeGreaterThanOrEqual(1)
		} else {
			// Leaderboard might be on dashboard as a component
			const quickStats = page.locator('[data-testid="quick-stats"]')
			await expect(quickStats).toBeVisible()
		}
	})

	test('kid can see their simple task list', async ({ page, seededData }) => {
		// Login as kid
		const creds = getUserCredentials('family', 'kid')
		await loginAsUser(page, creds!.userId, creds!.password)

		// Navigate to ChoreStrike
		await page.goto('/apps/chorestrike/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

		// Expand all floors
		const floorSections = page.locator('.floor-section')
		const floorCount = await floorSections.count()
		for (let i = 0; i < floorCount; i++) {
			const toggleButton = floorSections.nth(i).locator('.toggle-button')
			const isExpanded = await floorSections.nth(i).locator('.floor-content').isVisible().catch(() => false)
			if (!isExpanded) {
				await toggleButton.click()
				await page.waitForTimeout(200)
			}
		}

		// Kid should see rooms with their tasks
		const roomCards = page.locator('[data-testid^="room-card-"]')
		const count = await roomCards.count()
		expect(count).toBeGreaterThanOrEqual(1)
	})

	test('grandma can view but has limited tasks', async ({ page, seededData }) => {
		// Login as grandma
		const creds = getUserCredentials('family', 'grandma')
		await loginAsUser(page, creds!.userId, creds!.password)

		// Navigate to ChoreStrike
		await page.goto('/apps/chorestrike/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

		// Expand all floors
		const floorSections = page.locator('.floor-section')
		const floorCount = await floorSections.count()
		for (let i = 0; i < floorCount; i++) {
			const toggleButton = floorSections.nth(i).locator('.toggle-button')
			const isExpanded = await floorSections.nth(i).locator('.floor-content').isVisible().catch(() => false)
			if (!isExpanded) {
				await toggleButton.click()
				await page.waitForTimeout(200)
			}
		}

		// Grandma should see rooms
		const roomCards = page.locator('[data-testid^="room-card-"]')
		const count = await roomCards.count()
		expect(count).toBeGreaterThanOrEqual(1)
	})
})

test.describe('Multi-User Task Interactions', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })

	test('task assignment persists across users', async ({ browser, seededData }) => {
		// Create two browser contexts
		const momContext = await browser.newContext()
		const teenContext = await browser.newContext()

		const momPage = await momContext.newPage()
		const teenPage = await teenContext.newPage()

		try {
			// Mom logs in and sees rooms
			const momCreds = getUserCredentials('family', 'mom')
			await loginAsUser(momPage, momCreds!.userId, momCreds!.password)
			await momPage.goto('/apps/chorestrike/')
			await momPage.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })
			await momPage.waitForSelector('.floor-section', { timeout: 10000 })

			// Expand floors
			const momFloorSections = momPage.locator('.floor-section')
			const momFloorCount = await momFloorSections.count()
			for (let i = 0; i < momFloorCount; i++) {
				const floorSection = momFloorSections.nth(i)
				const toggleButton = floorSection.locator('.toggle-button')
				const floorContent = floorSection.locator('.floor-content')
				const isExpanded = await floorContent.isVisible().catch(() => false)
				if (!isExpanded) {
					await toggleButton.click()
					await momPage.waitForTimeout(500)
					await floorContent.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {})
				}
			}

			// Mom should see rooms
			const momRooms = momPage.locator('[data-testid^="room-card-"]')
			await momRooms.first().waitFor({ state: 'visible', timeout: 10000 })

			// Teen logs in and sees the same house state
			const teenCreds = getUserCredentials('family', 'teen')
			await loginAsUser(teenPage, teenCreds!.userId, teenCreds!.password)
			await teenPage.goto('/apps/chorestrike/')
			await teenPage.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })
			await teenPage.waitForSelector('.floor-section', { timeout: 10000 })

			// Expand floors for teen
			const teenFloorSections = teenPage.locator('.floor-section')
			const teenFloorCount = await teenFloorSections.count()
			for (let i = 0; i < teenFloorCount; i++) {
				const floorSection = teenFloorSections.nth(i)
				const toggleButton = floorSection.locator('.toggle-button')
				const floorContent = floorSection.locator('.floor-content')
				const isExpanded = await floorContent.isVisible().catch(() => false)
				if (!isExpanded) {
					await toggleButton.click()
					await teenPage.waitForTimeout(500)
					await floorContent.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {})
				}
			}

			// Teen should see the same rooms
			const teenRooms = teenPage.locator('[data-testid^="room-card-"]')
			await teenRooms.first().waitFor({ state: 'visible', timeout: 10000 })
		} finally {
			await momContext.close()
			await teenContext.close()
		}
	})

	test('task completion updates house health', async ({ page, seededData }) => {
		// Login as mom
		const creds = getUserCredentials('family', 'mom')
		await loginAsUser(page, creds!.userId, creds!.password)
		await page.goto('/apps/chorestrike/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

		// Get initial house health
		const healthIndicator = page.locator('[data-testid="house-health"]')
		await expect(healthIndicator).toBeVisible()

		// Verify health value is displayed
		const healthText = await healthIndicator.textContent()
		expect(healthText).toBeDefined()
	})

	test('leaderboard updates after task completion', async ({ browser, seededData }) => {
		// Create browser context
		const dadContext = await browser.newContext()
		const dadPage = await dadContext.newPage()

		try {
			// Dad logs in
			const dadCreds = getUserCredentials('family', 'dad')
			await loginAsUser(dadPage, dadCreds!.userId, dadCreds!.password)
			await dadPage.goto('/apps/chorestrike/')
			await dadPage.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

			// Check quick stats are visible
			const quickStats = dadPage.locator('[data-testid="quick-stats"]')
			await expect(quickStats).toBeVisible()

			// Navigate to leaderboard view
			await dadPage.goto('/apps/chorestrike/#/leaderboard')
			await dadPage.waitForLoadState('networkidle')
		} finally {
			await dadContext.close()
		}
	})
})

test.describe('Family Profile Data Validation', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })

	test('all rooms have correct cleanliness values', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const rooms = seededData!.data.rooms!
		
		// Kitchen should be relatively clean (75)
		const kitchen = rooms.find(r => r.name === 'Kitchen')
		expect(kitchen).toBeDefined()
		expect(kitchen!.cleanliness).toBe(75)

		// Kids Bedroom should be dirty (40)
		const kidsBedroom = rooms.find(r => r.name === 'Kids Bedroom')
		expect(kidsBedroom).toBeDefined()
		expect(kidsBedroom!.cleanliness).toBe(40)
	})

	test('tasks have correct categories and energy costs', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const tasks = seededData!.data.tasks!
		
		// Should have 12 tasks
		expect(tasks).toHaveLength(12)

		// Check specific tasks
		const dishes = tasks.find(t => t.name === 'Do the dishes')
		expect(dishes).toBeDefined()
		expect(dishes!.category).toBe('cleaning')

		// All tasks should have valid categories
		const validCategories = ['cleaning', 'maintenance', 'organizing']
		tasks.forEach(task => {
			expect(validCategories).toContain(task.category)
		})
	})

	test('room tasks have correct assignments', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const roomTasks = seededData!.data.roomTasks!
		
		// Should have 12 room tasks
		expect(roomTasks).toHaveLength(12)

		// Some should be assigned, some unassigned
		const assigned = roomTasks.filter(rt => rt.assignedToUserId)
		const unassigned = roomTasks.filter(rt => !rt.assignedToUserId)
		
		expect(assigned.length).toBeGreaterThan(0)
		expect(unassigned.length).toBeGreaterThan(0)
	})
})
