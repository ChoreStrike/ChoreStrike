import { expect, test } from '@playwright/test'

/**
 * E2E test: the single-series role title surface (CS-PLAN-070 WP4,
 * Q-075-02 Option A).
 *
 * The multi-pack picker is retired: Settings renders the server-derived
 * current title badge (or the empty hint) and there is NO selection
 * control; the retired `PUT /users/{id}/titles/select` route stays gone
 * (404). The unlock thresholds are not exercised here (covered by the
 * TitleService unit/integration tests); this spec pins the UI contract.
 *
 * CS-PLAN-015b: admin auth via project-level storageState (no loginAsUser).
 */
test.describe('Role Title System (single series)', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)

		await page.goto('/apps/chorestrike/#/dashboard')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })

		await page.getByTestId('nav-settings').click()
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="settings-view"]', { timeout: 20000 })
	})

	test('Title section renders without a change-title control (retired surface)', async ({ page }) => {
		// The title card exists; the picker trigger and picker are gone.
		await expect(page.locator('[data-testid="change-title-button"]')).toHaveCount(0)
		await expect(page.locator('[data-testid="title-picker"]')).toHaveCount(0)
		// Exactly one of: the earned-title badge or the empty hint.
		const badge = page.locator('[data-testid="title-badge"]')
		const noTitle = page.locator('[data-testid="no-title"]')
		await expect(badge.or(noTitle).first()).toBeVisible({ timeout: 10000 })
	})

	test('The titles API carries no pack selection and the select route stays gone', async ({ page }) => {
		const result = await page.evaluate(async () => {
			const headers = {
				Accept: 'application/json',
				'Content-Type': 'application/json',
				requesttoken: window.OC.requestToken,
			}
			const current = await window.fetch('/apps/chorestrike/api/v1/users/current', { headers })
			const body = await current.json()
			const userId = body?.data?.id ?? body?.id
			if (!userId) {
				return { error: 'no membership id' }
			}
			const titles = await window.fetch(`/apps/chorestrike/api/v1/users/${userId}/titles`, { headers })
			const titlesBody = await titles.json()
			const select = await window.fetch(`/apps/chorestrike/api/v1/users/${userId}/titles/select`, {
				method: 'PUT',
				headers,
				body: JSON.stringify({ pack: 'family_rhythm' }),
			})
			return {
				titlesStatus: titles.status,
				titlesData: titlesBody?.data ?? null,
				selectStatus: select.status,
			}
		})

		expect(result.error).toBeUndefined()
		// The retained read: currentTitle + unlocked set + the fixed catalog.
		expect(result.titlesStatus).toBe(200)
		expect(result.titlesData).toHaveProperty('currentTitle')
		expect(result.titlesData).toHaveProperty('unlockedTitles')
		expect(result.titlesData.catalog).toHaveProperty('family_rhythm')
		expect(Object.keys(result.titlesData.catalog)).toHaveLength(1)
		expect(result.titlesData).not.toHaveProperty('selectedTitlePack')
		// The retired selection route is gone.
		expect(result.selectStatus).toBe(404)
	})

	test('Reward-request reads stay available while the catalog is disabled (fourth role-display surface)', async ({ page }) => {
		// CS-PLAN-070 WP7 (CS-PLAN-078 §3.3/C4.10): the mine scope answers
		// for every member regardless of the gate/personal visibility —
		// the requester reference/placeholder rendering arrives with WP8,
		// the envelope is the pinned browser contract.
		const result = await page.evaluate(async () => {
			const headers = { Accept: 'application/json', requesttoken: window.OC.requestToken }
			const mine = await window.fetch('/apps/chorestrike/api/v1/reward-requests?scope=mine', { headers })
			return { status: mine.status, body: await mine.json() }
		})
		expect(result.status).toBe(200)
		expect(result.body.success).toBe(true)
		expect(Array.isArray(result.body.data.requests)).toBe(true)
	})
})
