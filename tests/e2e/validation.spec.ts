/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 * 
 * E2E tests for data validation and edge cases
 */

import { test, expect, DatabaseProfile, loginAsUser, getUserCredentials } from './fixtures/database-fixtures'

test.describe.configure({ mode: 'serial' })

test.describe('Data Validation - Family Profile', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })

	test('all users have valid profiles', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const users = seededData!.data.users!
		expect(users).toHaveLength(6)

		users.forEach(user => {
			expect(user.displayName).toBeTruthy()
			expect(user.nextcloudUserId).toBeTruthy()
			expect(user.role).toBeTruthy()
		})
	})

	test('all rooms have valid cleanliness', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const rooms = seededData!.data.rooms!
		expect(rooms.length).toBeGreaterThanOrEqual(1)

		rooms.forEach(room => {
			expect(room.name).toBeTruthy()
			expect(room.cleanliness).toBeGreaterThanOrEqual(0)
			expect(room.cleanliness).toBeLessThanOrEqual(100)
		})
	})

	test('all tasks have valid categories', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const tasks = seededData!.data.tasks!
		expect(tasks).toHaveLength(12)

		tasks.forEach(task => {
			expect(task.name).toBeTruthy()
			expect(['cleaning', 'maintenance', 'organizing']).toContain(task.category)
		})
	})

	test('all room tasks have valid assignments', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const roomTasks = seededData!.data.roomTasks!
		expect(roomTasks.length).toBeGreaterThanOrEqual(1)

		roomTasks.forEach(roomTask => {
			expect(roomTask.roomId).toBeDefined()
			expect(roomTask.taskId).toBeDefined()
		})
	})

	test('floors have correct structure', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const floors = seededData!.data.floors!
		expect(floors).toHaveLength(3)

		floors.forEach(floor => {
			expect(floor.name).toBeTruthy()
		})
	})
})

test.describe('User Access Validation', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })

	test('mom can access dashboard', async ({ page }) => {
		const creds = getUserCredentials('family', 'mom')
		await loginAsUser(page, creds!.userId, creds!.password)
		await page.goto('/apps/chorestrike/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })
	})

	test('kid can access dashboard', async ({ page }) => {
		const creds = getUserCredentials('family', 'kid')
		await loginAsUser(page, creds!.userId, creds!.password)
		await page.goto('/apps/chorestrike/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })
	})

	test('grandma can access dashboard', async ({ page }) => {
		const creds = getUserCredentials('family', 'grandma')
		await loginAsUser(page, creds!.userId, creds!.password)
		await page.goto('/apps/chorestrike/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })
	})
})

test.describe('Minimal Profile Validation', () => {
	test.use({ databaseProfile: 'minimal' as DatabaseProfile })

	test('minimal profile has correct structure', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const users = seededData!.data.users!
		const rooms = seededData!.data.rooms!
		const floors = seededData!.data.floors!

		expect(users.length).toBeGreaterThanOrEqual(2)
		expect(floors.length).toBeGreaterThanOrEqual(1)
		expect(rooms.length).toBeGreaterThanOrEqual(1)
	})
})

test.describe('Boundary Values', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })

	test('cleanliness values are within valid range', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const rooms = seededData!.data.rooms!

		const cleanlinessValues = rooms.map(r => r.cleanliness)
		const min = Math.min(...cleanlinessValues)
		const max = Math.max(...cleanlinessValues)

		expect(min).toBeGreaterThanOrEqual(0)
		expect(max).toBeLessThanOrEqual(100)
	})

	test('task categories are valid', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const tasks = seededData!.data.tasks!

		const validCategories = ['cleaning', 'maintenance', 'organizing']
		tasks.forEach(task => {
			expect(validCategories).toContain(task.category)
		})
	})
})
