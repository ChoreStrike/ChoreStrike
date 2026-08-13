/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Release E2E regression — Setup Wizard (CS-PLAN-027).
 *
 * The first-run gate (decision D3) bounds the wizard to a structurally
 * empty house. On the `family` profile the house already has floors, so the
 * apply endpoint must reject with 409 HOUSE_ALREADY_SET_UP. The catalog
 * endpoint stays available regardless. The full happy-path wizard
 * (empty house → populated) is covered by HouseSetupIntegrationTest (PHPUnit,
 * fresh instance) and the SetupWizard Jest component spec; an E2E
 * happy path needs a user-bearing empty profile which the test fixtures do
 * not provide.
 *
 * Verified:
 *  - GET /setup/catalog is reachable and non-empty.
 *  - POST /setup/apply on an already-set-up house returns 409.
 *
 * Requires: Docker test environment with the `family` database profile.
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'

test.describe('Release: Setup Wizard', () => {
	test.use({ databaseProfile: 'family' })

	test('setup catalog is available on a populated house', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const response = await page.request.get('/apps/chorestrike/api/v1/setup/catalog', {
				headers: { Accept: 'application/json' },
			})
			expect(response.status()).toBe(200)
			const body = await response.json()
			expect(body.success).toBe(true)
			expect(body.data.levels.length).toBe(2)
			expect(body.data.roomTypes.length).toBeGreaterThan(0)
		} finally {
			await context.close()
		}
	})

	test('apply is rejected with 409 when the house already has floors', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const response = await page.request.post('/apps/chorestrike/api/v1/setup/apply', {
				data: { intensityLevel: 'putzteufel', floors: [{ name: 'GF', rooms: [] }] },
				headers: { Accept: 'application/json' },
			})
			expect(response.status()).toBe(409)
			const body = await response.json()
			expect(body.success).toBe(false)
			expect(body.error.code).toBe('HOUSE_ALREADY_SET_UP')
		} finally {
			await context.close()
		}
	})
})
