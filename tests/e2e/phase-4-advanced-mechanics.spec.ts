import { test, expect } from '@playwright/test'

test.describe('Phase 4: Advanced Game Mechanics - Combo System', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('http://localhost:8080')
		await page.waitForLoadState('networkidle')
	})

	test('should display combo indicator', async ({ page }) => {
		const comboIndicator = page.locator('[data-testid="combo-indicator"]')
		await expect(comboIndicator).toBeVisible()
	})

	test('should show combo progress when active', async ({ page }) => {
		const comboIndicator = page.locator('[data-testid="combo-indicator"]')
		
		// Check for progress elements
		const progressBar = comboIndicator.locator('.progress-bar')
		const progressText = comboIndicator.locator('.progress-text')
		
		await expect(progressBar).toBeVisible()
		await expect(progressText).toBeVisible()
	})

	test('should display combo stats', async ({ page }) => {
		const comboIndicator = page.locator('[data-testid="combo-indicator"]')
		
		// Check for combo stats
		const bonusPoints = comboIndicator.locator('.stat:has-text("Bonus Points")')
		const multiplier = comboIndicator.locator('.stat:has-text("Multiplier")')
		
		await expect(bonusPoints).toBeVisible()
		await expect(multiplier).toBeVisible()
	})

	test('should show empty state when no combo', async ({ page }) => {
		const comboIndicator = page.locator('[data-testid="combo-indicator"]')
		const emptyState = comboIndicator.locator('.combo-empty')
		
		// Empty state should be visible when no active combo
		const count = await emptyState.count()
		if (count > 0) {
			await expect(emptyState).toBeVisible()
			await expect(emptyState.locator('.empty-icon')).toBeVisible()
		}
	})

	test('combo progress bar should fill based on task count', async ({ page }) => {
		const comboIndicator = page.locator('[data-testid="combo-indicator"]')
		const progressFill = comboIndicator.locator('.progress-fill')
		
		await expect(progressFill).toBeVisible()
		
		// Check that progress fill has width style
		const width = await progressFill.getAttribute('style')
		expect(width).toContain('width')
	})
})

test.describe('Phase 4: Advanced Game Mechanics - Medals', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('http://localhost:8080')
		await page.waitForLoadState('networkidle')
	})

	test('should display medal display component', async ({ page }) => {
		const medalDisplay = page.locator('[data-testid="medal-display"]')
		await expect(medalDisplay).toBeVisible()
	})

	test('should show medal badges with correct styling', async ({ page }) => {
		const medalDisplay = page.locator('[data-testid="medal-display"]')
		const medalBadges = medalDisplay.locator('[data-testid="medal-badge"]')
		
		const count = await medalBadges.count()
		
		if (count > 0) {
			// Check first medal badge has correct classes
			const firstBadge = medalBadges.first()
			const className = await firstBadge.getAttribute('class')
			
			// Should have rarity class
			expect(className).toMatch(/rarity-(common|rare|epic|legendary)/)
		}
	})

	test('medal badges should show correct information', async ({ page }) => {
		const medalDisplay = page.locator('[data-testid="medal-display"]')
		const medalBadges = medalDisplay.locator('[data-testid="medal-badge"]')
		
		const count = await medalBadges.count()
		
		if (count > 0) {
			const firstBadge = medalBadges.first()
			
			// Should have icon
			await expect(firstBadge.locator('.medal-icon')).toBeVisible()
			
			// Should have name
			await expect(firstBadge.locator('.medal-name')).toBeVisible()
			
			// Should have points
			await expect(firstBadge.locator('.medal-points')).toBeVisible()
		}
	})

	test('should display medals in leaderboard', async ({ page }) => {
		const leaderboard = page.locator('[data-testid="leaderboard"]')
		await expect(leaderboard).toBeVisible()
		
		// Medal display should be visible in leaderboard rows
		const medalDisplay = leaderboard.locator('[data-testid="medal-display"]')
		const count = await medalDisplay.count()
		
		// Either medals are displayed or empty state is shown
		if (count === 0) {
			const noMedals = leaderboard.locator('.no-medals')
			await expect(noMedals).toBeVisible()
		}
	})
})

test.describe('Phase 4: Advanced Game Mechanics - Achievement Notifications', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('http://localhost:8080')
		await page.waitForLoadState('networkidle')
	})

	test('should display achievement notification container', async ({ page }) => {
		const notificationContainer = page.locator('[data-testid="achievement-notification"]')
		await expect(notificationContainer).toBeVisible()
	})

	test('achievement notifications should have correct styling', async ({ page }) => {
		const notificationContainer = page.locator('[data-testid="achievement-notification"]')
		
		// Check notification styling by triggering a notification (if possible)
		// or checking the container is properly positioned
		const style = await notificationContainer.evaluate(el => {
			return {
				position: window.getComputedStyle(el).position,
				zIndex: window.getComputedStyle(el).zIndex,
			}
		})
		
		expect(style.position).toBe('fixed')
		expect(parseInt(style.zIndex)).toBeGreaterThan(100)
	})
})

