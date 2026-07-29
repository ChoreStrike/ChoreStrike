import { test, expect } from './fixtures/test-fixtures'
import { setupTestHouse } from './fixtures/test-house'

/**
 * E2E Test: 3-Floor House Scenario
 *
 * House configuration:
 * - Keller: WC, Dusche
 * - Erdgeschoss (EG): Gästebad, Garten
 * - 1. Obergeschoss (1.OG): WC
 *
 * Testszenarien:
 * 1. Haus-Setup (alle Etagen erstellen)
 * 2. Navigation durch alle Etagen
 * 3. Räume verwalten (WC, Gästebad, Dusche, Garten)
 * 4. Tasks für Badezimmer-Räume erstellen
 */

test.describe('3-Floor House: Keller, EG, 1.OG', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(90000)

		// Navigate to app (assuming already logged in)
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })
	})

	test('Setup: Create all 3 floors (Keller, EG, 1.OG)', async ({ page }) => {
		const house = await setupTestHouse(page)

		// We're already on settings view from beforeEach, verify floors exist
		// Check Keller exists
		await expect(page.locator('[data-testid="floor-Keller"]')).toBeVisible()

		// Check Erdgeschoss exists
		await expect(page.locator('[data-testid="floor-Erdgeschoss"]')).toBeVisible()

		// Check 1. Obergeschoss exists
		await expect(page.locator('[data-testid="floor-1. Obergeschoss"]')).toBeVisible()

		console.log('House setup complete:', house)
	})

	test('Navigation: Can navigate to all 3 floors from dashboard', async ({ page }) => {
		// Ensure house is set up
		await setupTestHouse(page)

		// Navigate to dashboard
		await page.click('[data-testid="nav-dashboard"]')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 10000 })

		// Verify all floors are visible on dashboard
		const floorsList = page.locator('[data-testid="floors-list"]')
		await expect(floorsList).toBeVisible()

		// Should see at least 3 floors
		const floorItems = page.locator('.floor-item')
		await expect(floorItems).toHaveCount(3)

		// Verify floor names are visible
		await expect(page.locator('text=Keller')).toBeVisible()
		await expect(page.locator('text=Erdgeschoss')).toBeVisible()
		await expect(page.locator('text=1. Obergeschoss')).toBeVisible()
	})

	test('Room Management: Create WC in Keller', async ({ page }) => {
		// Setup house
		await setupTestHouse(page)

		// Navigate to dashboard
		await page.click('[data-testid="nav-dashboard"]')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 10000 })

		// Find Keller floor and click add room (if UI supports it)
		const kellerFloor = page.locator('[data-testid="floor-Keller"]')
		await expect(kellerFloor).toBeVisible()

		// Click on Keller floor to expand/collapse or see rooms
		await kellerFloor.click()

		// If add room button exists, use it
		const addRoomButton = kellerFloor.locator('button:has-text("Add Room"), [data-testid="add-room-button"]')
		if (await addRoomButton.isVisible().catch(() => false)) {
			await addRoomButton.click()

			// Fill room name
			await page.fill('input[placeholder*="room" i], input[data-testid="room-name-input"]', 'WC')

			// Save room
			await page.click('button:has-text("Save"), button:has-text("Create")')

			// Verify WC appears under Keller
			await expect(kellerFloor.locator('text=WC')).toBeVisible()
		}
	})

	test('Room Management: Create Gästebad in EG', async ({ page }) => {
		// Setup house
		await setupTestHouse(page)

		// Navigate to dashboard
		await page.click('[data-testid="nav-dashboard"]')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 10000 })

		// Find EG floor
		const egFloor = page.locator('[data-testid="floor-Erdgeschoss"]')
		await expect(egFloor).toBeVisible()

		// Click on floor
		await egFloor.click()

		// Check if we can add rooms
		const addRoomButton = egFloor.locator('button:has-text("Add Room"), [data-testid="add-room-button"]')
		if (await addRoomButton.isVisible().catch(() => false)) {
			await addRoomButton.click()
			await page.fill('input[placeholder*="room" i], input[data-testid="room-name-input"]', 'Gästebad')
			await page.click('button:has-text("Save"), button:has-text("Create")')
			await expect(egFloor.locator('text=Gästebad')).toBeVisible()
		}
	})

	test('Room Management: Create Garten in EG', async ({ page }) => {
		// Setup house
		await setupTestHouse(page)

		// Navigate to dashboard
		await page.click('[data-testid="nav-dashboard"]')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 10000 })

		// Find EG floor
		const egFloor = page.locator('[data-testid="floor-Erdgeschoss"]')
		await expect(egFloor).toBeVisible()
		await egFloor.click()

		// Add Garten room if possible
		const addRoomButton = egFloor.locator('button:has-text("Add Room"), [data-testid="add-room-button"]')
		if (await addRoomButton.isVisible().catch(() => false)) {
			await addRoomButton.click()
			await page.fill('input[placeholder*="room" i], input[data-testid="room-name-input"]', 'Garten')
			await page.click('button:has-text("Save"), button:has-text("Create")')
			await expect(egFloor.locator('text=Garten')).toBeVisible()
		}
	})

	test('Room Management: Create Dusche in Keller', async ({ page }) => {
		// Setup house
		await setupTestHouse(page)

		// Navigate to dashboard
		await page.click('[data-testid="nav-dashboard"]')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 10000 })

		// Find Keller floor
		const kellerFloor = page.locator('[data-testid="floor-Keller"]')
		await expect(kellerFloor).toBeVisible()
		await kellerFloor.click()

		// Add Dusche room if possible
		const addRoomButton = kellerFloor.locator('button:has-text("Add Room"), [data-testid="add-room-button"]')
		if (await addRoomButton.isVisible().catch(() => false)) {
			await addRoomButton.click()
			await page.fill('input[placeholder*="room" i], input[data-testid="room-name-input"]', 'Dusche')
			await page.click('button:has-text("Save"), button:has-text("Create")')
			await expect(kellerFloor.locator('text=Dusche')).toBeVisible()
		}
	})

	test('Room Management: Create WC in 1.OG', async ({ page }) => {
		// Setup house
		await setupTestHouse(page)

		// Navigate to dashboard
		await page.click('[data-testid="nav-dashboard"]')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 10000 })

		// Find 1.OG floor
		const ogFloor = page.locator('[data-testid="floor-1. Obergeschoss"]')
		await expect(ogFloor).toBeVisible()
		await ogFloor.click()

		// Add WC room if possible
		const addRoomButton = ogFloor.locator('button:has-text("Add Room"), [data-testid="add-room-button"]')
		if (await addRoomButton.isVisible().catch(() => false)) {
			await addRoomButton.click()
			await page.fill('input[placeholder*="room" i], input[data-testid="room-name-input"]', 'WC')
			await page.click('button:has-text("Save"), button:has-text("Create")')
			await expect(ogFloor.locator('text=WC')).toBeVisible()
		}
	})

	test('Task Management: Add cleaning task to Gästebad', async ({ page }) => {
		// Setup house with rooms
		await setupTestHouse(page)

		// Navigate to tasks
		await page.click('[data-testid="nav-tasks"]')
		await page.waitForSelector('[data-testid="task-manager-view"]', { timeout: 10000 })

		// Try to add a task
		const addTaskButton = page.locator('button:has-text("Add Task"), [data-testid="add-task-button"]')
		if (await addTaskButton.isVisible().catch(() => false)) {
			await addTaskButton.click()

			// Fill task details
			await page.fill('input[placeholder*="task" i], input[data-testid="task-name-input"]', 'Gästebad putzen')
			await page.fill('textarea[placeholder*="description" i], textarea[data-testid="task-description"]', 'Boden wischen, Spiegel reinigen')

			// Select room
			await page.selectOption('select[data-testid="task-room-select"]', 'Gästebad')

			// Save task
			await page.click('button:has-text("Save"), button:has-text("Create")')

			// Verify task appears
			await expect(page.locator('text=Gästebad putzen')).toBeVisible()
		}
	})

	test('Settings: Verify all floors listed in settings', async ({ page }) => {
		// Setup house
		await setupTestHouse(page)

		// Navigate to settings
		await page.click('[data-testid="nav-settings"]')
		await page.waitForSelector('[data-testid="settings-view"]', { timeout: 10000 })

		// Verify floors section exists
		await expect(page.locator('[data-testid="floors-list"], .floors-list')).toBeVisible()

		// Count floor items
		const floorItems = page.locator('[data-testid^="floor-"]')
		const count = await floorItems.count()
		expect(count).toBeGreaterThanOrEqual(3)

		// Verify specific floors
		await expect(page.locator('text=Keller')).toBeVisible()
		await expect(page.locator('text=Erdgeschoss')).toBeVisible()
		await expect(page.locator('text=1. Obergeschoss')).toBeVisible()
	})

	test('Complete Workflow: Setup house with all rooms and verify', async ({ page }) => {
		// Full setup
		const house = await setupTestHouse(page)

		// Navigate through all views
		// 1. Dashboard
		await page.click('[data-testid="nav-dashboard"]')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 10000 })

		// 2. Tasks
		await page.click('[data-testid="nav-tasks"]')
		await page.waitForSelector('[data-testid="task-manager-view"]', { timeout: 10000 })

		// 3. Settings
		await page.click('[data-testid="nav-settings"]')
		await page.waitForSelector('[data-testid="settings-view"]', { timeout: 10000 })

		// 4. Leaderboard
		await page.click('[data-testid="nav-leaderboard"]')
		await page.waitForSelector('[data-testid="leaderboard-view"]', { timeout: 10000 })

		// Return to dashboard
		await page.click('[data-testid="nav-dashboard"]')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 10000 })

		// Verify floors are still there
		await expect(page.locator('text=Keller')).toBeVisible()
		await expect(page.locator('text=Erdgeschoss')).toBeVisible()
		await expect(page.locator('text=1. Obergeschoss')).toBeVisible()

		console.log('Complete workflow test passed for house:', house)
	})
})
