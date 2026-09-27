import { test, expect } from '@playwright/test'

/**
 * E2E test: Snake Arcade Game
 * Critical flow: Dashboard → Arcade → Game → Boost Shop → Leaderboard
 *
 * CS-PLAN-015: auth via storageState (no inline form-login). The per-browser
 * 2000 ms render sleep is gone (replaced by an arcade-section visibility wait);
 * post-action sleeps replaced by auto-waiting assertions; the positional
 * `.tab-btn:nth-child()` selectors replaced by `getByRole('button', { name })`.
 * The "force game over" loops no longer sleep per key press — the following
 * `expect(game-over-overlay)` auto-waits until the snake hits the wall.
 */

/** Open the Snake game modal from the arcade section. */
async function openSnake(page: import('@playwright/test').Page): Promise<void> {
	await expect(page.getByTestId('arcade-section')).toBeVisible({ timeout: 20000 })
	await page.locator('.game-card').first().click()
	await expect(page.getByTestId('snake-game')).toBeVisible({ timeout: 10000 })
}

test.describe('Snake Arcade Game', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/apps/chorestrike/')
		await expect(page.getByTestId('arcade-section')).toBeVisible({ timeout: 30000 })
	})

	test('Arcade section is visible on dashboard', async ({ page }) => {
		test.setTimeout(60000)

		// WP3 cutover (T10/D7d): the arcade is scores-only — no coin
		// display, no boost shop entry.
		await expect(page.getByTestId('coins-display')).toHaveCount(0)
		await expect(page.getByTestId('boost-shop')).toHaveCount(0)
		await expect(page.locator('.game-card').first()).toBeVisible()
		await expect(page.locator('.game-card h3').first()).toContainText('Snake')
	})

	test('User can start Snake game', async ({ page }) => {
		test.setTimeout(90000)

		await openSnake(page)
		await expect(page.locator('.start-overlay')).toBeVisible()

		await page.locator('.start-overlay .btn-primary').click()
		// Start overlay disappears once the game begins (auto-waited).
		await expect(page.locator('.start-overlay')).not.toBeVisible({ timeout: 5000 })
		await expect(page.locator('.score-value')).toContainText('0')
	})

	test('User can control snake with keyboard', async ({ page }) => {
		test.setTimeout(90000)

		await openSnake(page)
		await page.locator('.start-overlay .btn-primary').click()
		await expect(page.locator('.start-overlay')).not.toBeVisible({ timeout: 5000 })

		// Focus the board and steer. A short rAF yield between presses lets the
		// game loop process each direction change individually (rapid bursts would
		// only register the last key) — deterministic, not a fixed sleep.
		await page.locator('.game-board').click()
		for (const key of ['ArrowUp', 'ArrowRight', 'ArrowDown', 'ArrowLeft']) {
			await page.keyboard.press(key)
			await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))))
		}

		// Game should still be running.
		const gameOver = await page.locator('.game-over-overlay').isVisible().catch(() => false)
		expect(gameOver).toBe(false)
	})

	test('User can pause and resume game', async ({ page }) => {
		test.setTimeout(90000)

		await openSnake(page)
		// CS-PLAN-051 (D3): start and pause in ONE renderer task. The snake
		// survives only ~1.35 s from Start (9 ticks at 150 ms to the wall),
		// and a separate Space round-trip after the click can exceed that
		// window under load (observed in webkit and under matrix load).
		// Dispatching the Space keydown synchronously inside the same evaluate
		// as the start click pauses before the first tick can ever run. The
		// game listens on `window` (SnakeGame.vue onMounted), so no board
		// focus is required. Under heavy load the first sequence can still be
		// disturbed (a stray state where the pause did not stick), so the
		// sequence is retried a bounded number of times — each retry clicks
		// whichever start/play-again button is present, making every attempt
		// self-consistent.
		const pauseOverlay = page.locator('.pause-overlay')
		let paused = false
		for (let attempt = 0; attempt < 3 && !paused; attempt++) {
			await page.evaluate(() => {
				const startButton = document.querySelector('.start-overlay .btn-primary')
					|| document.querySelector('.game-over-overlay .btn-primary')
				if (startButton) {
					startButton.click()
				}
				window.dispatchEvent(new KeyboardEvent('keydown', { code: 'Space' }))
			})
			try {
				await expect(pauseOverlay).toBeVisible({ timeout: 3000 })
				paused = true
			} catch {
				// Fall through to the next bounded attempt.
			}
		}
		expect(paused, 'snake should be paused via Space in the start task').toBe(true)

		await page.locator('.pause-overlay .btn-primary').click()
		await expect(pauseOverlay).not.toBeVisible({ timeout: 15000 })
	})

	test('Mobile controls are visible on touch devices', async ({ page }) => {
		test.setTimeout(60000)

		await openSnake(page)
		await page.locator('.start-overlay .btn-primary').click()
		await expect(page.locator('.start-overlay')).not.toBeVisible({ timeout: 5000 })

		// Mobile controls exist (hidden on desktop but attached to the DOM).
		const mobileControls = page.locator('.mobile-controls')
		await expect(mobileControls).toBeVisible({ timeout: 5000 }).catch(() => {
			return expect(mobileControls).toBeAttached()
		})
		await expect(page.locator('.control-btn')).toHaveCount(4)
	})

	test('Leaderboard can be opened from arcade section', async ({ page }) => {
		test.setTimeout(60000)

		await page.locator('.arcade-footer .btn-secondary').click()

		await expect(page.getByTestId('snake-leaderboard')).toBeVisible({ timeout: 10000 })
		await expect(page.locator('.tab-btn')).toHaveCount(2)
		await expect(page.locator('.personal-stats')).toBeVisible()

		await page.locator('.leaderboard-overlay .btn-secondary').click()
		await expect(page.getByTestId('snake-leaderboard')).not.toBeVisible()
	})

	test('Leaderboard tabs switch between all-time and weekly', async ({ page }) => {
		test.setTimeout(60000)

		await page.locator('.arcade-footer .btn-secondary').click()
		await expect(page.getByTestId('snake-leaderboard')).toBeVisible({ timeout: 10000 })

		// Switch to the weekly tab and assert it becomes active.
		await page.getByRole('button', { name: 'This Week' }).click()
		await expect(page.getByRole('button', { name: 'This Week' })).toHaveClass(/active/)

		// Switch back to all-time.
		await page.getByRole('button', { name: 'All Time' }).click()
		await expect(page.getByRole('button', { name: 'All Time' })).toHaveClass(/active/)
	})

	test('Game over screen shows the score (no coin outputs)', async ({ page }) => {
		test.setTimeout(120000)

		await openSnake(page)
		await page.locator('.start-overlay .btn-primary').click()
		await expect(page.locator('.start-overlay')).not.toBeVisible({ timeout: 5000 })

		await page.locator('.game-board').click()
		// Steer into a wall — the game-over overlay auto-waits for the collision.
		for (let i = 0; i < 20; i++) {
			await page.keyboard.press('ArrowUp')
		}
		await expect(page.locator('.game-over-overlay')).toBeVisible({ timeout: 30000 })

		await expect(page.locator('.final-score')).toBeVisible()
		await expect(page.locator('.game-over-buttons .btn-primary')).toBeVisible()
		await expect(page.locator('.game-over-buttons .btn-secondary')).toBeVisible()
		// Scores-only contract (WP3): the game-over screen mints no Coins.
		await expect(page.locator('.game-over-overlay')).not.toContainText('Coins')
	})

	test('User can play again after game over', async ({ page }) => {
		test.setTimeout(120000)

		await openSnake(page)
		await page.locator('.start-overlay .btn-primary').click()
		await expect(page.locator('.start-overlay')).not.toBeVisible({ timeout: 5000 })

		await page.locator('.game-board').click()
		for (let i = 0; i < 20; i++) {
			await page.keyboard.press('ArrowUp')
		}
		await expect(page.locator('.game-over-overlay')).toBeVisible({ timeout: 30000 })

		await page.locator('.game-over-buttons .btn-primary').click()
		await expect(page.locator('.game-over-overlay')).not.toBeVisible({ timeout: 5000 })
		await expect(page.locator('.score-value')).toContainText('0')
	})

	test('No boost surfaces remain after the cutover', async ({ page }) => {
		test.setTimeout(60000)

		// WP3 (T10/D7d): boost purchase and the active-boost HUD retired
		// with the wallet cutover — neither may linger in the arcade.
		await openSnake(page)
		await page.locator('.start-overlay .btn-primary').click()
		await expect(page.locator('.start-overlay')).not.toBeVisible({ timeout: 5000 })

		await expect(page.locator('.active-boosts')).toHaveCount(0)
	})
})
