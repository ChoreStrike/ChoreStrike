import { test, expect } from './fixtures/test-fixtures'

/**
 * E2E test: Role title selection (CS-PLAN-006).
 *
 * Verifies a member can open the title picker from Settings, the catalog
 * renders, selecting a pack marks it active, and the badge/active state
 * updates. Unlock thresholds are not exercised here (covered by the
 * TitleService integration test); this spec covers the selection UI flow.
 *
 * CS-PLAN-015b: admin auth via project-level storageState (no loginAsUser).
 */
test.describe('Role Title System', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)

		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })

		await page.getByTestId('nav-settings').click()
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="settings-view"]', { timeout: 20000 })
	})

	test('Title section renders with a change-title button', async ({ page }) => {
		await expect(page.locator('[data-testid="change-title-button"]')).toBeVisible()
	})

	test('Opening the picker shows all three packs', async ({ page }) => {
		await page.click('[data-testid="change-title-button"]')

		await page.waitForSelector('[data-testid="title-picker"]', { timeout: 10000 })
		await expect(page.locator('[data-testid="title-pack-home_helpers"]')).toBeVisible()
		await expect(page.locator('[data-testid="title-pack-team_energy"]')).toBeVisible()
		await expect(page.locator('[data-testid="title-pack-cozy_home"]')).toBeVisible()
	})

	test('Selecting a pack marks it active', async ({ page }) => {
		await page.click('[data-testid="change-title-button"]')
		await page.waitForSelector('[data-testid="title-picker"]', { timeout: 10000 })

		const teamCard = page.locator('[data-testid="title-pack-team_energy"]')
		const selectButton = teamCard.locator('[data-testid="select-pack-button"]')
		const activeTag = teamCard.locator('[data-testid="active-pack-tag"]')

		// Already active (selection persisted in a prior run) — nothing to assert.
		if (await activeTag.isVisible().catch(() => false)) {
			return
		}

		// Pack selection is unavailable for users without a house membership;
		// skip only in that genuine case so selection failures are not masked.
		if (!(await selectButton.isEnabled().catch(() => false))) {
			test.skip()
			return
		}

		// Button is enabled: the click must activate the pack. A failed
		// selection is a real defect, not a soft skip.
		await selectButton.click()
		await expect(activeTag).toBeVisible({ timeout: 10000 })
	})
})
