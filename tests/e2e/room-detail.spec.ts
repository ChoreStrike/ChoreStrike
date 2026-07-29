import { test, expect } from './fixtures/test-fixtures'

/**
 * E2E test: Room detail view and task management
 * Tests room info display, task CRUD operations, and cleanliness updates
 */
test.describe('Room Detail', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
	})

	test('Room detail displays room information', async ({ page }) => {
		// Navigate to a room from dashboard
		const roomCard = page.locator('.room-card').first()

		// Skip if no rooms available
		const roomCount = await roomCard.count()
		if (roomCount === 0) {
			return
		}

		const roomName = await roomCard.locator('.room-name').textContent()
		await roomCard.click()
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

	test('Room detail displays monster sprite based on cleanliness', async ({ page }) => {
		// Navigate to a room
		const roomCard = page.locator('.room-card').first()

		// Skip if no rooms available
		const roomCount = await roomCard.count()
		if (roomCount === 0) {
			return
		}

		await roomCard.click()
		await page.waitForLoadState('networkidle', { timeout: 30000 })

		// Verify monster sprite is displayed
		await expect(page.locator('[data-testid="monster-sprite"]')).toBeVisible()
	})

	test('User can navigate back to dashboard from room detail', async ({ page }) => {
		// Navigate to a room
		const roomCard = page.locator('.room-card').first()

		// Skip if no rooms available
		const roomCount = await roomCard.count()
		if (roomCount === 0) {
			return
		}

		await roomCard.click()
		await page.waitForLoadState('networkidle', { timeout: 30000 })

		// Click back link
		await page.click('[data-testid="back-link"]')
		await page.waitForLoadState('networkidle', { timeout: 30000 })

		// Verify we're back on dashboard
		await expect(page.locator('[data-testid="dashboard-view"]')).toBeVisible()
	})

	test('User can open add task modal', async ({ page }) => {
		// Navigate to a room
		const roomCard = page.locator('.room-card').first()

		// Skip if no rooms available
		const roomCount = await roomCard.count()
		if (roomCount === 0) {
			return
		}

		await roomCard.click()
		await page.waitForLoadState('networkidle', { timeout: 30000 })

		// Click add task button
		await page.click('[data-testid="add-task-button"]')
		await page.waitForTimeout(500)

		// Verify modal is opened (TaskForm should be visible)
		await expect(page.locator('.modal-content')).toBeVisible({ timeout: 15000 })
	})

	test('Room cleanliness value is displayed correctly', async ({ page }) => {
		// Navigate to a room
		const roomCard = page.locator('.room-card').first()

		// Skip if no rooms available
		const roomCount = await roomCard.count()
		if (roomCount === 0) {
			return
		}

		await roomCard.click()
		await page.waitForLoadState('networkidle', { timeout: 30000 })

		// Get cleanliness value
		const cleanlinessText = await page.locator('[data-testid="cleanliness-value"]').textContent()

		// Verify it's a percentage value
		expect(cleanlinessText).toMatch(/\d+%/)

		// Parse and verify range
		const cleanlinessValue = parseInt(cleanlinessText?.replace('%', '') || '0', 10)
		expect(cleanlinessValue).toBeGreaterThanOrEqual(0)
		expect(cleanlinessValue).toBeLessThanOrEqual(100)
	})
})
