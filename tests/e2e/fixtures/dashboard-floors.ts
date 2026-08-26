import { expect, type Locator, type Page } from '@playwright/test'

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
 * button (matched via its translated aria-label) and the expanded content
 * assertion fails loudly instead of silently swallowing expansion errors.
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
		await section.getByRole('button', { name: 'Expand floor' }).click()
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
		const expandButtons = page.getByRole('button', { name: 'Expand floor' })
		const remaining = await expandButtons.count()
		if (remaining === 0) {
			return
		}
		await expandButtons.nth(remaining - 1).click()
	}
}
