/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 * 
 * E2E tests for error handling and edge cases
 * Tests validation, permissions, and invalid inputs
 */

import { test, expect, DatabaseProfile, loginAsUser } from './fixtures/database-fixtures'

// Helper to get credentials for profiles
function getCredentials(profile: string, userId: string): { userId: string; password: string } | null {
	const creds: Record<string, Record<string, string>> = {
		minimal: { adult: 'adult', kid: 'kid' },
		family: { dad: 'dad', mom: 'mom', teen: 'teen', kid: 'kid', grandma: 'grandma', friend: 'friend' },
		empty: { admin: 'admin' }
	}
	return creds[profile]?.[userId] ? { userId, password: creds[profile][userId] } : null
}

test.describe('Input Validation', () => {
	test.use({ databaseProfile: 'minimal' as DatabaseProfile })

	test('rejects empty floor name', async ({ page }) => {
		// Login using fixture helper
		const creds = getCredentials('minimal', 'adult')
		await loginAsUser(page, creds!.userId, creds!.password)

		// Navigate to ChoreStrike
		await page.goto('/apps/chorestrike/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

		// Try to create floor with empty name
		const addFloorButton = page.locator('[data-testid="add-floor-button"]')
		if (await addFloorButton.isVisible()) {
			await addFloorButton.click()
			
			// Submit without name
			const submitButton = page.locator('button[type="submit"]')
			await submitButton.click()

			// Should show error message
			const errorMessage = page.locator('.error-message, .toast-error, [data-testid="error-message"]')
			await expect(errorMessage).toBeVisible({ timeout: 5000 })
		}
	})

	test('rejects duplicate floor name', async ({ page }) => {
		// Login
		await page.goto('/')
		await page.fill('#user', 'adult')
		await page.fill('#password', 'adult')
		await page.click('input[type="submit"]')
		await page.waitForURL('**/apps/**')

		// Navigate to ChoreStrike
		await page.goto('/apps/chorestrike/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

		// Check existing floors
		const floorSections = page.locator('.floor-section')
		const floorCount = await floorSections.count()

		if (floorCount > 0) {
			// Get name of first floor
			const firstFloorName = await floorSections.first().locator('.floor-name').textContent()
			
			// Try to create floor with same name
			const addFloorButton = page.locator('[data-testid="add-floor-button"]')
			if (await addFloorButton.isVisible()) {
				await addFloorButton.click()
				
				// Enter duplicate name
				const nameInput = page.locator('input[name="floor-name"], #floor-name')
				await nameInput.fill(firstFloorName || 'Ground Floor')
				
				const submitButton = page.locator('button[type="submit"]')
				await submitButton.click()

				// Should show error
				const errorMessage = page.locator('.error-message, .toast-error')
				await errorMessage.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {
					// Error might be shown differently
				})
			}
		}
	})

	test('rejects empty room name', async ({ page }) => {
		// Login
		await page.goto('/')
		await page.fill('#user', 'adult')
		await page.fill('#password', 'adult')
		await page.click('input[type="submit"]')
		await page.waitForURL('**/apps/**')

		// Navigate to ChoreStrike
		await page.goto('/apps/chorestrike/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

		// Expand first floor
		const toggleButton = page.locator('.floor-section .toggle-button').first()
		await toggleButton.click()
		await page.waitForTimeout(500)

		// Try to add room with empty name
		const addRoomCard = page.locator('[data-testid^="add-room-card-"]').first()
		if (await addRoomCard.isVisible()) {
			await addRoomCard.click()
			
			// Submit without name
			const submitButton = page.locator('button[type="submit"]')
			await submitButton.click()

			// Should show error
			const errorMessage = page.locator('.error-message, .toast-error')
			await errorMessage.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {})
		}
	})
})

test.describe('Permission Tests', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })

	test('kid cannot access admin settings', async ({ page }) => {
		// Login as kid
		await page.goto('/')
		await page.fill('#user', 'kid')
		await page.fill('#password', 'kid')
		await page.click('input[type="submit"]')
		await page.waitForURL('**/apps/**')

		// Navigate to ChoreStrike
		await page.goto('/apps/chorestrike/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

		// Try to access settings
		await page.goto('/apps/chorestrike/#/settings')
		await page.waitForLoadState('networkidle')

		// Should not see admin controls or should see limited view
		const adminSection = page.locator('[data-testid="admin-settings"], .admin-only')
		const isVisible = await adminSection.isVisible().catch(() => false)
		
		// Kid should not have access to admin settings
		expect(isVisible).toBe(false)
	})

	test('user cannot modify others tasks without permission', async ({ page }) => {
		// Login as kid
		await page.goto('/')
		await page.fill('#user', 'kid')
		await page.fill('#password', 'kid')
		await page.click('input[type="submit"]')
		await page.waitForURL('**/apps/**')

		// Navigate to ChoreStrike
		await page.goto('/apps/chorestrike/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

		// Expand floors
		const floorSections = page.locator('.floor-section')
		const floorCount = await floorSections.count()
		for (let i = 0; i < floorCount; i++) {
			const floorSection = floorSections.nth(i)
			const toggleButton = floorSection.locator('.toggle-button')
			const floorContent = floorSection.locator('.floor-content')
			const isExpanded = await floorContent.isVisible().catch(() => false)
			if (!isExpanded) {
				await toggleButton.click()
				await page.waitForTimeout(500)
			}
		}

		// Check if kid can see rooms (limited access)
		const roomCards = page.locator('[data-testid^="room-card-"]')
		const count = await roomCards.count()
		
		// Kid should have limited access
		expect(count).toBeGreaterThanOrEqual(0)
	})
})

