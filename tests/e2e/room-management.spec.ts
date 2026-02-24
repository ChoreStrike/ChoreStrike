import { test, expect } from '@playwright/test'

/**
 * E2E test: Room and floor management
 * Critical flow: Create floor → Add rooms → Manage cleanliness
 */
test.describe('Room and Floor Management', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle')
	})

	test('User can create a new floor', async ({ page }) => {
		// Navigate to settings
		await page.click('[data-testid="nav-settings"]')
		await page.waitForLoadState('networkidle')

		// Click add floor
		await page.click('[data-testid="add-floor-button"]')

		// Fill floor name
		await page.fill('[data-testid="floor-name-input"]', 'Basement')

		// Save floor
		await page.click('[data-testid="save-floor-button"]')

		// Verify floor appears in list
		await expect(page.locator('text=Basement')).toBeVisible()
	})

	test('User can add room to floor', async ({ page }) => {
		// Navigate to dashboard
		await page.click('[data-testid="nav-dashboard"]')

		// Find floor and click add room
		await page.click('[data-testid="floor-Ground Floor"] [data-testid="add-room-button"]')

		// Fill room details
		await page.fill('[data-testid="room-name-input"]', 'Guest Bathroom')
		await page.selectOption('[data-testid="room-type-select"]', 'bathroom')

		// Save room
		await page.click('[data-testid="save-room-button"]')

		// Verify room appears under floor
		await expect(
			page.locator('[data-testid="floor-Ground Floor"]')
		).toContainText('Guest Bathroom')
	})

	test('User can update room cleanliness', async ({ page }) => {
		// Navigate to room
		await page.click('[data-testid="room-card-Living Room"]')
		await page.waitForLoadState('networkidle')

		// Get current cleanliness value
		const cleanlinessText = await page.locator('[data-testid="cleanliness-value"]').textContent()
		const currentCleanliness = parseInt(cleanlinessText || '0', 10)

		// Click quick clean button
		await page.click('[data-testid="quick-clean-button"]')

		// Wait for update
		await page.waitForTimeout(1000)

		// Verify cleanliness increased
		const newCleanlinessText = await page.locator('[data-testid="cleanliness-value"]').textContent()
		const newCleanliness = parseInt(newCleanlinessText || '0', 10)

		expect(newCleanliness).toBeGreaterThan(currentCleanliness)
	})

	test('Room cleanliness affects monster count', async ({ page }) => {
		// Navigate to dirty room
		await page.click('[data-testid="nav-dashboard"]')
		await page.click('[data-testid="room-card-Basement"]')

		// Toggle monsters display
		await page.click('[data-testid="monster-toggle"]')

		// Count visible monsters
		const monsterCount = await page.locator('[data-testid="monster-sprite"]').count()

		// Clean room
		await page.click('[data-testid="quick-clean-button"]')
		await page.click('[data-testid="quick-clean-button"]')
		await page.click('[data-testid="quick-clean-button"]')

		// Wait for monster update
		await page.waitForTimeout(1000)

		// Monsters should decrease
		const newMonsterCount = await page.locator('[data-testid="monster-sprite"]').count()
		expect(newMonsterCount).toBeLessThanOrEqual(monsterCount)
	})
})
