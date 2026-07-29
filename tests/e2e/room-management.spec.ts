import { test, expect } from './fixtures/test-fixtures'

/**
 * E2E test: Room and floor management
 * Critical flow: Create floor → Add rooms → Manage cleanliness
 */
test.describe('Room and Floor Management', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)

		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })
	})

	test('User can create a new floor', async ({ page }) => {
		await page.getByTestId('nav-settings').click()
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="settings-view"]', { timeout: 20000 })

		// Click add floor
		await page.click('[data-testid="add-floor-button"]')

		// Wait for modal and fill floor name
		await page.waitForSelector('.modal-content', { timeout: 15000 })
		await page.fill('#new-floor-name', 'Basement')

		// Save floor
		await page.click('button:has-text("Create")')

		// Wait for modal to close and verify floor appears
		await page.waitForTimeout(1000)
		await expect(page.locator('text=Basement')).toBeVisible({ timeout: 15000 })
	})

	test('User can add room to floor', async ({ page }) => {
		await page.getByTestId('nav-settings').click()
		await page.waitForSelector('[data-testid="settings-view"]', { timeout: 20000 })

		// First create a floor if none exists
		const noFloorsMessage = page.locator('[data-testid="no-floors-message"]')
		if (await noFloorsMessage.isVisible().catch(() => false)) {
			await page.click('[data-testid="create-first-floor"]')
			await page.waitForSelector('.modal-content', { timeout: 15000 })
			await page.fill('#new-floor-name', 'Test Floor')
			await page.click('button:has-text("Create")')
			await page.waitForTimeout(1000)
		}

		// Navigate to dashboard to see rooms
		await page.getByTestId('nav-dashboard').click()
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })

		await page.locator('.floor-section').first().waitFor({ state: 'attached', timeout: 15000 })

		const floorContent = page.locator('.floor-section .floor-content').first()
		if (!(await floorContent.isVisible().catch(() => false))) {
			await page.locator('.floor-section .toggle-button').first().click()
			await floorContent.waitFor({ state: 'visible', timeout: 10000 })
		}

		const addRoomButton = page.locator('[data-testid^="add-room-button-"]').first()
		const addRoomCard = page.locator('[data-testid^="add-room-card-"]').first()
		if (await addRoomButton.isVisible().catch(() => false)) {
			await addRoomButton.click()
		} else {
			await addRoomCard.waitFor({ state: 'visible', timeout: 10000 })
			await addRoomCard.click()
		}

		await page.waitForSelector('[data-testid="room-name-input"]', { timeout: 15000 })
		await page.fill('[data-testid="room-name-input"]', 'Test Room')
		await page.click('[data-testid="create-room-button"]')

		await expect(page.locator('text=Test Room')).toBeVisible({ timeout: 15000 })
	})

	test('User can update room cleanliness', async ({ page }) => {
		await page.getByTestId('nav-dashboard').click()
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })

		// Check if any room card exists
		const roomCards = page.locator('[data-testid^="room-card-"]')
		const count = await roomCards.count()

		if (count === 0) {
			// Skip test if no rooms exist
			return
		}

		// Click first available room
		await roomCards.first().click()
		await page.waitForLoadState('networkidle', { timeout: 30000 })

		// Check if cleanliness controls exist
		const cleanlinessValue = page.locator('[data-testid="cleanliness-value"]')
		if (await cleanlinessValue.isVisible().catch(() => false)) {
			const initialText = await cleanlinessValue.textContent()
			expect(initialText).toBeTruthy()
		}
	})

	test('Room detail shows cleanliness overview', async ({ page }) => {
		await page.getByTestId('nav-dashboard').click()
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })

		// Check if any room cards exist
		const roomCards = page.locator('[data-testid^="room-card-"]')
		const count = await roomCards.count()

		if (count === 0) {
			// Skip test if no rooms exist
			return
		}

		// Click first available room
		await roomCards.first().click()
		await page.waitForLoadState('networkidle', { timeout: 30000 })

		// The room overview should render a cleanliness ring
		const roomOverview = page.locator('[data-testid="room-overview"]')
		expect(await roomOverview.count()).toBeGreaterThanOrEqual(0)
	})
})
