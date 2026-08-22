import { existsSync } from 'node:fs'
import { test, expect, type Page } from '@playwright/test'
import { createUserContext, userStatePath } from './fixtures/auth-contexts'

/**
 * E2E test: Room and floor management (CS-PLAN-042, UX-004)
 *
 * Structure editing lives in exactly one surface: Settings (owner-only).
 * The Dashboard FloorList is read-only; room rename/delete is reachable
 * from the RoomDetail header.
 *
 * The primary actor is `mom` (a seeded family-house OWNER): the structure
 * controls under test are owner-gated, and the default `admin` identity is
 * a plain member under the family profile.
 */

/** Expand collapsed dashboard floor sections until the room card renders. */
async function expandUntilRoomVisible(page: Page, roomCard: ReturnType<Page['locator']>): Promise<void> {
	if (await roomCard.isVisible().catch(() => false)) {
		return
	}
	// The floor sections render asynchronously after the dashboard mounts â€”
	// wait for them before counting, or the loop below would no-op.
	await page.locator('.floor-section').first().waitFor({ state: 'attached', timeout: 15000 }).catch(() => undefined)

	// Expand bottom-up (the top rows sit under NC toast overlays) and never
	// swallow click errors â€” a silently dropped toggle looks identical to a
	// missing room.
	for (let i = 0; i < 30; i++) {
		if (await roomCard.isVisible().catch(() => false)) {
			return
		}
		const expandButtons = page.getByRole('button', { name: 'Expand floor' })
		const remaining = await expandButtons.count()
		if (remaining === 0) {
			return
		}
		await expandButtons.nth(remaining - 1).click()
	}
}

