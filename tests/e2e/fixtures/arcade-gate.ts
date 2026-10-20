/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * The owner-only arcade unlock gate (CS-PLAN-086 D-086-02) for browser
 * specs. The seeded members have finished too few chores to unlock the
 * games, so a spec that plays a game first switches the gate off through
 * its real endpoint, as an owner would. The test-mode reset restores the
 * default (on), so every reseeded spec starts locked again.
 */

import type { Browser } from '@playwright/test'

import { expect } from '@playwright/test'
import { createUserContext } from './auth-contexts.ts'

/** An owner of the family seed. */
const OWNER = 'mom'

/**
 * Switch the household arcade gate as the seed's owner.
 *
 * @param browser - Playwright browser for the owner's own context.
 * @param enabled - true: games unlock through finished chores; false: every game is open.
 */
export async function setArcadeGate(browser: Browser, enabled: boolean): Promise<void> {
	const { context, page } = await createUserContext(browser, OWNER)
	try {
		const response = await page.request.put('/apps/chorestrike/api/v1/house/arcade-gate', {
			data: { arcadeGateEnabled: enabled },
		})
		expect(response.status(), await response.text()).toBe(200)
	} finally {
		await context.close()
	}
}
