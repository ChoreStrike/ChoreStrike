import { test, expect } from '@playwright/test'
import type { Locator, Page } from '@playwright/test'

/**
 * E2E test: Crumb Snake on the arcade game shell
 * Critical flow: Arcade (own optional-area surface) → Game → Leaderboard
 *
 * CS-PLAN-015: auth via storageState (no inline form-login), auto-waiting
 * assertions instead of sleeps.
 *
 * CS-PLAN-070 WP6 (C5f/Q-077-05): the arcade is its own opt-in surface
 * under `/arcade` — no longer a Dashboard section.
 *
 * CS-PLAN-086 WP-A: Snake runs on the shared GameShell. Keys are handled on
 * the shell element only (no window listener), the shell exposes its phase
 * as `data-phase`, and the deterministic opening (first crumb four cells
 * ahead of the hose) scores without input or randomness — no test hook.
 */

/** Open Crumb Snake from its catalog card and wait for the start overlay. */
async function openSnake(page: Page): Promise<Locator> {
	await expect(page.getByTestId('arcade-card-snake')).toBeVisible({ timeout: 20000 })
	await page.getByTestId('arcade-play-snake').click()
	const shell = page.getByTestId('game-shell')
	await expect(shell).toHaveAttribute('data-phase', 'ready', { timeout: 15000 })
	return shell
}

async function startSnake(page: Page): Promise<Locator> {
	const shell = await openSnake(page)
	await page.getByTestId('game-start').click()
	await expect(shell).toHaveAttribute('data-phase', /playing|over/)
	return shell
}

/** Without input the hose eats the opening crumb and then reaches the wall. */
async function playUntilOver(page: Page): Promise<Locator> {
	const shell = await startSnake(page)
	await expect(shell).toHaveAttribute('data-phase', 'over', { timeout: 30000 })
	return shell
}

