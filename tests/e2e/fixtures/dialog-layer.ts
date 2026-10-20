/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Dialog stacking check (CS-PLAN-087 RF-087-09): an open app dialog must lie
 * above Nextcloud's navigation toggle. Rendered inside the app content's
 * stacking context, the toggle stayed on top and covered the dialog.
 */

import type { Locator, Page } from '@playwright/test'

import { expect } from '@playwright/test'

/**
 * Assert that the topmost element under the navigation toggle's center
 * belongs to the open dialog's overlay.
 *
 * @param page - Page with an open dialog.
 * @param dialog - The dialog (or its overlay).
 */
export async function expectDialogAboveNavigationToggle(page: Page, dialog: Locator): Promise<void> {
	const toggle = await page.locator('button.app-navigation-toggle').boundingBox()
	expect(toggle).not.toBeNull()
	const covered = await dialog.evaluate((element, point) => {
		const layer = element.closest('.modal-overlay') ?? element
		const top = document.elementFromPoint(point.x, point.y)
		return top !== null && layer.contains(top)
	}, { x: toggle!.x + toggle!.width / 2, y: toggle!.y + toggle!.height / 2 })
	expect(covered).toBe(true)
}
