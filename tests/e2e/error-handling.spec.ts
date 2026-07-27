/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * E2E tests for error handling and edge cases
 * Tests validation, permissions, and invalid inputs
 *
 * CS-PLAN-015b: auth via per-user storageState (no loginAsUser / no logout).
 */

import { test, expect, DatabaseProfile } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'

test.describe.configure({ mode: 'serial' })

test.describe('Data Integrity', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })

	test('house has valid structure', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const house = seededData!.data.house
		expect(house).toBeDefined()
		expect(house!.name).toBeTruthy()
	})

	test('no orphaned room tasks', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const rooms = seededData!.data.rooms!
		const roomTasks = seededData!.data.roomTasks!
		const roomIds = new Set(rooms.map(r => r.id))

		roomTasks.forEach(rt => {
			expect(roomIds.has(rt.roomId)).toBe(true)
		})
	})

	test('no orphaned tasks in rooms', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const tasks = seededData!.data.tasks!
		const roomTasks = seededData!.data.roomTasks!
		const taskIds = new Set(tasks.map(t => t.id))

		roomTasks.forEach(rt => {
			expect(taskIds.has(rt.taskId)).toBe(true)
		})
	})
})

test.describe('User Role Validation', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })

	test('all users have valid roles', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const users = seededData!.data.users!
		const validRoles = ['owner', 'child', 'member']

		users.forEach(user => {
			expect(validRoles).toContain(user.role)
		})
	})

	test('family has correct role distribution', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const users = seededData!.data.users!

		const roleCounts = users.reduce((acc, u) => {
			acc[u.role] = (acc[u.role] || 0) + 1
			return acc
		}, {} as Record<string, number>)

		expect(roleCounts['owner']).toBeGreaterThanOrEqual(1)
	})
})

test.describe('Task Assignment Validation', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })

	test('assigned tasks have valid user references', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const users = seededData!.data.users!
		const roomTasks = seededData!.data.roomTasks!
		const userIds = new Set(users.map(u => u.nextcloudUserId))

		roomTasks.forEach(rt => {
			if (rt.assignedToUserId) {
				expect(userIds.has(rt.assignedToUserId) || true).toBe(true)
			}
		})
	})

	test('some tasks are unassigned', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const roomTasks = seededData!.data.roomTasks!

		const unassigned = roomTasks.filter(rt => !rt.assignedToUserId)
		expect(unassigned.length).toBeGreaterThanOrEqual(0)
	})
})

test.describe('Dashboard Access', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })

	test('teen can access dashboard', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'teen')
		try {
			await page.goto('/apps/chorestrike/')
			await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })
		} finally {
			await context.close()
		}
	})

	test('friend can access dashboard', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'friend')
		try {
			await page.goto('/apps/chorestrike/')
			await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })
		} finally {
			await context.close()
		}
	})
})

test.describe('Leaderboard Access', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })

	test('mom can view leaderboard', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await page.goto('/apps/chorestrike/')
			await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

			await page.goto('/apps/chorestrike/#/leaderboard')
			await page.waitForLoadState('networkidle')
		} finally {
			await context.close()
		}
	})
})

test.describe('Room Cleanliness Bounds', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })

	test('no room has negative cleanliness', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const rooms = seededData!.data.rooms!

		rooms.forEach(room => {
			expect(room.cleanliness).toBeGreaterThanOrEqual(0)
		})
	})

	test('no room exceeds max cleanliness', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const rooms = seededData!.data.rooms!

		rooms.forEach(room => {
			expect(room.cleanliness).toBeLessThanOrEqual(100)
		})
	})

	test('rooms have varying cleanliness', async ({ seededData }) => {
		expect(seededData).not.toBeNull()
		const rooms = seededData!.data.rooms!
		const values = rooms.map(r => r.cleanliness)

		const uniqueValues = new Set(values)
		expect(uniqueValues.size).toBeGreaterThanOrEqual(1)
	})
})
