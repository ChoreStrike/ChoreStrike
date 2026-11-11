import { expect } from '@playwright/test'
import { existsSync } from 'node:fs'
import { createUserContext, userStatePath } from './fixtures/auth-contexts.ts'
import { test } from './fixtures/database-fixtures.ts'
import { listRooms, openRoom } from './fixtures/rooms.ts'

/**
 * E2E test: Room and floor management (CS-PLAN-042, UX-004)
 *
 * Structure editing lives in exactly one surface: Settings (owner-only).
 * Room rename/delete is also reachable from the RoomDetail header.
 *
 * The primary actor is `mom` (a seeded family-house OWNER): the structure
 * controls under test are owner-gated, and the default `admin` identity is
 * a plain member under the family profile.
 *
 * CS-PLAN-090 WP-B: the Dashboard's read-only floor list is gone, and
 * with it the scenario that it offers no structure controls. A room is
 * opened through the shared `fixtures/rooms.ts` helper.
 */

test.describe('Room and Floor Management', () => {
	test.use({ storageState: userStatePath('mom') })
	test.use({ databaseProfile: 'family' })

	test.beforeEach(async ({ page, seededData }) => {
		test.setTimeout(60000)
		expect(seededData).not.toBeNull()

		await page.goto('/apps/chorestrike/#/today')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="today-view"]', { timeout: 20000 })
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

	test('Owner can rename a room from the RoomDetail header', async ({ page }) => {
		await page.getByTestId('nav-settings').click()
		await page.waitForSelector('[data-testid="settings-view"]', { timeout: 20000 })

		// The family seed guarantees a room on the first floor — assert it
		// instead of silently creating a fallback.
		const floorItem = page.locator('.floor-item').first()
		await floorItem.locator('[data-testid^="expand-floor-"]').click()
		await expect(floorItem.locator('[data-testid^="delete-room-"]').first()).toBeVisible({ timeout: 15000 })

		const { name: oldName } = await openRoom(page)

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

		await openRoom(page, 'Doomed Room')

		await page.getByTestId('delete-room-button').click()
		await expect(page.getByTestId('confirm-dialog')).toBeVisible()
		await page.getByTestId('confirm-dialog-confirm').click()

		// The page of a deleted room has nothing to show: back on Today,
		// and the house no longer holds the room.
		await page.waitForSelector('[data-testid="today-view"]', { timeout: 20000 })
		expect((await listRooms(page)).map((room) => room.name)).not.toContain('Doomed Room')
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
		const indexOfFloor = (name: string) => page.getByTestId(`floor-${name}`).evaluate((el) => Array.prototype.indexOf.call(el.parentElement.querySelectorAll('.floor-item'), el))
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
		expect(existsSync(userStatePath('teen')), 'required child session must exist').toBe(true)

		const { context, page } = await createUserContext(browser, 'teen')
		try {
			await page.goto('/apps/chorestrike/#/today')
			await page.getByTestId('today-view').waitFor({ state: 'visible' })
			// No way into the setup wizard for a member, on Today either.
			await expect(page.getByTestId('open-setup-wizard')).toHaveCount(0)

			// Settings: owner-only sections hidden for members. The navigation
			// retries against the durable outcome (one-off load-flake class,
			// see BUG-E2E-005 history) instead of a fixed single attempt.
			await expect(async () => {
				await page.getByTestId('nav-settings').click()
				await page.getByTestId('settings-view').waitFor({ state: 'visible', timeout: 10000 })
			}).toPass({ timeout: 45000 })

			await expect(page.getByTestId('house-section')).toHaveCount(0)
			await expect(page.getByTestId('floors-section')).toHaveCount(0)
			await expect(page.getByTestId('danger-zone')).toHaveCount(0)
		} finally {
			await context.close()
		}
	})
})