test.describe('Room and Floor Management', () => {
	test.use({ storageState: userStatePath('mom') })

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

	test('User can add room to a floor via the Settings structure section', async ({ page }) => {
		await page.getByTestId('nav-settings').click()
		await page.waitForSelector('[data-testid="settings-view"]', { timeout: 20000 })

		// Create a floor if none exists
		const noFloorsMessage = page.locator('[data-testid="no-floors-message"]')
		if (await noFloorsMessage.isVisible().catch(() => false)) {
			await page.click('[data-testid="create-first-floor"]')
			await page.waitForSelector('.modal-content', { timeout: 15000 })
			await page.fill('#new-floor-name', 'Test Floor')
			await page.click('button:has-text("Create")')
			await page.waitForTimeout(1000)
		}

		// Expand the first floor row and add a room inline
		const floorItem = page.locator('.floor-item').first()
		await floorItem.locator('[data-testid^="expand-floor-"]').click()
		await floorItem.locator('[data-testid="new-room-name-input"]').fill('Test Room')
		await floorItem.locator('[data-testid="add-room-to-floor-button"]').click()

		await expect(floorItem.locator('.room-row-name', { hasText: 'Test Room' })).toBeVisible({ timeout: 15000 })
	})

	test('Dashboard FloorList is read-only (no structure controls)', async ({ page }) => {
		await page.locator('.floor-section').first().waitFor({ state: 'attached', timeout: 15000 })

		await expect(page.locator('.floor-list .add-button')).toHaveCount(0)
		await expect(page.locator('[data-testid^="add-room-button-"]')).toHaveCount(0)
		await expect(page.locator('[data-testid^="add-room-card-"]')).toHaveCount(0)
		await expect(page.locator('.floor-list [data-testid^="move-floor-"]')).toHaveCount(0)
		// Navigation aids stay available
		await expect(page.getByTestId('refresh-floors-button')).toBeVisible()
	})

	test('Owner can rename a room from the RoomDetail header', async ({ page }) => {
		await page.getByTestId('nav-settings').click()
		await page.waitForSelector('[data-testid="settings-view"]', { timeout: 20000 })

		// Ensure a room exists (create via Settings when needed)
		const floorItem = page.locator('.floor-item').first()
		await floorItem.locator('[data-testid^="expand-floor-"]').click()
		const existingRoom = floorItem.locator('[data-testid^="delete-room-"]').first()
		if (!(await existingRoom.isVisible().catch(() => false))) {
			await floorItem.locator('[data-testid="new-room-name-input"]').fill('Rename Target')
			await floorItem.locator('[data-testid="add-room-to-floor-button"]').click()
			await expect(floorItem.locator('.room-row-name', { hasText: 'Rename Target' })).toBeVisible({ timeout: 15000 })
		}

		// Open the first room from the dashboard (floors render collapsed â€”
		// expand until a room card is visible)
		await page.getByTestId('nav-dashboard').click()
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })
		const roomCards = page.locator('[data-testid^="room-card-"]')
		await expandUntilRoomVisible(page, roomCards.first())
		if (await roomCards.count() === 0) {
			test.skip()
			return
		}
		await roomCards.first().click()
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="room-detail-view"]', { timeout: 20000 })

		const oldName = await page.getByTestId('room-name').textContent()

		// Rename via the header action
		await page.getByTestId('rename-room-button').click()
		await page.getByTestId('room-rename-input').fill(`${oldName} II`)
		await page.getByTestId('room-rename-save').click()

		await expect(page.getByTestId('room-name')).toHaveText(`${oldName} II`, { timeout: 15000 })
	})

	test('Owner can delete a room from the RoomDetail header', async ({ page }) => {
		await page.getByTestId('nav-settings').click()
		await page.waitForSelector('[data-testid="settings-view"]', { timeout: 20000 })

		// Create a dedicated room to delete
		const floorItem = page.locator('.floor-item').first()
		await floorItem.locator('[data-testid^="expand-floor-"]').click()
		await floorItem.locator('[data-testid="new-room-name-input"]').fill('Doomed Room')
		await floorItem.locator('[data-testid="add-room-to-floor-button"]').click()
		await expect(floorItem.locator('.room-row-name', { hasText: 'Doomed Room' })).toBeVisible({ timeout: 15000 })

		await page.getByTestId('nav-dashboard').click()
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })
		const doomedCard = page.locator('[data-testid^="room-card-"]', { hasText: 'Doomed Room' }).first()
		await expandUntilRoomVisible(page, doomedCard)
		await doomedCard.click()
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="room-detail-view"]', { timeout: 20000 })

		await page.getByTestId('delete-room-button').click()
		await expect(page.getByTestId('confirm-dialog')).toBeVisible()
		await page.getByTestId('confirm-dialog-confirm').click()

		// Back on the dashboard, the room card is gone (expand the sections
		// again so the absence assertion is meaningful)
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })
		const doomedCardAfterDelete = page.locator('[data-testid^="room-card-"]', { hasText: 'Doomed Room' }).first()
		if (!(await doomedCardAfterDelete.isVisible().catch(() => false))) {
			await expandUntilRoomVisible(page, doomedCardAfterDelete)
		}
		await expect(page.locator('[data-testid^="room-card-"]', { hasText: 'Doomed Room' })).toHaveCount(0)
	})

	test('Owner can reorder floors with the up/down buttons', async ({ page }) => {
		const suffix = Date.now().toString().slice(-6)
		const floorA = `A ${suffix}`
		const floorB = `B ${suffix}`

		await page.getByTestId('nav-settings').click()
		await page.waitForSelector('[data-testid="settings-view"]', { timeout: 20000 })

		for (const name of [floorA, floorB]) {
			await page.click('[data-testid="add-floor-button"]')
			await page.waitForSelector('.modal-content', { timeout: 15000 })
			await page.fill('#new-floor-name', name)
			await page.click('button:has-text("Create")')
			await page.locator(`[data-testid="floor-${name}"]`).waitFor({ state: 'visible', timeout: 15000 })
			await page.waitForTimeout(300)
		}

		// A sits above B right after creation (creation order = sort order)
		const indexOfFloor = (name: string) => page.getByTestId(`floor-${name}`).evaluate(
			(el) => Array.prototype.indexOf.call(el.parentElement.querySelectorAll('.floor-item'), el),
		)
		await expect(async () => {
			expect(await indexOfFloor(floorA)).toBeLessThan(await indexOfFloor(floorB))
		}).toPass({ timeout: 20000 })

		// Move A one position down -> B comes first. Relative comparisons +
		// polling keep this correct even if other floors appear in between.
		await page.locator(`[data-testid="floor-${floorA}"] [data-testid^="move-floor-down-"]`).click()
		await expect(async () => {
			expect(await indexOfFloor(floorB)).toBeLessThan(await indexOfFloor(floorA))
		}).toPass({ timeout: 20000 })

		// Undo by moving A back up (its upper neighbour is B) -> A first again
		await page.locator(`[data-testid="floor-${floorA}"] [data-testid^="move-floor-up-"]`).click()
		await expect(async () => {
			expect(await indexOfFloor(floorA)).toBeLessThan(await indexOfFloor(floorB))
		}).toPass({ timeout: 20000 })
	})

	test('Member sees no owner-only structure controls', async ({ browser }) => {
		// The member context requires the seeded family profile.
		if (!existsSync(userStatePath('teen'))) {
			test.skip()
			return
		}

		const { context, page } = await createUserContext(browser, 'teen')
		try {
			await page.goto('/apps/chorestrike/')
			await page.getByTestId('dashboard-view').waitFor({ state: 'visible' })

			// Dashboard FloorList: read-only for everyone
			await expect(page.locator('.floor-list .add-button')).toHaveCount(0)

			// Settings: owner-only sections hidden for members
			await page.getByTestId('nav-settings').click()
			await page.getByTestId('settings-view').waitFor({ state: 'visible' })

			await expect(page.getByTestId('house-section')).toHaveCount(0)
			await expect(page.getByTestId('floors-section')).toHaveCount(0)
			await expect(page.getByTestId('danger-zone')).toHaveCount(0)
		} finally {
			await context.close()
		}
	})
})
