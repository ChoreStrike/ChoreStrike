import { test, expect } from '@playwright/test'

/**
 * E2E test: the consent-filtered Leaderboard in its DEFAULT (opt-in
 * closed) state (CS-PLAN-070 WP4, C5i/C5l/D10b).
 *
 * The family E2E seed leaves the house ranking flag OFF, so this
 * read-only pool pins: the opt-in/closed surface, the absence of the
 * unconditional member ranking, contribution scores and freeze
 * indicators, and the retained weekly-medal catalog. The consent
 * lifecycle against an ENABLED flag lives in ranking-consent.spec.ts
 * (serial family pool, owner-driven).
 */

test.describe('Leaderboard and Gamification (consent-filtered)', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)

		await page.goto('/apps/chorestrike/#/dashboard')
		await page.waitForLoadState('domcontentloaded')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })
	})

	test('User can view leaderboard with rankings', async ({ page }) => {
		await page.getByTestId('nav-leaderboard').click()
		await expect(page.getByTestId('leaderboard-view')).toBeVisible({ timeout: 20000 })
	})

	test('Disabled ranking shows the opt-in/closed state (default seed)', async ({ page }) => {
		await page.goto('/apps/chorestrike/#/leaderboard')
		await expect(page.getByTestId('leaderboard-view')).toBeVisible({ timeout: 20000 })

		await expect(page.getByTestId('ranking-disabled')).toBeVisible({ timeout: 15000 })
		// No ranked list exists while the flag is off.
		await expect(page.getByTestId('leaderboard-list')).toHaveCount(0)
	})

	test('No freeze indicators or contribution score column remain', async ({ page }) => {
		await page.goto('/apps/chorestrike/#/leaderboard')
		await expect(page.getByTestId('leaderboard-view')).toBeVisible({ timeout: 20000 })

		await expect(page.getByTestId('ranking-disabled')).toBeVisible({ timeout: 15000 })
		const html = await page.locator('.leaderboard').innerHTML()
		expect(html).not.toContain('freeze')
		expect(html).not.toContain('score-col')
		expect(html).not.toContain('streak-badge')
	})

	test('Weekly medals render from the game store when medals exist', async ({ page }) => {
		await page.goto('/apps/chorestrike/#/leaderboard')
		await expect(page.getByTestId('leaderboard-view')).toBeVisible({ timeout: 20000 })

		const medalSummary = page.getByTestId('medal-summary')
		await medalSummary.waitFor({ state: 'visible', timeout: 15000 }).catch(() => {
			// Medals are weekly awards; an empty house legitimately has none.
		})

		if (await medalSummary.isVisible()) {
			const badges = page.getByTestId('medal-badge')
			const count = await badges.count()
			// WP4 cutover: the retained recognition catalog is the rhythm
			// tiers plus the household weekly milestone.
			for (let i = 0; i < count; i++) {
				await expect(badges.nth(i)).toHaveAttribute('data-medal-type', /rhythm_(3|6|12)|weekly_milestone/)
			}
		}
	})
})
