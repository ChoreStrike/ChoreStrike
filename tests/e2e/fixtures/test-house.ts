import { test as base, expect, type Page } from '@playwright/test'

/**
 * Test data fixture for ChoreStrike E2E tests
 * Creates a 3-floor house with specific rooms for testing
 *
 * House configuration:
 * - Floor 1: Keller
 *   - WC
 *   - Dusche
 * - Floor 2: Erdgeschoss (EG)
 *   - Gästebad
 *   - Garten
 * - Floor 3: 1. Obergeschoss (1.OG)
 *   - WC
 */

export type TestHouseData = {
	floors: {
		keller: { id: number | null; name: string }
		eg: { id: number | null; name: string }
		og: { id: number | null; name: string }
	}
	rooms: {
		kellerWC: { id: number | null; name: string; floor: string }
		kellerDusche: { id: number | null; name: string; floor: string }
		egGaestebad: { id: number | null; name: string; floor: string }
		egGarten: { id: number | null; name: string; floor: string }
		ogWC: { id: number | null; name: string; floor: string }
	}
}

/**
 * Creates the 3-floor test house with specific rooms
 */
export async function setupTestHouse(page: Page): Promise<TestHouseData> {
	const testData: TestHouseData = {
		floors: {
			keller: { id: null, name: 'Keller' },
			eg: { id: null, name: 'Erdgeschoss' },
			og: { id: null, name: '1. Obergeschoss' },
		},
		rooms: {
			kellerWC: { id: null, name: 'WC', floor: 'Keller' },
			kellerDusche: { id: null, name: 'Dusche', floor: 'Keller' },
			egGaestebad: { id: null, name: 'Gästebad', floor: 'Erdgeschoss' },
			egGarten: { id: null, name: 'Garten', floor: 'Erdgeschoss' },
			ogWC: { id: null, name: 'WC', floor: '1. Obergeschoss' },
		},
	}

	// Navigate to settings
	await page.goto('/apps/chorestrike/')
	await page.waitForLoadState('networkidle')
	await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 10000 })

	await page.click('[data-testid="nav-settings"]')
	await page.waitForSelector('[data-testid="settings-view"]', { timeout: 10000 })

	// Helper to create floor
	const createFloor = async (floorName: string) => {
		// Check if floor already exists
		const floorSelector = `[data-testid="floor-${floorName}"]`
		const existingFloor = page.locator(floorSelector)

		if (await existingFloor.isVisible().catch(() => false)) {
			console.log(`Floor "${floorName}" already exists`)
			return
		}

		console.log(`Creating floor: ${floorName}`)

		// Click add floor button
		await page.click('[data-testid="add-floor-button"]')
		await page.waitForSelector('.modal-content', { timeout: 5000 })

		// Fill floor name — use the modal-specific input id to avoid matching
		// the NC contacts search bar that also has type="text".
		await page.fill('#new-floor-name', floorName)

		// Click create
		await page.click('button:has-text("Create")')

		// Wait for modal to close
		await page.waitForTimeout(500)

		// Verify floor was created
		await expect(page.locator(`text=${floorName}`)).toBeVisible()
	}

	// Create all 3 floors
	await createFloor('Keller')
	await createFloor('Erdgeschoss')
	await createFloor('1. Obergeschoss')

	return testData
}

/**
 * Extended test with test house setup
 */
export const testWithHouse = base.extend<{
	testHouse: TestHouseData
}>({
	testHouse: async ({ page }, use) => {
		const house = await setupTestHouse(page)
		await use(house)
	},
})

export { expect }
