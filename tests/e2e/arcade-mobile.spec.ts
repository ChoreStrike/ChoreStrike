/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * E2E: arcade touch controls on the phone projects (CS-PLAN-086 §4): the DOM
 * games played by tapping (Spot Sprint, Sock Match) and one canvas game
 * steered with the on-screen pad (Crumb Snake). Runs in `mobile-chromium`
 * (Pixel 7) and `mobile-webkit` (iPhone 14), which own the database
 * serially.
 *
 * Requires: Docker test environment with the `family` database profile.
 */

import { test, expect } from './fixtures/database-fixtures'
import type { DatabaseProfile } from './fixtures/database-fixtures'
import type { Page } from '@playwright/test'
import { setArcadeGate } from './fixtures/arcade-gate'

/** Let real game time pass: the round runs on animation frames. */
async function playFor(page: Page, ms: number): Promise<void> {
	await page.evaluate((delay) => new Promise((resolve) => { setTimeout(resolve, delay) }), ms)
}

async function openGame(page: Page, gameId: string): Promise<void> {
	await page.goto('/apps/chorestrike/#/arcade')
	await expect(page.getByTestId(`arcade-card-${gameId}`)).toBeVisible({ timeout: 30000 })
	await page.getByTestId(`arcade-play-${gameId}`).tap()
	await expect(page.getByTestId('game-shell')).toHaveAttribute('data-phase', 'ready', { timeout: 15000 })
	await page.getByTestId('game-start').tap()
	await expect(page.getByTestId('game-shell')).toHaveAttribute('data-phase', 'playing')
}

test.describe('Arcade touch controls (CS-PLAN-086)', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })
	test.setTimeout(120000)

	test('Spot Sprint plays by tapping a stain', async ({ page, seededData }) => {
		expect(seededData).not.toBeNull()
		await openGame(page, 'spots')

		// A tap game has no on-screen pad; the opening stain is tapped directly.
		await expect(page.getByTestId('game-dpad')).toHaveCount(0)
		const stain = page.locator('[data-kind="stain"]').first()
		await expect(stain).toBeVisible()
		const box = await stain.boundingBox()
		expect(box?.width ?? 0, 'tiles are comfortable touch targets').toBeGreaterThanOrEqual(44)
		await stain.tap()

		await expect(page.getByTestId('game-score')).toHaveText('10')
		await expect(page.getByTestId('game-shell')).toHaveAttribute('data-phase', 'playing')
	})

	test('Sock Match plays by tapping a pair', async ({ page, browser, seededData }) => {
		expect(seededData).not.toBeNull()
		// The seeded member has not unlocked Sock Match; the owner opens it.
		await setArcadeGate(browser, false)
		await openGame(page, 'socks')

		await expect(page.getByTestId('game-dpad')).toHaveCount(0)
		// The opening pair: the first sock and its right neighbour.
		const first = page.locator('[data-cell="0"]')
		const box = await first.boundingBox()
		expect(box?.width ?? 0, 'socks are comfortable touch targets').toBeGreaterThanOrEqual(44)
		await first.tap()
		await expect(first).toHaveAttribute('data-face', 'up')
		await page.locator('[data-cell="1"]').tap()

		await expect(first).toHaveAttribute('data-face', 'matched')
		await expect(page.getByTestId('game-score')).toHaveText('100')
		await expect(page.getByTestId('game-shell')).toHaveAttribute('data-phase', 'playing')
	})

	test('Crumb Snake steers with the on-screen pad', async ({ page, browser, seededData }) => {
		expect(seededData).not.toBeNull()
		// The seeded member has not unlocked Crumb Snake; the owner opens it.
		await setArcadeGate(browser, false)
		await openGame(page, 'snake')

		const pad = page.getByTestId('game-dpad')
		await expect(pad).toBeVisible()
		// Without input the hose reaches the right wall about two seconds
		// after the start; steering one small loop keeps it running longer.
		for (const direction of ['Up', 'Right', 'Down', 'Left']) {
			await pad.getByRole('button', { name: direction }).tap()
			await playFor(page, 700)
		}

		await expect(page.getByTestId('game-shell')).toHaveAttribute('data-phase', 'playing')
	})
})
