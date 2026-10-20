/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Release E2E regression — Setup Wizard (CS-PLAN-027).
 *
 * The first-run gate bounds the wizard to a structurally empty house; since
 * CS-PLAN-062 §3.1 the gate is enforced inside the setup transaction (house
 * lock + floor recheck) and maps to the unchanged 409 envelope. On the
 * `family` profile the house already has floors, so the apply endpoint must
 * reject with 409 HOUSE_ALREADY_SET_UP. The catalog endpoint stays available
 * regardless. The full happy-path wizard journey (empty house → populated →
 * invited member view) lives in setup-wizard-journey.spec.ts against the
 * `emptyhouse` profile (CS-PLAN-062 §3.6).
 *
 * Verified:
 *  - GET /setup/catalog is reachable and non-empty.
 *  - POST /setup/apply on an already-set-up house returns 409.
 *
 * Requires: Docker test environment with the `family` database profile.
 */

import { createUserContext } from './fixtures/auth-contexts'
import { expect, test } from './fixtures/database-fixtures'

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
			// A fully valid payload: since CS-PLAN-062 §3.1 the payload is
			// validated before the in-transaction gate, so the 409 must come
			// from the gate itself, not from validation.
			const response = await page.request.post('/apps/chorestrike/api/v1/setup/apply', {
				data: {
					intensityLevel: 'putzteufel',
					floors: [{ name: 'GF', rooms: [{ name: 'Kitchen', roomType: 'kitchen' }] }],
				},
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
