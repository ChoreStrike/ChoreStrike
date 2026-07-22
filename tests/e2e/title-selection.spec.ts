import { test, expect } from './fixtures/test-fixtures'

/**
 * E2E test: Role title selection (CS-PLAN-006).
 *
 * Verifies a member can open the title picker from Settings, the catalog
 * renders, selecting a pack marks it active, and the badge/active state
 * updates. Unlock thresholds are not exercised here (covered by the
 * TitleService integration test); this spec covers the selection UI flow.
 */
test.describe('Role Title System', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)

		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })

		await page.click('text=Settings')
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

		// Pick the team_energy pack via its select button.
		const teamCard = page.locator('[data-testid="title-pack-team_energy"]')
		const selectButton = teamCard.locator('[data-testid="select-pack-button"]')

		// The button only exists when the pack is not already active.
		if (await selectButton.isVisible().catch(() => false)) {
			await selectButton.click()
			// Wait for the API round-trip to flip the card to active.
			await expect(teamCard.locator('[data-testid="active-pack-tag"]')).toBeVisible({ timeout: 10000 })
		} else {
			// Already active from a prior run — assert the active tag is present.
			await expect(teamCard.locator('[data-testid="active-pack-tag"]')).toBeVisible()
		}
	})
})
