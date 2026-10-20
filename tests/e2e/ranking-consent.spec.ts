import type { DatabaseProfile } from './fixtures/database-fixtures'

import { createUserContext } from './fixtures/auth-contexts'
import { expect, test } from './fixtures/database-fixtures'

/**
 * E2E test: the consent lifecycle against an ENABLED ranking flag
 * (CS-PLAN-070 WP4, C5i/C5l/D10b) — serial family pool, owner-driven.
 *
 * Flow: the owner enables the house flag through the settings API, the
 * member sees the opt-in hint instead of a ranking, joins explicitly
 * (always starting NEXT Monday — pending, never ranked before the
 * effective Monday), and withdraws (immediate removal). The on-time
 * entries themselves are pinned server-side (RankingConsentTest) and in
 * the unit suite; this spec covers the UI contract.
 */

test.describe('Ranking Consent Lifecycle (WP4)', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })
	test.setTimeout(120000)

	test('owner enables ranking; member joins pending and withdraws', async ({ browser, seededData: _seededData }) => {
		// The owner flips the flag through the same settings endpoint the
		// Settings view uses.
		const owner = await createUserContext(browser, 'mom')
		try {
			const enable = await owner.page.request.put('/apps/chorestrike/api/v1/house', {
				data: { name: 'Test Family Home', rankingEnabled: true },
			})
			expect(enable.status()).toBe(200)
			expect((await enable.json()).data.rankingEnabled).toBe(true)

			// The member opts in through the UI.
			const member = await createUserContext(browser, 'dad')
			try {
				await member.page.goto('/apps/chorestrike/#/leaderboard')
				await expect(member.page.getByTestId('leaderboard-view')).toBeVisible({ timeout: 30000 })

				// Unconsented: opt-in hint, no ranked entries.
				await expect(member.page.getByTestId('ranking-optin-hint')).toBeVisible({ timeout: 15000 })
				await expect(member.page.getByTestId('empty-leaderboard')).toBeVisible()

				// Join: explicit consent, effective next Monday (pending).
				await member.page.getByTestId('ranking-consent-button').click()
				await expect(member.page.getByTestId('ranking-pending-state')).toBeVisible({ timeout: 15000 })
				await expect(member.page.locator('[data-testid="leaderboard-entry"]')).toHaveCount(0)

				// Withdraw: immediate removal.
				await member.page.getByTestId('ranking-withdraw-button').click()
				await expect(member.page.getByTestId('ranking-optin-hint')).toBeVisible({ timeout: 15000 })
			} finally {
				await member.context.close()
			}
		} finally {
			// Leave the flag off for the other pools (the gate reuses the DB).
			const disable = await owner.page.request.put('/apps/chorestrike/api/v1/house', {
				data: { name: 'Test Family Home', rankingEnabled: false },
			})
			expect(disable.status()).toBe(200)
			await owner.context.close()
		}
	})
})
