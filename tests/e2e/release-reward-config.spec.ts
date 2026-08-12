/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Release E2E regression — Reward Config (CS-PLAN-024, Phase 4; absorbed
 * from CS-022 Phase 5).
 *
 * Covers the release-test-plan.md §7 "Admin-only config (global)" row. The reward
 * configuration lives in `oc_appconfig` (global, not per-house), surfaced by
 * `reward_config#index` / `reward_config#update`. The update route is gated
 * to server administrators, so this spec verifies:
 *  1. the config is readable and carries the economy knobs
 *     (coin/multiplier/medal-threshold/teamwork-bonus/…),
 *  2. an admin can mutate a value and it persists,
 *  3. a non-admin is denied on update (403).
 *
 * Config is global and survives the family re-seed (it is not a
 * `chorestrike_*` table), so the original value is restored in `finally`.
 *
 * Seed-mutating (mutates `oc_appconfig`): registered in
 * `SEED_DEPENDENT_SPECS` (chromium-serial-db).
 * Requires: Docker test environment with the `family` database profile.
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import type { Page } from '@playwright/test'

interface RewardConfig {
	comboMultiplier?: number
	[key: string]: unknown
}

/** GET the reward config object (returned at the JSON root). */
async function fetchConfig(page: Page): Promise<RewardConfig> {
	const response = await page.request.get('/apps/chorestrike/api/v1/reward-config', {
		headers: { Accept: 'application/json' },
	})
	expect(response.status()).toBe(200)
	return (await response.json()) as RewardConfig
}

/** PUT a partial config update; returns the full config after update. */
async function updateConfig(page: Page, values: Record<string, unknown>): Promise<RewardConfig> {
	const response = await page.request.put('/apps/chorestrike/api/v1/reward-config', {
		data: { values },
		headers: { Accept: 'application/json' },
	})
	expect(response.status()).toBe(200)
	return (await response.json()) as RewardConfig
}

test.describe('Release: Reward Config', () => {
	test.use({ databaseProfile: 'family' })

	test('admin can read and edit reward config; non-admin is denied', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const admin = await createUserContext(browser, 'admin')
		const teen = await createUserContext(browser, 'teen')
		let originalCombo: number | undefined
		try {
			// 1) The config is readable and exposes the documented economy knobs.
			const config = await fetchConfig(admin.page)
			expect(config).toHaveProperty('comboMultiplier')
			expect(config).toHaveProperty('weeklyGoalTargetPercent')
			expect(config).toHaveProperty('tierGoldMultiplier')
			expect(config).toHaveProperty('teamworkBonusMaxPct')
			originalCombo = config.comboMultiplier

			// 2) An admin can mutate a value and it persists across a re-read.
			const afterUpdate = await updateConfig(admin.page, { comboMultiplier: 3.5 })
			expect(afterUpdate.comboMultiplier).toBe(3.5)

			const reread = await fetchConfig(admin.page)
			expect(reread.comboMultiplier).toBe(3.5)

			// 3) A non-admin cannot update the config.
			const denied = await teen.page.request.put('/apps/chorestrike/api/v1/reward-config', {
				data: { values: { comboMultiplier: 1 } },
				headers: { Accept: 'application/json' },
			})
			expect(denied.status()).toBe(403)
		} finally {
			// Restore the global config so this mutation never leaks into other
			// specs or subsequent runs (oc_appconfig is not reset by re-seed).
			if (originalCombo !== undefined) {
				await updateConfig(admin.page, { comboMultiplier: originalCombo }).catch(() => undefined)
			}
			await admin.context.close()
			await teen.context.close()
		}
	})
})
