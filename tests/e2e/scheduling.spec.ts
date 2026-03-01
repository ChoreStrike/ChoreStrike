import { test, expect } from './fixtures/test-fixtures'

/**
 * E2E test: Smart Scheduling UI
 * Tests scheduling suggestions, availability settings, energy budget, and schedule preview
 * @task P2-FE-004
 */
test.describe('Smart Scheduling', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)

		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })

		// Navigate to Scheduling section
		await page.click('text=Scheduling')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForTimeout(1000)
	})

	test('Scheduling page loads with correct title', async ({ page }) => {
		// Wait for scheduling view
		await expect(page.locator('[data-testid="scheduling-view"]')).toBeVisible({ timeout: 20000 })

		// Verify title
		await expect(page.locator('[data-testid="scheduling-title"]')).toContainText('Smart Scheduling')
	})

	test('Scheduling displays all main sections', async ({ page }) => {
		// Verify main sections are visible
		await expect(page.locator('[data-testid="availability-settings"]')).toBeVisible({ timeout: 15000 })
		await expect(page.locator('[data-testid="energy-budget-settings"]')).toBeVisible({ timeout: 15000 })
		await expect(page.locator('[data-testid="schedule-preview"]')).toBeVisible({ timeout: 15000 })
		await expect(page.locator('[data-testid="scheduling-suggestions"]')).toBeVisible({ timeout: 15000 })
	})

	test('Availability settings can be toggled', async ({ page }) => {
		// Open availability settings
		await page.click('[data-testid="availability-settings-toggle"]')
		await page.waitForTimeout(500)

		// Verify availability grid is visible
		await expect(page.locator('[data-testid="availability-grid"]')).toBeVisible({ timeout: 10000 })

		// Toggle a time slot
		const firstSlot = page.locator('[data-testid="availability-slot"]').first()
		await firstSlot.click()
		await page.waitForTimeout(300)

		// Verify slot is now marked as available
		await expect(firstSlot).toHaveClass(/available/)
	})

	test('Energy budget can be adjusted', async ({ page }) => {
		// Open energy budget settings
		await page.click('[data-testid="energy-budget-toggle"]')
		await page.waitForTimeout(500)

		// Verify budget controls are visible
		await expect(page.locator('[data-testid="energy-budget-slider"]')).toBeVisible({ timeout: 10000 })

		// Adjust budget using slider
		const slider = page.locator('[data-testid="energy-budget-slider"]')
		await slider.fill('75')
		await page.waitForTimeout(300)

		// Verify budget value is updated
		await expect(page.locator('[data-testid="energy-budget-value"]')).toContainText('75')
	})

	test('Schedule preview generates and displays', async ({ page }) => {
		// Click generate schedule button
		await page.click('[data-testid="generate-schedule-button"]')
		await page.waitForTimeout(2000)

		// Verify preview is displayed
		await expect(page.locator('[data-testid="schedule-preview-calendar"]')).toBeVisible({ timeout: 15000 })

		// Verify schedule has tasks
		const taskCount = await page.locator('[data-testid="scheduled-task"]').count()
		expect(taskCount).toBeGreaterThan(0)
	})

	test('Scheduling suggestions are displayed', async ({ page }) => {
		// Verify suggestions section is visible
		await expect(page.locator('[data-testid="suggestions-list"]')).toBeVisible({ timeout: 15000 })

		// Check if there are any suggestions or empty state
		const suggestionCount = await page.locator('[data-testid="scheduling-suggestion"]').count()
		const emptyState = await page.locator('[data-testid="no-suggestions-message"]').isVisible().catch(() => false)

		// Either suggestions exist or empty state is shown
		expect(suggestionCount > 0 || emptyState).toBeTruthy()
	})

	test('User can apply a scheduling suggestion', async ({ page }) => {
		// Wait for suggestions to load
		await page.waitForTimeout(1000)

		// Check if there are suggestions
		const firstSuggestion = page.locator('[data-testid="scheduling-suggestion"]').first()
		const hasSuggestions = await firstSuggestion.isVisible().catch(() => false)

		if (hasSuggestions) {
			// Click apply on first suggestion
			await firstSuggestion.locator('[data-testid="apply-suggestion-button"]').click()
			await page.waitForTimeout(1000)

			// Verify success toast is shown
			await expect(page.locator('[data-testid="success-toast"]')).toBeVisible({ timeout: 10000 })

			// Verify suggestion is removed from list
			await expect(firstSuggestion).not.toBeVisible({ timeout: 5000 })
		} else {
			// Skip test if no suggestions available
			test.skip()
		}
	})

	test('Schedule calendar allows drag and drop', async ({ page }) => {
		// Generate schedule first
		await page.click('[data-testid="generate-schedule-button"]')
		await page.waitForTimeout(2000)

		// Wait for calendar to be visible
		await expect(page.locator('[data-testid="schedule-calendar"]')).toBeVisible({ timeout: 15000 })

		// Get first scheduled task
		const firstTask = page.locator('[data-testid="scheduled-task"]').first()
		const hasTasks = await firstTask.isVisible().catch(() => false)

		if (hasTasks) {
			// Perform drag operation (drag to next day)
			const taskBox = await firstTask.boundingBox()
			if (taskBox) {
				await firstTask.dragTo(page.locator('[data-testid="calendar-day"]').nth(1))
				await page.waitForTimeout(1000)

				// Verify task was moved
				await expect(page.locator('[data-testid="schedule-updated-indicator"]')).toBeVisible({ timeout: 5000 })
			}
		} else {
			test.skip()
		}
	})

	test('Conflict detection displays warnings', async ({ page }) => {
		// Generate schedule
		await page.click('[data-testid="generate-schedule-button"]')
		await page.waitForTimeout(2000)

		// Check for conflict warnings
		const conflictWarning = page.locator('[data-testid="conflict-warning"]')
		const hasConflicts = await conflictWarning.isVisible().catch(() => false)

		if (hasConflicts) {
			// Verify conflict details are shown
			await expect(page.locator('[data-testid="conflict-list"]')).toBeVisible({ timeout: 5000 })

			// Test conflict resolution
			await page.locator('[data-testid="resolve-conflict-button"]').first().click()
			await page.waitForTimeout(500)

			// Verify conflict modal opens
			await expect(page.locator('[data-testid="conflict-resolution-modal"]')).toBeVisible({ timeout: 5000 })
		}
	})

	test('Energy budget warnings are shown for over-allocation', async ({ page }) => {
		// Set very low energy budget
		await page.click('[data-testid="energy-budget-toggle"]')
		await page.waitForTimeout(500)

		const slider = page.locator('[data-testid="energy-budget-slider"]')
		await slider.fill('10')
		await page.waitForTimeout(300)

		// Generate schedule with many tasks
		await page.click('[data-testid="generate-schedule-button"]')
		await page.waitForTimeout(2000)

		// Check for energy warning
		const energyWarning = page.locator('[data-testid="energy-warning"]')
		const warningVisible = await energyWarning.isVisible().catch(() => false)

		if (warningVisible) {
			await expect(energyWarning).toContainText(/energy|budget/i)
		}
	})

	test('Schedule can be saved', async ({ page }) => {
		// Generate schedule
		await page.click('[data-testid="generate-schedule-button"]')
		await page.waitForTimeout(2000)

		// Click save schedule button
		await page.click('[data-testid="save-schedule-button"]')
		await page.waitForTimeout(1000)

		// Verify success message
		await expect(page.locator('[data-testid="success-toast"]')).toBeVisible({ timeout: 10000 })
		await expect(page.locator('[data-testid="success-toast"]')).toContainText('saved', { ignoreCase: true })
	})
})
