/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Release E2E regression — Boost Shop & Coin Ledger (CS-PLAN-024, Phase 4;
 * absorbed from CS-022 Phase 5).
 *
 * Covers the release-test-plan.md §9 "Boost shop" and "Coin spend balance"
 * rows. Users start with 0 Coins in the `family` seed, so this spec earns
 * Coins deterministically via the minigame score endpoint (1 Coin per 10
 * points) and then exercises the boost purchase path:
 *  - GET /minigames/boosts returns the priced catalogue.
 *  - Purchasing a boost deducts the exact cost and reports the remainder.
 *  - An insufficient-balance purchase is rejected (400).
 *  - The earn/spend ledger stays consistent (earn 10 → spend 10 → 0).
 *
 * Seed-mutating (mutates Coins + minigame scores): registered in
 * `SEED_DEPENDENT_SPECS` (chromium-serial-db).
 * Requires: Docker test environment with the `family` database profile.
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import type { Page } from '@playwright/test'

/** Current Coin balance for the acting user. */
async function fetchCoins(page: Page): Promise<number> {
	const response = await page.request.get('/apps/chorestrike/api/v1/minigames/coins', {
		headers: { Accept: 'application/json' },
	})
	expect(response.status()).toBe(200)
	const body = await response.json()
	return body.data.currentCoins as number
}

test.describe('Release: Boost Shop & Coin Ledger', () => {
	test.use({ databaseProfile: 'family' })

	test('boost catalogue is priced; purchase deducts coins; insufficient is rejected', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			// Baseline balance in the family seed (0 by default).
			const startingCoins = await fetchCoins(page)

			// 1) Boost catalogue is readable and priced.
			const boostsResponse = await page.request.get('/apps/chorestrike/api/v1/minigames/boosts', {
				headers: { Accept: 'application/json' },
			})
			expect(boostsResponse.status()).toBe(200)
			const boosts = (await boostsResponse.json()).data as Record<string, number>
			expect(boosts.slow_motion).toBeGreaterThan(0)

			// 2) Earn exactly enough for one slow_motion boost (1 Coin / 10 pts).
			const cost = boosts.slow_motion
			const scoreResponse = await page.request.post('/apps/chorestrike/api/v1/minigames/snake/scores', {
				data: { score: cost * 10, boostsUsed: [] },
				headers: { Accept: 'application/json' },
			})
			expect(scoreResponse.status()).toBe(200)
			const earned = (await scoreResponse.json()).data.coinsEarned as number
			expect(earned).toBe(cost)

			const balanceAfterEarn = await fetchCoins(page)
			expect(balanceAfterEarn).toBe(startingCoins + cost)

			// 3) Purchase succeeds and deducts the exact cost.
			const purchaseResponse = await page.request.post(
				'/apps/chorestrike/api/v1/minigames/boosts/slow_motion/purchase',
				{ headers: { Accept: 'application/json' } },
			)
			expect(purchaseResponse.status()).toBe(200)
			const purchaseBody = await purchaseResponse.json()
			expect(purchaseBody.data.cost).toBe(cost)
			expect(purchaseBody.data.remainingCoins).toBe(startingCoins)

			// 4) Ledger consistency: earn N, spend N → back to baseline.
			expect(await fetchCoins(page)).toBe(startingCoins)

			// 5) A second purchase with insufficient balance is rejected (400).
			const deniedResponse = await page.request.post(
				'/apps/chorestrike/api/v1/minigames/boosts/shrink/purchase',
				{ headers: { Accept: 'application/json' } },
			)
			expect(deniedResponse.status()).toBe(400)
			const deniedBody = await deniedResponse.json()
			expect(deniedBody.success).toBe(false)
		} finally {
			await context.close()
		}
	})
})