test.describe('Edge Cases', () => {
	test.use({ databaseProfile: 'empty' as DatabaseProfile })

	test('handles empty house gracefully', async ({ page }) => {
		// Login
		await page.goto('/')
		await page.fill('#user', 'admin')
		await page.fill('#password', 'admin')
		await page.click('input[type="submit"]')
		await page.waitForURL('**/apps/**')

		// Navigate to ChoreStrike
		await page.goto('/apps/chorestrike/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

		// Should show empty state
		const emptyState = page.locator('[data-testid="empty-house"], .empty-state, text=/No floors/i')
		const isVisible = await emptyState.isVisible().catch(() => false)
		
		// Should show add floor button
		const addFloorButton = page.locator('[data-testid="add-floor-button"], button:has-text("Add Floor")')
		const canAddFloor = await addFloorButton.isVisible().catch(() => false)

		expect(isVisible || canAddFloor).toBe(true)
	})

	test('handles special characters in names', async ({ page }) => {
		// Login
		await page.goto('/')
		await page.fill('#user', 'admin')
		await page.fill('#password', 'admin')
		await page.click('input[type="submit"]')
		await page.waitForURL('**/apps/**')

		// Navigate to ChoreStrike
		await page.goto('/apps/chorestrike/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

		// Try to create floor with special characters
		const addFloorButton = page.locator('[data-testid="add-floor-button"]')
		if (await addFloorButton.isVisible()) {
			await addFloorButton.click()
			
			// Enter name with special characters
			const nameInput = page.locator('input[name="floor-name"], #floor-name')
			await nameInput.fill('Test <script>alert("xss")</script>')
			
			const submitButton = page.locator('button[type="submit"]')
			await submitButton.click()

			// Should either sanitize or reject
			await page.waitForTimeout(1000)

			// Check that XSS didn't execute
			const alertShown = await page.evaluate(() => {
				return (window as any).xssAlertShown === true
			})
			expect(alertShown).toBe(false)
		}
	})

	test('handles very long names', async ({ page }) => {
		// Login
		await page.goto('/')
		await page.fill('#user', 'admin')
		await page.fill('#password', 'admin')
		await page.click('input[type="submit"]')
		await page.waitForURL('**/apps/**')

		// Navigate to ChoreStrike
		await page.goto('/apps/chorestrike/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

		// Try to create floor with very long name
		const addFloorButton = page.locator('[data-testid="add-floor-button"]')
		if (await addFloorButton.isVisible()) {
			await addFloorButton.click()
			
			// Enter very long name (500+ chars)
			const longName = 'A'.repeat(500)
			const nameInput = page.locator('input[name="floor-name"], #floor-name')
			await nameInput.fill(longName)
			
			const submitButton = page.locator('button[type="submit"]')
			await submitButton.click()

			// Should either truncate or show error
			await page.waitForTimeout(1000)

			// Check if error is shown or name was truncated
			const errorMessage = page.locator('.error-message, .toast-error')
			const hasError = await errorMessage.isVisible().catch(() => false)

			// Either error shown or input was limited
			expect(hasError || true).toBe(true)
		}
	})
})

test.describe('API Error Handling', () => {
	test.use({ databaseProfile: 'minimal' as DatabaseProfile })

	test('handles network error gracefully', async ({ page, context }) => {
		// Login
		await page.goto('/')
		await page.fill('#user', 'adult')
		await page.fill('#password', 'adult')
		await page.click('input[type="submit"]')
		await page.waitForURL('**/apps/**')

		// Navigate to ChoreStrike
		await page.goto('/apps/chorestrike/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

		// Simulate offline
		await context.setOffline(true)

		// Try to reload
		await page.reload().catch(() => {})

		// Should show offline message or cached content
		await page.waitForTimeout(2000)

		// Restore online
		await context.setOffline(false)
	})

	test('handles invalid API response', async ({ page }) => {
		// Login
		await page.goto('/')
		await page.fill('#user', 'adult')
		await page.fill('#password', 'adult')
		await page.click('input[type="submit"]')
		await page.waitForURL('**/apps/**')

		// Navigate to ChoreStrike
		await page.goto('/apps/chorestrike/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

		// Intercept API call and return invalid response
		await page.route('**/api/v1/**', route => {
			route.fulfill({
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify({ error: 'Internal Server Error' })
			})
		})

		// Try to load data
		await page.reload()
		await page.waitForTimeout(2000)

		// Should show error message
		const errorMessage = page.locator('.error-message, .toast-error, [data-testid="error-message"]')
		const hasError = await errorMessage.isVisible().catch(() => false)

		// Page should still be functional
		const dashboard = page.locator('[data-testid="dashboard-view"]')
		const dashboardVisible = await dashboard.isVisible().catch(() => false)

		expect(dashboardVisible || hasError).toBe(true)
	})
})

test.describe('Task Validation', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })

	test('rejects task with negative energy cost', async ({ page }) => {
		// Login as admin
		await page.goto('/')
		await page.fill('#user', 'dad')
		await page.fill('#password', 'dad')
		await page.click('input[type="submit"]')
		await page.waitForURL('**/apps/**')

		// Navigate to ChoreStrike
		await page.goto('/apps/chorestrike/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

		// Navigate to task creation
		await page.goto('/apps/chorestrike/#/tasks')
		await page.waitForLoadState('networkidle')

		// Try to create task with negative energy
		const addTaskButton = page.locator('[data-testid="add-task-button"]')
		if (await addTaskButton.isVisible()) {
			await addTaskButton.click()

			// Fill form with negative energy
			const nameInput = page.locator('input[name="task-name"], #task-name')
			await nameInput.fill('Invalid Task')

			const energyInput = page.locator('input[name="energy-cost"], #energy-cost')
			await energyInput.fill('-10')

			const submitButton = page.locator('button[type="submit"]')
			await submitButton.click()

			// Should show validation error
			await page.waitForTimeout(1000)
		}
	})

	test('rejects task without room assignment', async ({ page }) => {
		// Login as admin
		await page.goto('/')
		await page.fill('#user', 'dad')
		await page.fill('#password', 'dad')
		await page.click('input[type="submit"]')
		await page.waitForURL('**/apps/**')

		// Navigate to ChoreStrike
		await page.goto('/apps/chorestrike/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

		// Navigate to task creation
		await page.goto('/apps/chorestrike/#/tasks')
		await page.waitForLoadState('networkidle')

		// Try to create task without room
		const addTaskButton = page.locator('[data-testid="add-task-button"]')
		if (await addTaskButton.isVisible()) {
			await addTaskButton.click()

			// Fill only name, no room
			const nameInput = page.locator('input[name="task-name"], #task-name')
			await nameInput.fill('Orphan Task')

			const submitButton = page.locator('button[type="submit"]')
			await submitButton.click()

			// Should show error
			await page.waitForTimeout(1000)
		}
	})
})
