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
		const floorRow = page.getByTestId(`floor-${floorName}`).first()

		// The floors list renders asynchronously after Settings mounts. Give
		// the row a bounded chance to appear before treating it as missing —
		// an instant isVisible() probe races the fetch and would silently
		// create duplicate floors (the backend allows duplicate names).
		const floorExists = await floorRow.waitFor({ state: 'attached', timeout: 10000 })
			.then(() => true)
			.catch(() => false)

		if (floorExists) {
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

		// Verify the floor row rendered (auto-waiting replaces a fixed sleep;
		// scoped to the testid so duplicate text elsewhere cannot break it).
		await expect(floorRow).toBeVisible({ timeout: 10000 })
	}

	// Create all 3 floors
	await createFloor('Keller')
	await createFloor('Erdgeschoss')
	await createFloor('1. Obergeschoss')

	return testData
}

/**
 * Creates a room on a floor via the Settings structure section
 * (CS-PLAN-042, UX-004): the Dashboard FloorList is read-only and Settings
 * is the sole CRUD surface. Creates the floor when it is missing.
 */
export async function createRoomViaSettings(page: Page, floorName: string, roomName: string): Promise<void> {
	await page.click('[data-testid="nav-settings"]')
	await page.waitForSelector('[data-testid="settings-view"]', { timeout: 10000 })

	const floorItem = page.getByTestId(`floor-${floorName}`).first()
	// Bounded wait for the asynchronously rendered floors list (see
	// createFloor) — an instant visibility probe would race the fetch.
	const floorExists = await floorItem.waitFor({ state: 'attached', timeout: 10000 })
		.then(() => true)
		.catch(() => false)

	if (!floorExists) {
		await page.click('[data-testid="add-floor-button"]')
		await page.waitForSelector('.modal-content', { timeout: 5000 })
		await page.fill('#new-floor-name', floorName)
		await page.click('button:has-text("Create")')
		await floorItem.waitFor({ state: 'visible', timeout: 10000 })
	}

	await floorItem.locator('[data-testid^="expand-floor-"]').click()

	// Create-if-missing, mirroring `createFloor`'s bounded existence guard:
	// the backend allows duplicate room names and this spec's tests share
	// the serial DB, so an unconditional create can produce a second row
	// and break the (strict) name assertion below.
	const roomRow = floorItem.locator('.room-row-name', { hasText: roomName }).first()
	const roomExists = await roomRow.waitFor({ state: 'visible', timeout: 3000 })
		.then(() => true)
		.catch(() => false)
	if (roomExists) {
		console.log(`Room "${roomName}" already exists on floor "${floorName}"`)
		return
	}

	await floorItem.locator('[data-testid="new-room-name-input"]').fill(roomName)
	await floorItem.locator('[data-testid="add-room-to-floor-button"]').click()

	await expect(roomRow).toBeVisible({ timeout: 10000 })
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
