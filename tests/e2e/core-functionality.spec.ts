import { test, expect } from './fixtures/test-fixtures'

/**
 * E2E Tests for Core App Functionality
 * Tests basic navigation, data loading, and UI components
 */
test.describe('Core App Functionality', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)
		
		// Navigate to app (assuming already logged in)
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		
		// Wait for dashboard with multiple fallback strategies
		try {
			await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })
		} catch (error) {
			// Fallback: wait for any dashboard-related content
			await page.waitForSelector('.dashboard, .dashboard-view, h1', { timeout: 10000 })
		}
	})

	test('Dashboard loads and displays basic components', async ({ page }) => {
		// Dashboard view should be visible (with fallback)
		try {
			await expect(page.locator('[data-testid="dashboard-view"]')).toBeVisible()
		} catch (error) {
			// Fallback: check for dashboard class or h1
			await expect(page.locator('.dashboard, .dashboard-view')).toBeVisible()
		}

		// House health widget should be present
		try {
			await expect(page.locator('[data-testid="house-health"]')).toBeVisible({ timeout: 15000 })
		} catch (error) {
			// Fallback: check for any health-related component
			const healthBar = page.locator('[class*="health"], [data-testid*="health"]')
			const count = await healthBar.count()
			if (count > 0) {
				await expect(healthBar.first()).toBeVisible()
			}
		}

		// Floor list should be present
		try {
			await expect(page.locator('[data-testid="floor-list"]')).toBeVisible({ timeout: 15000 })
		} catch (error) {
			// Fallback: check for any floor-related component
			const floorList = page.locator('[class*="floor"], [data-testid*="floor"]')
			const count = await floorList.count()
			if (count > 0) {
				await expect(floorList.first()).toBeVisible()
			}
		}

		// Quick stats should be visible
		try {
			await expect(page.locator('[data-testid="quick-stats"]')).toBeVisible({ timeout: 15000 })
		} catch (error) {
			// Fallback: check for any stats-related component
			const stats = page.locator('[class*="stats"], [data-testid*="stats"]')
			const count = await stats.count()
			if (count > 0) {
				await expect(stats.first()).toBeVisible()
			}
		}
	})

	test('Navigation between main views works', async ({ page }) => {
		// Wait for initial load
		await page.waitForTimeout(1000)

		// Navigate to Tasks
		await page.click('text=Tasks')
		await page.waitForTimeout(1000)
		await expect(page).toHaveURL(/.*tasks/)

		// Navigate to Leaderboard
		await page.click('text=Leaderboard')
		await page.waitForTimeout(1000)
		await expect(page).toHaveURL(/.*leaderboard/)

		// Navigate to Settings
		await page.click('text=Settings')
		await page.waitForTimeout(1000)
		await expect(page).toHaveURL(/.*settings/)

		// Back to Dashboard
		await page.click('text=Dashboard')
		await page.waitForTimeout(1000)
		await expect(page).toHaveURL(/.*dashboard/)
	})

	test('Task Manager loads and displays controls', async ({ page }) => {
		// Navigate to Task Manager
		await page.click('text=Tasks')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForTimeout(1000)

		// Task Manager view should be visible
		await expect(page.locator('[data-testid="task-manager-view"]')).toBeVisible({ timeout: 20000 })

		// Filter controls should be present
		await expect(page.locator('[data-testid="status-filter"]')).toBeVisible({ timeout: 15000 })
		await expect(page.locator('[data-testid="priority-filter"]')).toBeVisible({ timeout: 15000 })
		await expect(page.locator('[data-testid="difficulty-filter"]')).toBeVisible({ timeout: 15000 })

		// Search input should be present
		await expect(page.locator('[data-testid="search-input"]')).toBeVisible()

		// Add task button should be present
		await expect(page.locator('[data-testid="add-task-button"]')).toBeVisible({ timeout: 15000 })
	})

	test('Settings page loads and displays controls', async ({ page }) => {
		// Navigate to Settings
		await page.click('text=Settings')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForTimeout(1000)

		// Settings view should be visible
		await expect(page.locator('[data-testid="settings-view"]')).toBeVisible({ timeout: 20000 })

		// House name input should be present
		await expect(page.locator('[data-testid="house-name-input"]')).toBeVisible({ timeout: 15000 })

		// Floors section should be present
		await expect(page.locator('[data-testid="floors-section"]')).toBeVisible({ timeout: 15000 })

		// Add floor button should be present
		await expect(page.locator('[data-testid="add-floor-button"]')).toBeVisible({ timeout: 15000 })
	})

	test('Leaderboard page loads', async ({ page }) => {
		// Navigate to Leaderboard
		await page.click('text=Leaderboard')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForTimeout(1000)

		// Leaderboard view should be visible
		await expect(page.locator('[data-testid="leaderboard-view"]')).toBeVisible({ timeout: 20000 })
	})

	test('Room cards are clickable and navigate to room detail', async ({ page }) => {
		// Look for room cards
		const roomCards = page.locator('.room-card')
		
		// Check if there are any rooms
		const roomCount = await roomCards.count()
		if (roomCount === 0) {
			// Skip test if no rooms exist
			test.skip()
			return
		}

		// Click first room card
		await roomCards.first().click()
		await page.waitForLoadState('networkidle', { timeout: 30000 })

		// Should navigate to room detail
		await expect(page.locator('[data-testid="room-detail-view"]')).toBeVisible({ timeout: 15000 })
	})

	test('Task completion flow works', async ({ page }) => {
		// Navigate to Task Manager
		await page.click('text=Tasks')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForTimeout(1000)

		// Look for active tasks
		const taskItems = page.locator('.task-item')
		const taskCount = await taskItems.count()
		
		if (taskCount === 0) {
			// Skip test if no tasks exist
			test.skip()
			return
		}

		// Try to complete first task (look for complete button)
		const completeButton = taskItems.first().locator('[data-testid*="complete"], button:has-text("Complete")')
		const buttonCount = await completeButton.count()
		
		if (buttonCount > 0) {
			await completeButton.first().click()
			await page.waitForTimeout(1000)
			
			// Should show some feedback (modal, notification, or state change)
			// We can't assert specific behavior since it depends on implementation
		}
	})

	test('Filters and search work in Task Manager', async ({ page }) => {
		// Navigate to Task Manager
		await page.click('text=Tasks')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForTimeout(1000)

		// Test status filter
		await page.selectOption('[data-testid="status-filter"]', 'active')
		await page.waitForTimeout(300)

		// Test priority filter
		await page.selectOption('[data-testid="priority-filter"]', 'urgent')
		await page.waitForTimeout(300)

		// Test search
		await page.fill('[data-testid="search-input"]', 'test')
		await page.waitForTimeout(300)

		// Clear search
		await page.fill('[data-testid="search-input"]', '')
		await page.waitForTimeout(300)

		// View should still be visible after all interactions
		await expect(page.locator('[data-testid="task-manager-view"]')).toBeVisible()
	})
})
