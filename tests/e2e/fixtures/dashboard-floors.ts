import { type Locator, type Page, expect } from '@playwright/test'

/**
 * Dashboard floor-expansion helpers (CS-PLAN-050).
 *
 * The Dashboard FloorList renders every floor collapsed by default
 * (`expandedFloors` starts empty in the rooms store), so specs that need
 * room cards must expand the sections first. These helpers replace the
 * copy-pasted expand loops that previously padded each toggle click with
 * fixed `waitForTimeout` sleeps.
 */

/**
 * Expand every dashboard floor section and wait for its content to render.
 *
 * Auto-waits per section: the toggle click is issued on the collapsed
 * button (matched via the LOCALE-INDEPENDENT testid — profile users render
 * in their own Nextcloud language, so the translated aria-label is not a
 * stable selector) and the expanded content assertion fails loudly instead
 * of silently swallowing expansion errors.
 *
 * @param page - Page showing the ChoreStrike dashboard.
 */
export async function expandAllFloors(page: Page): Promise<void> {
	const sections = page.locator('.floor-section')
	await sections.first().waitFor({ state: 'attached', timeout: 15000 })
	const count = await sections.count()
	for (let i = 0; i < count; i++) {
		const section = sections.nth(i)
		const content = section.locator('.floor-content')
		if (await content.isVisible()) {
			continue
		}
		await section.getByTestId('floor-expand-toggle').click()
		await expect(content).toBeVisible({ timeout: 5000 })
	}
}

/**
 * Expand collapsed dashboard floor sections (bottom-up, because the top
 * rows sit under NC toast overlays) until the given room-card locator is
 * visible. Returns as soon as the room renders; never swallows click
 * errors — a silently dropped toggle looks identical to a missing room.
 *
 * @param page - Page showing the ChoreStrike dashboard.
 * @param roomCard - Locator for the room card that should become visible.
 */
export async function expandUntilRoomVisible(page: Page, roomCard: Locator): Promise<void> {
	if (await roomCard.isVisible().catch(() => false)) {
		return
	}
	// The floor sections render asynchronously after the dashboard mounts —
	// wait for them before counting, or the loop below would no-op.
	await page.locator('.floor-section').first().waitFor({ state: 'attached', timeout: 15000 }).catch(() => undefined)

	for (let i = 0; i < 30; i++) {
		if (await roomCard.isVisible().catch(() => false)) {
			return
		}
		// Collapsed toggles only: the expanded state carries the same
		// testid, so an unfiltered match would flip sections forever.
		const expandButtons = page.locator('.floor-section .toggle-button:not(.expanded)')
		const remaining = await expandButtons.count()
		if (remaining === 0) {
			return
		}
		await expandButtons.nth(remaining - 1).click()
	}
}
