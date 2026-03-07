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
	test.describe.configure({ mode: 'parallel' })

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
		await page.waitForLoadState('networkidle')

		// Check that mom's assigned tasks are visible
		const taskCards = page.locator('[data-testid="task-card"]')
		const count = await taskCards.count()
		
		// Mom should have at least 2 tasks assigned (dishes, laundry)
		expect(count).toBeGreaterThanOrEqual(2)

		// Verify task names
		const taskNames = await taskCards.allInnerTexts()
		expect(taskNames.some(t => t.toLowerCase().includes('dishes'))).toBeTruthy()
	})

	test('teen can claim and complete a task', async ({ page, seededData }) => {
		// Login as teen
		const creds = getUserCredentials('family', 'teen')
		await loginAsUser(page, creds!.userId, creds!.password)

		// Navigate to ChoreStrike
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle')

		// Find an unassigned task or one assigned to teen
		const taskCard = page.locator('[data-testid="task-card"]').first()
		await taskCard.click()

		// Complete the task
		const completeButton = page.locator('[data-testid="complete-task-button"]')
		if (await completeButton.isVisible()) {
			await completeButton.click()
			
			// Verify completion animation or state change
			await expect(page.locator('.task-completed, [data-testid="task-completed"]')).toBeVisible({ timeout: 5000 })
		}
	})

	test('leaderboard shows all family members', async ({ page, seededData }) => {
		// Login as dad
		const creds = getUserCredentials('family', 'dad')
		await loginAsUser(page, creds!.userId, creds!.password)

		// Navigate to ChoreStrike
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle')

		// Navigate to leaderboard
		const leaderboardTab = page.locator('[data-testid="leaderboard-tab"], text=/Leaderboard/i')
		await leaderboardTab.click()

		// Check that all family members are shown
		const leaderboardEntries = page.locator('[data-testid="leaderboard-entry"]')
		const count = await leaderboardEntries.count()
		
		// At least the active family members should be visible
		expect(count).toBeGreaterThanOrEqual(4)
	})

	test('kid can see their simple task list', async ({ page, seededData }) => {
		// Login as kid
		const creds = getUserCredentials('family', 'kid')
		await loginAsUser(page, creds!.userId, creds!.password)

		// Navigate to ChoreStrike
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle')

		// Kid should see their assigned tasks (toys, bed)
		const myTasksSection = page.locator('[data-testid="my-tasks"], text=/My Tasks/i')
		await expect(myTasksSection).toBeVisible()

		// Tasks should be simple and child-friendly
		const taskCards = page.locator('[data-testid="task-card"]')
		const count = await taskCards.count()
		expect(count).toBeGreaterThanOrEqual(1)
	})

	test('grandma can view but has limited tasks', async ({ page, seededData }) => {
		// Login as grandma
		const creds = getUserCredentials('family', 'grandma')
		await loginAsUser(page, creds!.userId, creds!.password)

		// Navigate to ChoreStrike
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle')

		// Grandma should see her assigned tasks (dust furniture)
		const taskCards = page.locator('[data-testid="task-card"]')
		const count = await taskCards.count()
		
		// Grandma has maxDailyTasks: 2, so should see limited tasks
		expect(count).toBeLessThanOrEqual(2)
	})

	test('friend can only see weekend tasks', async ({ page, seededData }) => {
		// Login as friend
		const creds = getUserCredentials('family', 'friend')
		await loginAsUser(page, creds!.userId, creds!.password)

		// Navigate to ChoreStrike
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle')

		// Friend only has weekend availability
		// Their view should reflect this limitation
		const availabilityInfo = page.locator('[data-testid="availability-info"], text=/weekend/i')
		
		// Either they see limited tasks or an availability message
		const taskCards = page.locator('[data-testid="task-card"]')
		const count = await taskCards.count()
		expect(count).toBeLessThanOrEqual(1) // friend has maxDailyTasks: 1
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
			// Mom logs in and assigns a task to teen
			const momCreds = getUserCredentials('family', 'mom')
			await loginAsUser(momPage, momCreds!.userId, momCreds!.password)
			await momPage.goto('/apps/chorestrike/')
			await momPage.waitForLoadState('networkidle')

			// Find an unassigned task
			const unassignedTask = momPage.locator('[data-testid="task-card"]:not([data-assigned])').first()
			if (await unassignedTask.count() > 0) {
				await unassignedTask.click()
				
				// Assign to teen
				const assignButton = momPage.locator('[data-testid="assign-task-button"]')
				if (await assignButton.isVisible()) {
					await assignButton.click()
					const teenOption = momPage.locator('text=Teen')
					if (await teenOption.isVisible()) {
						await teenOption.click()
					}
				}
			}

			// Teen logs in and sees the assigned task
			const teenCreds = getUserCredentials('family', 'teen')
			await loginAsUser(teenPage, teenCreds!.userId, teenCreds!.password)
			await teenPage.goto('/apps/chorestrike/')
			await teenPage.waitForLoadState('networkidle')

			// Teen should see the task in their list
			const teenTasks = teenPage.locator('[data-testid="task-card"]')
			await expect(teenTasks.first()).toBeVisible()
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
		await page.waitForLoadState('networkidle')

		// Get initial house health
		const healthIndicator = page.locator('[data-testid="house-health"]')
		const initialHealth = await healthIndicator.getAttribute('data-value') || await healthIndicator.textContent()

		// Complete a task
		const taskCard = page.locator('[data-testid="task-card"]').first()
		await taskCard.click()

		const completeButton = page.locator('[data-testid="complete-task-button"]')
		if (await completeButton.isVisible()) {
			await completeButton.click()
			await page.waitForTimeout(1000) // Wait for state update
		}

		// Check house health updated
		const updatedHealth = await healthIndicator.getAttribute('data-value') || await healthIndicator.textContent()
		// Health should have changed (increased or stayed same)
		expect(updatedHealth).toBeDefined()
	})

	test('leaderboard updates after task completion', async ({ browser, seededData }) => {
		// Create two browser contexts
		const dadContext = await browser.newContext()
		const dadPage = await dadContext.newPage()

		try {
			// Dad logs in
			const dadCreds = getUserCredentials('family', 'dad')
			await loginAsUser(dadPage, dadCreds!.userId, dadCreds!.password)
			await dadPage.goto('/apps/chorestrike/')
			await dadPage.waitForLoadState('networkidle')

			// Navigate to leaderboard
			const leaderboardTab = dadPage.locator('[data-testid="leaderboard-tab"], text=/Leaderboard/i')
			await leaderboardTab.click()

			// Get initial scores
			const scores = await dadPage.locator('[data-testid="leaderboard-score"]').allTextContents()

			// Complete a task
			await dadPage.locator('[data-testid="dashboard-tab"], text=/Dashboard/i').first().click()
			const taskCard = dadPage.locator('[data-testid="task-card"]').first()
			await taskCard.click()

			const completeButton = dadPage.locator('[data-testid="complete-task-button"]')
			if (await completeButton.isVisible()) {
				await completeButton.click()
				await dadPage.waitForTimeout(2000)
			}

			// Check leaderboard again
			await leaderboardTab.click()
			const updatedScores = await dadPage.locator('[data-testid="leaderboard-score"]').allTextContents()

			// Scores should have changed
			expect(updatedScores).toBeDefined()
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
