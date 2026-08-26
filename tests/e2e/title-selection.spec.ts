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

		// The catalog renders asynchronously behind the loading hint; wait for
		// the card before probing its selection state.
		await expect(teamCard).toBeVisible({ timeout: 10000 })

		// Already active (selection persisted in a prior run) — the card
		// shows the Active tag instead of a select button; nothing to exercise.
		if (!(await selectButton.isVisible().catch(() => false))) {
			await expect(activeTag).toBeVisible()
			return
		}

		// Pack selection is unavailable for users without a house membership;
		// skip only when that is genuinely the case (no house user id) so a
		// rendered-but-disabled button never masks a real defect. Transport
		// errors must fail loudly, not skip.
		const currentUserId = await page.evaluate(async () => {
			const response = await window.fetch('/apps/chorestrike/api/v1/users/current', {
				headers: {
					Accept: 'application/json',
					requesttoken: window.OC.requestToken,
				},
			})
			if (!response.ok) {
				throw new Error(`GET /users/current failed with ${response.status}`)
			}
			const body = await response.json()
			return body?.data?.id ?? body?.id ?? null
		})
		if (!currentUserId) {
			test.skip()
			return
		}
		await expect(selectButton).toBeEnabled({ timeout: 10000 })

		// Button is enabled: the click must persist the selection. A failed
		// selection is a real defect, not a soft skip.
		const selectPutPromise = page.waitForResponse((response) => {
			const isPutRequest = response.request().method() === 'PUT'
			const pathname = new URL(response.url()).pathname
			return isPutRequest && pathname.endsWith('/titles/select')
		})
		await selectButton.click()
		const selectResponse = await selectPutPromise
		expect(selectResponse.ok()).toBeTruthy()

		// A successful selection closes the picker (Settings @selected) —
		// BUG-E2E-003: asserting the Active tag inside the closing modal was
		// impossible by construction. The durable outcome lives in the
		// reopened picker: the pack now renders as active.
		await expect(page.locator('[data-testid="title-picker"]')).toHaveCount(0, { timeout: 10000 })
		await page.click('[data-testid="change-title-button"]')
		await page.waitForSelector('[data-testid="title-picker"]', { timeout: 10000 })
		await expect(activeTag).toBeVisible({ timeout: 10000 })
	})
})