test.describe('Phase 4: Advanced Game Mechanics - Game Stats', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('http://localhost:8080')
		await page.waitForLoadState('networkidle')
	})

	test('should display game stats component', async ({ page }) => {
		const gameStats = page.locator('[data-testid="game-stats"]')
		await expect(gameStats).toBeVisible()
	})

	test('should show all stat cards', async ({ page }) => {
		const gameStats = page.locator('[data-testid="game-stats"]')
		
		// Check for stat cards
		await expect(gameStats.locator('.stat-card.total-points')).toBeVisible()
		await expect(gameStats.locator('.stat-card.tasks-completed')).toBeVisible()
		await expect(gameStats.locator('.stat-card.current-streak')).toBeVisible()
		await expect(gameStats.locator('.stat-card.combos')).toBeVisible()
		await expect(gameStats.locator('.stat-card.medals')).toBeVisible()
		await expect(gameStats.locator('.stat-card.rank')).toBeVisible()
	})

	test('should display weekly progress section', async ({ page }) => {
		const gameStats = page.locator('[data-testid="game-stats"]')
		
		await expect(gameStats.locator('.weekly-section')).toBeVisible()
		await expect(gameStats.locator('.weekly-section h3')).toHaveText(/This Week|Diese Woche/)
	})

	test('stat cards should show correct values', async ({ page }) => {
		const gameStats = page.locator('[data-testid="game-stats"]')
		const statCards = gameStats.locator('.stat-card')
		
		const count = await statCards.count()
		expect(count).toBeGreaterThan(0)
		
		// Each stat card should have value and label
		for (let i = 0; i < count; i++) {
			const card = statCards.nth(i)
			await expect(card.locator('.stat-value')).toBeVisible()
			await expect(card.locator('.stat-label')).toBeVisible()
		}
	})
})

test.describe('Phase 4: Advanced Game Mechanics - Weekly Challenge', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('http://localhost:8080')
		await page.waitForLoadState('networkidle')
	})

	test('should display weekly challenge component', async ({ page }) => {
		const weeklyChallenge = page.locator('[data-testid="weekly-challenge"]')
		await expect(weeklyChallenge).toBeVisible()
	})

	test('should show challenge information', async ({ page }) => {
		const weeklyChallenge = page.locator('[data-testid="weekly-challenge"]')
		
		await expect(weeklyChallenge.locator('.challenge-title')).toBeVisible()
		await expect(weeklyChallenge.locator('.challenge-description')).toBeVisible()
		await expect(weeklyChallenge.locator('.challenge-reward')).toBeVisible()
	})

	test('should show difficulty badge', async ({ page }) => {
		const weeklyChallenge = page.locator('[data-testid="weekly-challenge"]')
		const difficultyBadge = weeklyChallenge.locator('.challenge-difficulty')
		
		await expect(difficultyBadge).toBeVisible()
		
		// Should have difficulty class
		const className = await difficultyBadge.getAttribute('class')
		expect(className).toMatch(/difficulty-(easy|medium|hard)/)
	})

	test('should show progress bar', async ({ page }) => {
		const weeklyChallenge = page.locator('[data-testid="weekly-challenge"]')
		
		await expect(weeklyChallenge.locator('.progress-bar')).toBeVisible()
		await expect(weeklyChallenge.locator('.progress-fill')).toBeVisible()
	})

	test('challenge actions should be visible', async ({ page }) => {
		const weeklyChallenge = page.locator('[data-testid="weekly-challenge"]')
		
		// Should have either claim button, completed badge, or time remaining
		const hasClaimButton = await weeklyChallenge.locator('.claim-button').count() > 0
		const hasCompletedBadge = await weeklyChallenge.locator('.completed-badge').count() > 0
		const hasTimeRemaining = await weeklyChallenge.locator('.time-remaining').count() > 0
		
		expect(hasClaimButton || hasCompletedBadge || hasTimeRemaining).toBe(true)
	})
})

test.describe('Phase 4: Integration - Combo and Medal System', () => {
	test('completing task should update combo', async ({ page }) => {
		await page.goto('http://localhost:8080')
		await page.waitForLoadState('networkidle')
		
		// Complete a task
		const taskCheckbox = page.locator('.task-checkbox').first()
		await taskCheckbox.click()
		
		// Wait for combo to update
		await page.waitForTimeout(1000)
		
		// Check combo indicator updated
		const comboIndicator = page.locator('[data-testid="combo-indicator"]')
		await expect(comboIndicator.locator('.combo-active')).toBeVisible()
	})

	test('achievements should show notifications', async ({ page }) => {
		await page.goto('http://localhost:8080')
		await page.waitForLoadState('networkidle')
		
		// Check notification container exists
		const notificationContainer = page.locator('[data-testid="achievement-notification"]')
		await expect(notificationContainer).toBeVisible()
	})
})
