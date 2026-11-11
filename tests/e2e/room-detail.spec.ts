import { expect, test } from '@playwright/test'
import { openRoom } from './fixtures/rooms.ts'

/**
 * E2E test: Room detail view and task management
 * Tests room info display, task CRUD operations, and cleanliness updates
 *
 * CS-PLAN-090 WP-B: the Dashboard and its room cards are gone, so every
 * test opens the first seeded room through the shared helper (the family
 * seed guarantees rooms). The page itself goes with WP-C, when the tasks
 * are grouped by room.
 */
test.describe('Room Detail', () => {
	test.beforeEach(() => {
		test.setTimeout(60000)
	})

	test('Room detail displays room information', async ({ page }) => {
		// The helper asserts the view and the room's name.
		await openRoom(page)

		// Verify room stats are displayed
		await expect(page.locator('[data-testid="cleanliness-stat"]')).toBeVisible()
		await expect(page.locator('[data-testid="cleanliness-value"]')).toBeVisible()
		await expect(page.locator('[data-testid="tasks-count-stat"]')).toBeVisible()
		await expect(page.locator('[data-testid="overdue-stat"]')).toBeVisible()
	})

	test('User can navigate back to Today from room detail', async ({ page }) => {
		await openRoom(page)

		// Click back link
		await page.click('[data-testid="back-link"]')

		await expect(page.locator('[data-testid="today-view"]')).toBeVisible({ timeout: 30000 })
	})

	test('User can open add task modal', async ({ page }) => {
		await openRoom(page)

		// Click add task button — the shared Modal opens on the template
		// picker (addTaskMode defaults to 'template').
		await page.click('[data-testid="add-task-button"]')
		await expect(page.getByTestId('add-task-picker')).toBeVisible({ timeout: 15000 })
	})

	test('Room cleanliness value is displayed correctly', async ({ page }) => {
		await openRoom(page)

		const cleanlinessText = await page
			.locator('[data-testid="room-detail-view"] [data-testid="cleanliness-value"]')
			.textContent()

		// Verify it's a percentage value
		expect(cleanlinessText).toMatch(/\d+%/)

		// Parse and verify range
		const cleanlinessValue = parseInt(cleanlinessText?.replace('%', '') || '0', 10)
		expect(cleanlinessValue).toBeGreaterThanOrEqual(0)
		expect(cleanlinessValue).toBeLessThanOrEqual(100)
	})
})