test.describe('Snake Arcade Game', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/apps/chorestrike/#/arcade')
		await expect(page.getByTestId('arcade-surface')).toBeVisible({ timeout: 30000 })
	})

	test('Arcade surface is reachable and stays scores-only', async ({ page }) => {
		test.setTimeout(60000)

		// WP3/WP6 cutovers (T10/D7d, Q-077-05): the arcade is scores-only —
		// no coin display, no boost shop entry, no Dashboard embedding.
		await expect(page.getByTestId('coins-display')).toHaveCount(0)
		await expect(page.getByTestId('boost-shop')).toHaveCount(0)
		await expect(page.getByTestId('arcade-card-snake')).toContainText('Crumb Snake')
		await expect(page.getByTestId('game-shell')).toHaveCount(0)
	})

	test('User can start Snake game', async ({ page }) => {
		test.setTimeout(90000)

		await startSnake(page)
		await expect(page.getByTestId('game-start')).toHaveCount(0)
		await expect(page.getByTestId('game-score')).toBeVisible()
	})

	test('User can control snake with keyboard', async ({ page }) => {
		test.setTimeout(90000)

		const shell = await startSnake(page)
		// The shell holds focus after Start; arrows steer up and back right
		// with a frame between presses, far from any wall.
		for (const key of ['ArrowUp', 'ArrowRight']) {
			await page.keyboard.press(key)
			await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))))
		}

		await expect(shell).not.toHaveAttribute('data-phase', 'over')
		await expect(shell).toBeFocused()
	})

	test('User can pause and resume game', async ({ page }) => {
		test.setTimeout(90000)

		const shell = await openSnake(page)
		// Start and pause in one renderer task: Space is dispatched on the
		// shell element (the only key listener) right after the Start click.
		await page.evaluate(() => {
			const shellElement = document.querySelector('[data-testid="game-shell"]')
			;(document.querySelector('[data-testid="game-start"]') as HTMLButtonElement).click()
			shellElement?.dispatchEvent(new KeyboardEvent('keydown', { code: 'Space', bubbles: true, cancelable: true }))
		})
		await expect(shell).toHaveAttribute('data-phase', 'paused')
		await expect(page.getByTestId('game-resume')).toBeFocused()

		await page.getByTestId('game-resume').click()
		await expect(shell).toHaveAttribute('data-phase', /playing|over/)
	})

	test('Mobile controls are visible on touch devices', async ({ page }) => {
		test.setTimeout(60000)

		await startSnake(page)

		// The D-pad is attached everywhere and shown on touch/narrow screens
		// only, so the desktop check includes hidden buttons.
		await expect(page.getByTestId('game-dpad').getByRole('button', { includeHidden: true })).toHaveCount(4)
		await expect(page.getByRole('button', { name: 'Up', includeHidden: true })).toBeAttached()
	})

	test('Leaderboard can be opened from arcade section', async ({ page }) => {
		test.setTimeout(60000)

		await page.getByTestId('arcade-leaderboard-snake').click()

		const board = page.getByTestId('game-leaderboard')
		await expect(board).toBeVisible({ timeout: 10000 })
		await expect(board).toHaveAttribute('data-game-id', 'snake')
		await expect(page.getByRole('button', { name: 'All Time' })).toBeVisible()
		await expect(page.getByTestId('game-leaderboard-personal')).toBeVisible()

		await page.getByTestId('modal-close').click()
		await expect(board).not.toBeVisible()
	})

	test('Leaderboard tabs switch between all-time and weekly', async ({ page }) => {
		test.setTimeout(60000)

		await page.getByTestId('arcade-leaderboard-snake').click()
		await expect(page.getByTestId('game-leaderboard')).toBeVisible({ timeout: 10000 })

		await page.getByRole('button', { name: 'This Week' }).click()
		await expect(page.getByRole('button', { name: 'This Week' })).toHaveAttribute('aria-pressed', 'true')

		await page.getByRole('button', { name: 'All Time' }).click()
		await expect(page.getByRole('button', { name: 'All Time' })).toHaveAttribute('aria-pressed', 'true')
	})

	test('Game over screen shows the score (no coin outputs)', async ({ page }) => {
		test.setTimeout(120000)

		const shell = await playUntilOver(page)

		// The deterministic opening always collects the first crumb.
		await expect(page.getByTestId('game-final-score')).toHaveText(/Score: [1-9]\d*/)
		await expect(page.getByTestId('game-restart')).toBeVisible()
		await expect(page.getByTestId('game-open-leaderboard')).toBeVisible()
		// Scores-only contract (WP3): the game-over screen mints no Coins.
		await expect(shell).not.toContainText('Coins')
	})

	test('Finished round is saved to this game\'s leaderboard', async ({ page }) => {
		test.setTimeout(120000)

		const saved = page.waitForResponse((response) => response.url().includes('/api/v1/minigames/snake/scores')
			&& response.request().method() === 'POST')
		await playUntilOver(page)
		const response = await saved
		expect(response.status()).toBe(200)
		const body = response.request().postDataJSON() as { score: number, durationMs: number }
		expect(body.score).toBeGreaterThan(0)
		expect(body.durationMs).toBeGreaterThan(0)
		await expect(page.getByTestId('game-save-failed')).toHaveCount(0)

		await page.getByTestId('game-open-leaderboard').click()
		const board = page.getByTestId('game-leaderboard')
		await expect(board).toHaveAttribute('data-game-id', 'snake')
		await expect(board.getByTestId('game-leaderboard-entry').first()).toBeVisible({ timeout: 10000 })
		await expect(board.getByTestId('game-leaderboard-personal')).not.toContainText('#-')
	})

	test('User can play again after game over', async ({ page }) => {
		test.setTimeout(120000)

		const shell = await playUntilOver(page)

		await page.getByTestId('game-restart').click()
		// A fresh round runs for about two seconds before the wall.
		await expect(shell).toHaveAttribute('data-phase', 'playing')
		await expect(page.getByTestId('game-final-score')).toHaveCount(0)
	})

	test('No boost surfaces remain after the cutover', async ({ page }) => {
		test.setTimeout(60000)

		// WP3 (T10/D7d): boost purchase and the active-boost HUD retired
		// with the wallet cutover — neither may linger in the arcade.
		await startSnake(page)

		await expect(page.locator('.active-boosts')).toHaveCount(0)
	})

	test('Keys outside the game are never captured', async ({ page }) => {
		test.setTimeout(60000)

		// Snake defect S2: the old game prevented arrows and Space page-wide.
		await openSnake(page)
		const prevented = await page.evaluate(() => {
			const event = new KeyboardEvent('keydown', { code: 'ArrowDown', bubbles: true, cancelable: true })
			document.body.dispatchEvent(event)
			return event.defaultPrevented
		})
		expect(prevented).toBe(false)
		await expect(page.getByTestId('game-shell')).toHaveAttribute('data-phase', 'ready')
	})
})
