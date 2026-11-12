import type { Page } from '@playwright/test'

import { expect } from '@playwright/test'

/**
 * The ranking is a section of Insights (CS-PLAN-090 WP-E); the Leaderboard
 * page and its navigation entry are gone, and `/leaderboard` redirects.
 */

/**
 * Open Insights through the navigation and wait for the ranking section,
 * whatever its state (off, opt-in, ranked).
 *
 * @param page - Page of a signed-in household member, inside the app.
 */
export async function openRanking(page: Page): Promise<void> {
	await page.getByTestId('nav-insights').click()
	await expect(page.getByTestId('insights-view')).toBeVisible({ timeout: 30000 })
	const panel = page.getByTestId('ranking-panel')
	await expect(panel).toBeVisible({ timeout: 30000 })
	await expect(panel.getByTestId('ranking-loading')).toHaveCount(0, { timeout: 30000 })
	await panel.scrollIntoViewIfNeeded()
}
