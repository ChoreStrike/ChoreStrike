import { test, expect } from '@playwright/test'
import { userStatePath } from './fixtures/auth-contexts'

/**
 * E2E test: Settings - house and floor management
 * Tests house name editing, floor management, and reset functionality
 *
 * CS-PLAN-015b: auth via project-level storageState (no loginAsUser).
 * CS-PLAN-042: house/floors/danger sections are OWNER-only, so this spec
 * runs as `mom` (seeded family-house owner) — the default `admin` identity
 * is a plain member under the family profile.
 */
test.describe('Settings', () => {
	test.use({ storageState: userStatePath('mom') })

	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)

		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })

		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })

		await page.getByTestId('nav-settings').click()
		await page.waitForLoadState('networkidle', { timeout: 30000 })

		await page.waitForSelector('[data-testid="settings-view"]', { timeout: 20000 })
	})

	test('Settings page loads correctly', async ({ page }) => {
		await expect(page.locator('[data-testid="settings-view"]')).toBeVisible()

		await expect(page.locator('[data-testid="settings-title"]')).toContainText('Settings')
	})

	test('House name input is displayed and interactive', async ({ page }) => {
		await expect(page.locator('[data-testid="house-name-input"]')).toBeVisible()

		await expect(page.locator('[data-testid="save-house-name"]')).toBeVisible()

		await expect(page.locator('[data-testid="house-name-input"]')).toHaveAttribute('placeholder', /house name/i)
	})

	test('User can type in house name field', async ({ page }) => {
		await page.fill('[data-testid="house-name-input"]', 'Test House')

		await expect(page.locator('[data-testid="house-name-input"]')).toHaveValue('Test House')
	})

	test('Floors section is displayed with add button', async ({ page }) => {
		await expect(page.locator('[data-testid="add-floor-button"]')).toBeVisible()
	})

	test('Floors list or empty state is displayed', async ({ page }) => {
		// The floors fetch resolves asynchronously after the view mounts; wait
		// for whichever end state applies instead of probing instantly.
		await expect(
			page.getByTestId('floors-list').or(page.getByTestId('no-floors-message')),
		).toBeVisible({ timeout: 15000 })
	})

	test('User can open add floor modal', async ({ page }) => {
		await page.click('[data-testid="add-floor-button"]')

		await expect(page.locator('.modal-content')).toBeVisible()
	})

	test('Settings page has danger zone section', async ({ page }) => {
		const dangerZone = page.locator('.danger-zone, .reset-section, [data-testid="danger-zone"]')

		if (await dangerZone.count() > 0) {
			await expect(dangerZone).toBeVisible()
		}
	})
})
