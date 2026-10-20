import { expect, test } from '@playwright/test'
import { expandUntilRoomVisible } from './fixtures/dashboard-floors.ts'

/**
 * E2E test: Room detail view and task management
 * Tests room info display, task CRUD operations, and cleanliness updates
 *
 * CS-PLAN-050: the dashboard floors render collapsed, so every test expands
 * them through the shared helper before opening a room; the silent
 * `if (roomCount === 0) return` early-exits became explicit skips with a
 * reason (the family seed guarantees rooms, so they never fire). The dead
 * `monster-sprite` test was deleted — no such element exists in `src/`.
 */
test.describe('Room Detail', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)
		await page.goto('/apps/chorestrike/#/dashboard')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
	})

	test('Room detail displays room information', async ({ page }) => {
		// Navigate to a room from the dashboard (floors start collapsed)
		const roomCards = page.locator('[data-testid^="room-card-"]')
		await expandUntilRoomVisible(page, roomCards.first())
		await expect(roomCards.first()).toBeVisible()

		const roomName = await roomCards.first().locator('.room-name').textContent()
		await roomCards.first().click()
		await page.waitForLoadState('networkidle', { timeout: 30000 })

		// Verify room detail view
		await expect(page.locator('[data-testid="room-detail-view"]')).toBeVisible()

		// Verify room name is displayed
		await expect(page.locator('[data-testid="room-name"]')).toContainText(roomName || '')

		// Verify room stats are displayed
		await expect(page.locator('[data-testid="cleanliness-stat"]')).toBeVisible()
		await expect(page.locator('[data-testid="cleanliness-value"]')).toBeVisible()
		await expect(page.locator('[data-testid="tasks-count-stat"]')).toBeVisible()
		await expect(page.locator('[data-testid="overdue-stat"]')).toBeVisible()
	})

	test('User can navigate back to dashboard from room detail', async ({ page }) => {
		const roomCards = page.locator('[data-testid^="room-card-"]')
		await expandUntilRoomVisible(page, roomCards.first())
		await expect(roomCards.first()).toBeVisible()

		await roomCards.first().click()
		await page.waitForLoadState('networkidle', { timeout: 30000 })

		// Click back link
		await page.click('[data-testid="back-link"]')
		await page.waitForLoadState('networkidle', { timeout: 30000 })

		// Verify we're back on dashboard
		await expect(page.locator('[data-testid="dashboard-view"]')).toBeVisible()
	})

	test('User can open add task modal', async ({ page }) => {
		const roomCards = page.locator('[data-testid^="room-card-"]')
		await expandUntilRoomVisible(page, roomCards.first())
		await expect(roomCards.first()).toBeVisible()

		await roomCards.first().click()
		await page.waitForLoadState('networkidle', { timeout: 30000 })

		// Click add task button — the shared Modal opens on the template
		// picker (addTaskMode defaults to 'template').
		await page.click('[data-testid="add-task-button"]')
		await expect(page.getByTestId('add-task-picker')).toBeVisible({ timeout: 15000 })
	})

	test('Room cleanliness value is displayed correctly', async ({ page }) => {
		const roomCards = page.locator('[data-testid^="room-card-"]')
		await expandUntilRoomVisible(page, roomCards.first())
		await expect(roomCards.first()).toBeVisible()

		await roomCards.first().click()
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await expect(page.locator('[data-testid="room-detail-view"]')).toBeVisible()

		// Get cleanliness value from the detail view (the dashboard room
		// cards carry the same testid, so scope into the detail view).
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
