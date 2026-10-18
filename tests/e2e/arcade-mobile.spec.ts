/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * E2E: arcade touch controls on the phone projects (CS-PLAN-086 §4): the DOM
 * games played by tapping (Spot Sprint, Sock Match), the canvas games steered
 * with the on-screen pad (Crumb Snake, Dust Runner) and one whose squeegee
 * follows a finger on the board (Tile Breaker). Runs in `mobile-chromium`
 * (Pixel 7) and `mobile-webkit` (iPhone 14), which own the database
 * serially.
 *
 * Requires: Docker test environment with the `family` database profile.
 */

import { test, expect } from './fixtures/database-fixtures'
import type { DatabaseProfile } from './fixtures/database-fixtures'
import type { Page } from '@playwright/test'
import { setArcadeGate } from './fixtures/arcade-gate'
import { expectDialogAboveNavigationToggle } from './fixtures/dialog-layer'

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
		await page.goto('/apps/chorestrike/#/arcade')
		await expect(page.getByTestId('arcade-card-spots')).toBeVisible({ timeout: 30000 })
		// R086E-02: every card's best and rank fit a phone-width card.
		const overflowing = await page.locator('[data-testid^="arcade-card-"] dl').evaluateAll((lists) => lists
			.filter((list) => list.scrollWidth > list.clientWidth).length)
		expect(overflowing).toBe(0)
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

	test('Tile Breaker steers by tapping the board', async ({ page, browser, seededData }) => {
		expect(seededData).not.toBeNull()
		// The seeded member has not unlocked Tile Breaker; the owner opens it.
		await setArcadeGate(browser, false)
		await openGame(page, 'tiles')

		await expect(page.getByTestId('game-dpad')).toHaveCount(0)
		const score = page.getByTestId('game-score')
		const lives = page.getByTestId('game-lives')
		await expect(lives).toHaveText('3')
		// The bubble cleans its first tile by itself; a tap at the board's left
		// edge pulls the squeegee under its way back down. It is back at the
		// squeegee about 1.25 s after the hit, so the hit is watched per frame
		// and the tap goes straight to the touchscreen: the expect polling (up
		// to 1 s) plus the locator tap's actionability round-trips (0.7 s on
		// the WebKit emulation) missed that window (R086G-02).
		const canvas = page.getByTestId('game-shell').locator('canvas')
		const box = await canvas.boundingBox()
		if (!box) throw new Error('the Tile Breaker canvas has no bounding box')
		await page.waitForFunction(
			() => document.querySelector('[data-testid="game-score"]')?.textContent?.trim() === '10',
			null,
			{ polling: 'raf', timeout: 10000 },
		)
		await page.touchscreen.tap(box.x + 4, box.y + box.height / 2)

		await expect(score).toHaveText(/^[2-9]0$/, { timeout: 10000 })
		await expect(lives).toHaveText('3')
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

	test('Dust Runner turns with the on-screen pad', async ({ page, browser, seededData }) => {
		expect(seededData).not.toBeNull()
		// The seeded member has not unlocked Dust Runner; the owner opens it.
		await setArcadeGate(browser, false)
		await openGame(page, 'dust')
		// UX-019: at phone width the open game dialog covers Nextcloud's navigation toggle.
		await expectDialogAboveNavigationToggle(page, page.getByTestId('modal'))

		const pad = page.getByTestId('game-dpad')
		await expect(pad).toBeVisible()
		const score = page.getByTestId('game-score')
		// The robot clears its start row by itself and stops at the wall; the
		// pad turns it down along the next row of dust.
		await expect(score).toHaveText('12', { timeout: 10000 })
		await pad.getByRole('button', { name: 'Down' }).tap()

		await expect(score).toHaveText(/^(1[3-9]|2\d)$/, { timeout: 5000 })
		await expect(page.getByTestId('game-lives')).toHaveText('3')
		await expect(page.getByTestId('game-shell')).toHaveAttribute('data-phase', 'playing')
	})
})
