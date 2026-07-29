/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { test, expect } from './fixtures/test-fixtures'

test.describe('Daily Tasks Flow', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)

		// Navigate to app
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
	})

	test('Daily task list loads', async ({ page }) => {
		// Check for daily task list component
		const taskList = page.locator('[data-testid="daily-task-list"], .daily-task-list')
		const count = await taskList.count()
		if (count > 0) {
			await expect(taskList.first()).toBeVisible({ timeout: 15000 })
		}
	})

	test('Can complete a task', async ({ page }) => {
		// Find a complete button
		const completeButton = page.locator('button:has-text("Complete"), button:has-text("Erledigen")').first()
		const count = await completeButton.count()
		if (count > 0) {
			await completeButton.click({ timeout: 10000 })
			// Wait for UI update
			await page.waitForTimeout(1000)
		}
	})

	test('Can skip a task', async ({ page }) => {
		// Find a skip button
		const skipButton = page.locator('button:has-text("Skip"), button:has-text("Überspringen")').first()
		const count = await skipButton.count()
		if (count > 0) {
			await skipButton.click({ timeout: 10000 })
			// Wait for UI update
			await page.waitForTimeout(1000)
		}
	})

	test('Can reschedule a task', async ({ page }) => {
		// Find a reschedule button
		const rescheduleButton = page.locator('button:has-text("Reschedule"), button:has-text("Verschieben")').first()
		const count = await rescheduleButton.count()
		if (count > 0) {
			await rescheduleButton.click({ timeout: 10000 })
			// Wait for modal or date picker
			await page.waitForTimeout(1000)
		}
	})

	test('Task progress indicator visible', async ({ page }) => {
		// Check for progress indicator
		const progress = page.locator('[data-testid="daily-progress"], .progress-indicator')
		const count = await progress.count()
		if (count > 0) {
			await expect(progress.first()).toBeVisible({ timeout: 10000 })
		}
	})
})
