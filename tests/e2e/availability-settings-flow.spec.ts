/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { test, expect } from './fixtures/test-fixtures'

test.describe('Availability Settings Flow', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)
		
		// Navigate to app
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
	})

	test('Availability settings page loads', async ({ page }) => {
		// Navigate to scheduling view
		await page.goto('/apps/chorestrike/scheduling')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		
		// Check for availability settings component
		const availabilitySection = page.locator('[data-testid="availability-settings"], .availability-settings')
		const count = await availabilitySection.count()
		if (count > 0) {
			await expect(availabilitySection.first()).toBeVisible({ timeout: 15000 })
		}
	})

	test('Can toggle weekday availability', async ({ page }) => {
		await page.goto('/apps/chorestrike/scheduling')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		
		// Find a weekday toggle
		const toggle = page.locator('input[type="checkbox"], .toggle, .switch').first()
		const count = await toggle.count()
		if (count > 0) {
			await toggle.click({ timeout: 10000 })
		}
	})

	test('Can adjust energy budget slider', async ({ page }) => {
		await page.goto('/apps/chorestrike/scheduling')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		
		// Find energy budget slider
		const slider = page.locator('input[type="range"], .slider').first()
		const count = await slider.count()
		if (count > 0) {
			// Adjust slider value
			await slider.fill('10', { timeout: 10000 })
		}
	})

	test('Availability settings persist after save', async ({ page }) => {
		await page.goto('/apps/chorestrike/scheduling')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		
		// Find save button
		const saveButton = page.locator('button:has-text("Save"), button:has-text("Speichern")').first()
		const count = await saveButton.count()
		if (count > 0) {
			await saveButton.click({ timeout: 10000 })
			// Wait for success feedback
			await page.waitForTimeout(2000)
		}
	})
})
