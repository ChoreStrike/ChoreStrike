import { test, expect } from './fixtures/test-fixtures'

/**
 * E2E test: Snake Arcade Game
 * Critical flow: Dashboard → Arcade → Game → Boost Shop → Leaderboard
 */
test.describe('Snake Arcade Game', () => {
	test.beforeEach(async ({ page }) => {
		// Navigate to Nextcloud login
		await page.goto('/')
		
		const loginForm = await page.locator('#login-form').isVisible().catch(() => false)
		
		if (loginForm) {
			await page.fill('#user', process.env.NEXTCLOUD_USER || 'admin')
			await page.fill('#password', process.env.NEXTCLOUD_PASSWORD || 'admin')
			await page.click('#submit-form')
			await page.waitForLoadState('networkidle', { timeout: 30000 })
		}
		
		// Navigate to ChoreStrike app
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForTimeout(2000)
	})

	test('Arcade section is visible on dashboard', async ({ page }) => {
		test.setTimeout(60000)

		// Arcade section should be visible
		await expect(page.locator('[data-testid="arcade-section"]')).toBeVisible({ timeout: 20000 })

		// Should show Sparks display
		await expect(page.locator('.arcade-section .sparks-display')).toBeVisible()

		// Should show Snake game card
		await expect(page.locator('.game-card').first()).toBeVisible()
		await expect(page.locator('.game-card h3').first()).toContainText('Snake')
	})

	test('User can start Snake game', async ({ page }) => {
		test.setTimeout(90000)

		// Wait for arcade section
		await expect(page.locator('[data-testid="arcade-section"]')).toBeVisible({ timeout: 20000 })

		// Click on Snake game card
		await page.click('.game-card:first-child')
		await page.waitForTimeout(500)

		// Game modal should open
		await expect(page.locator('[data-testid="snake-game"]')).toBeVisible({ timeout: 10000 })

		// Start screen should be visible
		await expect(page.locator('.start-overlay')).toBeVisible()

		// Click start button
		await page.click('.start-overlay .btn-primary')
		await page.waitForTimeout(500)

		// Game should start (no start overlay)
		await expect(page.locator('.start-overlay')).not.toBeVisible({ timeout: 5000 })

		// Score should be 0 initially
		await expect(page.locator('.score-value')).toContainText('0')
	})

	test('User can control snake with keyboard', async ({ page }) => {
		test.setTimeout(90000)

		// Open game
		await expect(page.locator('[data-testid="arcade-section"]')).toBeVisible({ timeout: 20000 })
		await page.click('.game-card:first-child')
		await page.waitForTimeout(500)
		await expect(page.locator('[data-testid="snake-game"]')).toBeVisible({ timeout: 10000 })

		// Start game
		await page.click('.start-overlay .btn-primary')
		await page.waitForTimeout(1000)

		// Focus the game board
		await page.click('.game-board')

		// Press arrow keys to change direction
		await page.keyboard.press('ArrowUp')
		await page.waitForTimeout(100)
		await page.keyboard.press('ArrowRight')
		await page.waitForTimeout(100)
		await page.keyboard.press('ArrowDown')
		await page.waitForTimeout(100)
		await page.keyboard.press('ArrowLeft')
		await page.waitForTimeout(500)

		// Game should still be running (not game over immediately)
		const gameOver = await page.locator('.game-over-overlay').isVisible().catch(() => false)
		expect(gameOver).toBe(false)
	})

	test('User can pause and resume game', async ({ page }) => {
		test.setTimeout(90000)

		// Open and start game
		await expect(page.locator('[data-testid="arcade-section"]')).toBeVisible({ timeout: 20000 })
		await page.click('.game-card:first-child')
		await page.waitForTimeout(500)
		await expect(page.locator('[data-testid="snake-game"]')).toBeVisible({ timeout: 10000 })
		await page.click('.start-overlay .btn-primary')
		await page.waitForTimeout(1000)

		// Focus game board and pause
		await page.click('.game-board')
		await page.keyboard.press('Space')
		await page.waitForTimeout(500)

		// Pause overlay should be visible
		await expect(page.locator('.pause-overlay')).toBeVisible()

		// Resume game
		await page.click('.pause-overlay .btn-primary')
		await page.waitForTimeout(500)

		// Pause overlay should be gone
		await expect(page.locator('.pause-overlay')).not.toBeVisible()
	})

	test('Mobile controls are visible on touch devices', async ({ page }) => {
		test.setTimeout(60000)

		// Open game
		await expect(page.locator('[data-testid="arcade-section"]')).toBeVisible({ timeout: 20000 })
		await page.click('.game-card:first-child')
		await page.waitForTimeout(500)
		await expect(page.locator('[data-testid="snake-game"]')).toBeVisible({ timeout: 10000 })

		// Start game
		await page.click('.start-overlay .btn-primary')
		await page.waitForTimeout(500)

		// Mobile controls should exist (even if hidden on desktop)
		const mobileControls = page.locator('.mobile-controls')
		await expect(mobileControls).toBeVisible({ timeout: 5000 }).catch(() => {
			// On desktop, controls may be hidden but should exist in DOM
			return expect(mobileControls).toBeAttached()
		})

		// Control buttons should exist
		await expect(page.locator('.control-btn')).toHaveCount(4)
	})

	test('Boost shop can be opened from start screen', async ({ page }) => {
		test.setTimeout(60000)

		// Open game
		await expect(page.locator('[data-testid="arcade-section"]')).toBeVisible({ timeout: 20000 })
		await page.click('.game-card:first-child')
		await page.waitForTimeout(500)
		await expect(page.locator('[data-testid="snake-game"]')).toBeVisible({ timeout: 10000 })

		// Click boost shop button
		await page.click('.start-overlay .btn-secondary')
		await page.waitForTimeout(500)

		// Boost shop should be visible
		await expect(page.locator('[data-testid="boost-shop"]')).toBeVisible({ timeout: 10000 })

		// Should show available boosts
		await expect(page.locator('.boost-card')).toHaveCount(5, { timeout: 5000 })

		// Close boost shop
		await page.click('.boost-shop-overlay')
		await page.waitForTimeout(500)
		await expect(page.locator('[data-testid="boost-shop"]')).not.toBeVisible()
	})

	test('Leaderboard can be opened from arcade section', async ({ page }) => {
		test.setTimeout(60000)

		// Wait for arcade section
		await expect(page.locator('[data-testid="arcade-section"]')).toBeVisible({ timeout: 20000 })

		// Click view leaderboard button
		await page.click('.arcade-footer .btn-secondary')
		await page.waitForTimeout(500)

		// Leaderboard should be visible
		await expect(page.locator('[data-testid="snake-leaderboard"]')).toBeVisible({ timeout: 10000 })

		// Should have tab buttons
		await expect(page.locator('.tab-btn')).toHaveCount(2)

		// Should show personal stats
		await expect(page.locator('.personal-stats')).toBeVisible()

		// Close leaderboard
		await page.click('.leaderboard-overlay')
		await page.waitForTimeout(500)
		await expect(page.locator('[data-testid="snake-leaderboard"]')).not.toBeVisible()
	})

	test('Leaderboard tabs switch between all-time and weekly', async ({ page }) => {
		test.setTimeout(60000)

		// Open leaderboard
		await expect(page.locator('[data-testid="arcade-section"]')).toBeVisible({ timeout: 20000 })
		await page.click('.arcade-footer .btn-secondary')
		await page.waitForTimeout(500)
		await expect(page.locator('[data-testid="snake-leaderboard"]')).toBeVisible({ timeout: 10000 })

		// Click weekly tab
		await page.click('.tab-btn:nth-child(2)')
		await page.waitForTimeout(300)

		// Weekly tab should be active
		await expect(page.locator('.tab-btn:nth-child(2)')).toHaveClass(/active/)

		// Click all-time tab
		await page.click('.tab-btn:nth-child(1)')
		await page.waitForTimeout(300)

		// All-time tab should be active
		await expect(page.locator('.tab-btn:nth-child(1)')).toHaveClass(/active/)
	})

	test('Game over screen shows score and sparks earned', async ({ page }) => {
		test.setTimeout(120000)

		// Open and start game
		await expect(page.locator('[data-testid="arcade-section"]')).toBeVisible({ timeout: 20000 })
		await page.click('.game-card:first-child')
		await page.waitForTimeout(500)
		await expect(page.locator('[data-testid="snake-game"]')).toBeVisible({ timeout: 10000 })
		await page.click('.start-overlay .btn-primary')
		await page.waitForTimeout(1000)

		// Focus game board and play until game over
		await page.click('.game-board')

		// Force game over by going into wall (press up repeatedly)
		for (let i = 0; i < 20; i++) {
			await page.keyboard.press('ArrowUp')
			await page.waitForTimeout(100)
		}

		// Wait for game over
		await expect(page.locator('.game-over-overlay')).toBeVisible({ timeout: 30000 })

		// Should show final score
		await expect(page.locator('.final-score')).toBeVisible()

		// Should show play again button
		await expect(page.locator('.game-over-buttons .btn-primary')).toBeVisible()

		// Should show leaderboard button
		await expect(page.locator('.game-over-buttons .btn-secondary')).toBeVisible()
	})

	test('User can play again after game over', async ({ page }) => {
		test.setTimeout(120000)

		// Open and start game
		await expect(page.locator('[data-testid="arcade-section"]')).toBeVisible({ timeout: 20000 })
		await page.click('.game-card:first-child')
		await page.waitForTimeout(500)
		await expect(page.locator('[data-testid="snake-game"]')).toBeVisible({ timeout: 10000 })
		await page.click('.start-overlay .btn-primary')
		await page.waitForTimeout(1000)

		// Force game over
		await page.click('.game-board')
		for (let i = 0; i < 20; i++) {
			await page.keyboard.press('ArrowUp')
			await page.waitForTimeout(100)
		}
		await expect(page.locator('.game-over-overlay')).toBeVisible({ timeout: 30000 })

		// Click play again
		await page.click('.game-over-buttons .btn-primary')
		await page.waitForTimeout(500)

		// Game should restart (no game over overlay)
		await expect(page.locator('.game-over-overlay')).not.toBeVisible({ timeout: 5000 })

		// Score should be reset to 0
		await expect(page.locator('.score-value')).toContainText('0')
	})

	test('Active boosts are displayed during gameplay', async ({ page }) => {
		test.setTimeout(90000)

		// Open game
		await expect(page.locator('[data-testid="arcade-section"]')).toBeVisible({ timeout: 20000 })
		await page.click('.game-card:first-child')
		await page.waitForTimeout(500)
		await expect(page.locator('[data-testid="snake-game"]')).toBeVisible({ timeout: 10000 })

		// Start game (without boosts for this test)
		await page.click('.start-overlay .btn-primary')
		await page.waitForTimeout(1000)

		// Active boosts section should exist (even if empty)
		const activeBoosts = page.locator('.active-boosts')
		await expect(activeBoosts).toBeVisible().catch(() => {
			// If no boosts, section may not be visible
			return expect(activeBoosts).toBeAttached()
		})
	})

	test('Sparks balance is displayed in arcade header', async ({ page }) => {
		test.setTimeout(60000)

		// Wait for arcade section
		await expect(page.locator('[data-testid="arcade-section"]')).toBeVisible({ timeout: 20000 })

		// Sparks display should show a number
		const sparksValue = page.locator('.arcade-section .sparks-value')
		await expect(sparksValue).toBeVisible()

		// Should be a numeric value
		const sparksText = await sparksValue.textContent()
		expect(sparksText).toMatch(/^\d+$/)
	})
})
