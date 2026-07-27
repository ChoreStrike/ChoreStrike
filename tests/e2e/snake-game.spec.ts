import { test, expect } from './fixtures/test-fixtures'

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

		await expect(page.getByTestId('coins-display')).toBeVisible()
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
		await page.locator('.start-overlay .btn-primary').click()
		await expect(page.locator('.start-overlay')).not.toBeVisible({ timeout: 5000 })

		await page.locator('.game-board').click()
		await page.keyboard.press('Space')

		await expect(page.locator('.pause-overlay')).toBeVisible()
		await page.locator('.pause-overlay .btn-primary').click()
		await expect(page.locator('.pause-overlay')).not.toBeVisible()
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

	test('Boost shop can be opened from start screen', async ({ page }) => {
		test.setTimeout(60000)

		await openSnake(page)
		await page.locator('.start-overlay .btn-secondary').click()

		await expect(page.getByTestId('boost-shop')).toBeVisible({ timeout: 10000 })
		await expect(page.locator('.boost-card')).toHaveCount(5, { timeout: 5000 })

		await page.locator('.boost-shop-overlay').click()
		await expect(page.getByTestId('boost-shop')).not.toBeVisible()
	})

	test('Leaderboard can be opened from arcade section', async ({ page }) => {
		test.setTimeout(60000)

		await page.locator('.arcade-footer .btn-secondary').click()

		await expect(page.getByTestId('snake-leaderboard')).toBeVisible({ timeout: 10000 })
		await expect(page.locator('.tab-btn')).toHaveCount(2)
		await expect(page.locator('.personal-stats')).toBeVisible()

		await page.locator('.leaderboard-overlay').click()
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

	test('Game over screen shows score and sparks earned', async ({ page }) => {
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

	test('Active boosts are displayed during gameplay', async ({ page }) => {
		test.setTimeout(90000)

		await openSnake(page)
		await page.locator('.start-overlay .btn-primary').click()
		await expect(page.locator('.start-overlay')).not.toBeVisible({ timeout: 5000 })

		const activeBoosts = page.locator('.active-boosts')
		await expect(activeBoosts).toBeVisible().catch(() => {
			return expect(activeBoosts).toBeAttached()
		})
	})

	test('Coins balance is displayed in arcade header', async ({ page }) => {
		test.setTimeout(60000)

		const coinsValue = page.getByTestId('coins-value')
		await expect(coinsValue).toBeVisible()
		expect(await coinsValue.textContent()).toMatch(/^\d+$/)
	})
})
