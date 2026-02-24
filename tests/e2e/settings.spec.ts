import { test, expect } from '@playwright/test'

/**
 * E2E test: Settings and configuration
 * Critical flow: Edit house name → Manage floors → Manage rooms
 */
test.describe('Settings and Configuration', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle')
	})

	test('User can edit house name', async ({ page }) => {
		// Navigate to settings
		await page.click('[data-testid="nav-settings"]')
		await page.waitForLoadState('networkidle')

		// Clear and type new house name
		await page.fill('[data-testid="house-name-input"]', 'My Test House')

		// Click save
		await page.click('[data-testid="save-house-name-button"]')

		// Verify success message
		await expect(page.locator('.success-message')).toContainText('updated')
	})

	test('User can reorder floors', async ({ page }) => {
		// Navigate to settings
		await page.click('[data-testid="nav-settings"]')
		await page.waitForLoadState('networkidle')

		// Get first floor name
		const firstFloor = await page.locator('[data-testid="floor-item"]').first()
		const firstFloorName = await firstFloor.textContent()

		// Click move down on first floor
		await firstFloor.locator('[data-testid="move-floor-down"]').click()

		// Verify floor moved (check success message or new order)
		await expect(page.locator('.success-message')).toContainText('order updated')
	})

	test('User can edit floor name', async ({ page }) => {
		// Navigate to settings
		await page.click('[data-testid="nav-settings"]')
		await page.waitForLoadState('networkidle')

		// Click edit on first floor
		await page.click('[data-testid="floor-item"] [data-testid="edit-floor-button"]:first')

		// Clear and type new name
		await page.fill('[data-testid="edit-floor-name-input"]', 'Updated Floor Name')

		// Save
		await page.click('[data-testid="save-floor-edit-button"]')

		// Verify floor name updated
		await expect(page.locator('text=Updated Floor Name')).toBeVisible()
	})

	test('User can create and delete floor', async ({ page }) => {
		// Navigate to settings
		await page.click('[data-testid="nav-settings"]')
		await page.waitForLoadState('networkidle')

		// Type new floor name
		await page.fill('[data-testid="new-floor-name-input"]', 'Test Floor ' + Date.now())

		// Click add
		await page.click('[data-testid="add-floor-button"]')

		// Verify floor created
		await expect(page.locator('.success-message')).toContainText('Floor created')

		// Delete the floor
		await page.click('[data-testid="floor-item"]:last [data-testid="delete-floor-button"]')

		// Confirm delete
		await page.click('[data-testid="confirm-delete-button"]')

		// Verify floor deleted
		await expect(page.locator('.success-message')).toContainText('Floor deleted')
	})

	test('User can edit room and change floor assignment', async ({ page }) => {
		// Navigate to settings
		await page.click('[data-testid="nav-settings"]')
		await page.waitForLoadState('networkidle')

		// Click edit on first room
		await page.click('[data-testid="room-item"] [data-testid="edit-room-button"]:first')

		// Change room name
		await page.fill('[data-testid="edit-room-name-input"]', 'Updated Room')

		// Change floor assignment
		await page.selectOption('[data-testid="edit-room-floor-select"]', { index: 1 })

		// Save
		await page.click('[data-testid="save-room-edit-button"]')

		// Verify success
		await expect(page.locator('.success-message')).toContainText('Room updated')
	})
})
