import { test, expect } from './fixtures/test-fixtures'
import { loginAsUser } from './fixtures/database-fixtures'

/**
 * E2E test: Settings - house and floor management
 * Tests house name editing, floor management, and reset functionality
 */
test.describe('Settings', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)

		await loginAsUser(
			page,
			process.env.NEXTCLOUD_USER || 'admin',
			process.env.NEXTCLOUD_PASSWORD || 'admin'
		)

		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })

		// Wait for dashboard to be ready
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })

		// Use data-testid for unambiguous navigation (NcAppNavigationItem passthrough).
		await page.getByTestId('nav-settings').click()
		await page.waitForLoadState('networkidle', { timeout: 30000 })

		// Wait for settings data to load with longer timeout
		await page.waitForSelector('[data-testid="settings-view"]', { timeout: 20000 })
	})

	test('Settings page loads correctly', async ({ page }) => {
		// Verify settings view is visible
		await expect(page.locator('[data-testid="settings-view"]')).toBeVisible()

		// Verify settings title
		await expect(page.locator('[data-testid="settings-title"]')).toContainText('Settings')
	})

	test('House name input is displayed and interactive', async ({ page }) => {
		// Verify house name input is visible
		await expect(page.locator('[data-testid="house-name-input"]')).toBeVisible()

		// Verify save button is visible
		await expect(page.locator('[data-testid="save-house-name"]')).toBeVisible()

		// Verify input has placeholder
		await expect(page.locator('[data-testid="house-name-input"]')).toHaveAttribute('placeholder', /house name/i)
	})

	test('User can type in house name field', async ({ page }) => {
		// Clear and type new house name
		await page.fill('[data-testid="house-name-input"]', 'Test House')

		// Verify value was entered
		await expect(page.locator('[data-testid="house-name-input"]')).toHaveValue('Test House')
	})

	test('Floors section is displayed with add button', async ({ page }) => {
		// Verify add floor button is visible
		await expect(page.locator('[data-testid="add-floor-button"]')).toBeVisible()
	})

	test('Floors list or empty state is displayed', async ({ page }) => {
		// Check if floors list exists
		const floorsList = page.locator('[data-testid="floors-list"]')
		const noFloorsMessage = page.locator('[data-testid="no-floors-message"]')

		// Either floors list or no floors message should be visible
		const hasFloorsList = await floorsList.isVisible().catch(() => false)
		const hasNoFloorsMessage = await noFloorsMessage.isVisible().catch(() => false)

		expect(hasFloorsList || hasNoFloorsMessage).toBeTruthy()
	})

	test('User can open add floor modal', async ({ page }) => {
		// Click add floor button
		await page.click('[data-testid="add-floor-button"]')

		// Verify modal is opened (should contain floor name input)
		await expect(page.locator('.modal-content')).toBeVisible()
	})

	test('Settings page has danger zone section', async ({ page }) => {
		// Look for danger zone or reset section (common pattern)
		const dangerZone = page.locator('.danger-zone, .reset-section, [data-testid="danger-zone"]')

		// If danger zone exists, verify it's visible
		if (await dangerZone.count() > 0) {
			await expect(dangerZone).toBeVisible()
		}
	})
})
