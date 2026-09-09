import { existsSync } from 'node:fs'
import { expect } from '@playwright/test'
import { test } from './fixtures/database-fixtures'
import { createUserContext, userStatePath } from './fixtures/auth-contexts'
import { expandUntilRoomVisible } from './fixtures/dashboard-floors'

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
 *
 * CS-PLAN-050: the local floor-expansion helper moved to the shared
 * `fixtures/dashboard-floors.ts`; the fixed sleeps before assertions were
 * dropped (the assertions themselves auto-wait).
 */

test.describe('Room and Floor Management', () => {
	test.use({ storageState: userStatePath('mom') })
	test.use({ databaseProfile: 'family' })

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

		// Save floor — the visibility assertion below auto-waits for the
		// floors list to refresh after the POST.
		await page.click('button:has-text("Create")')

		await expect(page.locator('text=Basement')).toBeVisible({ timeout: 15000 })
	})

	test('User can add room to a floor via the Settings structure section', async ({ page }) => {
		await page.getByTestId('nav-settings').click()
		await page.waitForSelector('[data-testid="settings-view"]', { timeout: 20000 })

		// The family seed guarantees at least one floor row for the owner.
		const floorItem = page.locator('.floor-item').first()
		await expect(floorItem).toBeVisible({ timeout: 15000 })

		// Expand the first floor row and add a room inline
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

		// The family seed guarantees a room on the first floor — assert it
		// instead of silently creating a fallback.
		const floorItem = page.locator('.floor-item').first()
		await floorItem.locator('[data-testid^="expand-floor-"]').click()
		await expect(floorItem.locator('[data-testid^="delete-room-"]').first()).toBeVisible({ timeout: 15000 })

		// Open the first room from the dashboard (floors render collapsed —
		// expand until a room card is visible)
		await page.getByTestId('nav-dashboard').click()
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })
		const roomCards = page.locator('[data-testid^="room-card-"]')
		await expandUntilRoomVisible(page, roomCards.first())
		test.skip(await roomCards.count() === 0, 'family seed guarantees rooms; none rendered')
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
		await expandUntilRoomVisible(page, doomedCardAfterDelete)
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

	test('Member sees no owner-only structure controls', async ({ browser, seededData }) => {
		// The member context requires the seeded family profile. Upstream
		// serial specs legitimately mutate roles/structure, so this test
		// opts into the `seededData` fixture: reset+reseed guarantees the
		// teen really is a plain MEMBER regardless of prior specs.
		expect(seededData).not.toBeNull()
		test.skip(!existsSync(userStatePath('teen')), 'seeded teen auth state missing (setup project did not run)')

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
