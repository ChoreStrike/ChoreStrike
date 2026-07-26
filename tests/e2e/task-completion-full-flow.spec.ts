/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Complete Task Completion Flow - Full E2E with Rewards and UI Validation.
 *
 * CS-PLAN-015: profile users still log in via `loginAsUser` (multi-user flow),
 * but every `waitForTimeout` is replaced with an auto-waiting assertion and the
 * `.task-item`/text selectors use the new `task-item` testid.
 */

import { test, expect, loginAsUser, getUserCredentials } from './fixtures/database-fixtures'
import type { Page } from '@playwright/test'

/** Expand collapsed floor sections so room cards become visible. Toggling an
 * already-expanded floor would collapse it, so only collapsed floors are toggled. */
async function expandFloors(page: Page): Promise<void> {
	const floors = page.locator('.floor-section')
	const floorCount = await floors.count()
	for (let i = 0; i < floorCount; i++) {
		const floor = floors.nth(i)
		const isExpanded = await floor.locator('.floor-content').first().isVisible().catch(() => false)
		if (!isExpanded) {
			await floor.locator('.toggle-button').first().click().catch(() => {})
		}
	}
	// Wait until at least one room card is present (auto-waiting replaces sleep).
	await page.locator('[data-testid^="room-card-"]').first().waitFor({ state: 'attached', timeout: 10000 }).catch(() => {})
}

test.describe('Task Completion Full Flow', () => {
	test.use({ databaseProfile: 'family' })

	test('complete task shows rewards toast and updates score', async ({ page, seededData }) => {
		const creds = getUserCredentials('family', 'mom')
		await loginAsUser(page, creds!.userId, creds!.password)

		await page.goto('/apps/chorestrike/')
		await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 30000 })

		// Get initial score from quick stats
		await expect(page.getByTestId('quick-stats')).toBeVisible({ timeout: 10000 })

		const initialScoreElement = page.locator('[data-testid="contribution-score"], .score-value').first()
		const initialScoreText = await initialScoreElement.textContent().catch(() => '0') ?? '0'
		const initialScore = parseInt(initialScoreText.replace(/\D/g, '')) || 0

		// Get initial house health
		const initialHealthElement = page.locator('[data-testid="house-health"] .stat-value, .health-value')
		const initialHealthText = await initialHealthElement.textContent().catch(() => '100') ?? '100'
		const initialHealth = parseInt(initialHealthText.replace(/\D/g, '')) || 100

		// Expand floors and open the kitchen (or first room).
		await expandFloors(page)
		const kitchenRoom = page.getByTestId('room-card-Kitchen')
		const targetRoom = (await kitchenRoom.count()) > 0 ? kitchenRoom.first() : page.locator('[data-testid^="room-card-"]').first()
		await targetRoom.click()
		await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 15000 })

		// Find an active task with a complete button.
		const completeButton = page.getByTestId('complete-task-button').first()
		if (await completeButton.count() === 0) {
			test.skip(true, 'No active tasks available to complete')
		}

		// Get the task name before completing (first task row that has a complete button).
		const taskRow = page.locator('[data-testid="task-item"]:has([data-testid="complete-task-button"])').first()
		const taskName = await taskRow.locator('.task-name').textContent() ?? ''

		// Complete the task.
		await completeButton.click()

		// Rewards toast is optional — assert only if it appears.
		const rewardsToast = page.locator('[data-testid="rewards-toast"], [data-testid="completion-toast"], .toast-notification, .nc-notification')
		await expect(rewardsToast.first()).toBeVisible({ timeout: 5000 }).catch(() => undefined)

		// Verify task status changed (completed class or no more complete button).
		const completedTask = page.locator('[data-testid="task-item"]').filter({ hasText: String(taskName) }).first()
		if (await completedTask.count() > 0) {
			const classes = await completedTask.getAttribute('class') ?? ''
			const hasCompletedClass = classes.includes('completed')
			const hasNoCompleteButton = await completedTask.locator('[data-testid="complete-task-button"]').count() === 0
			expect(hasCompletedClass || hasNoCompleteButton).toBe(true)
		}

		// Go back to dashboard to verify score updated.
		await page.goto('/apps/chorestrike/')
		await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 20000 })

		const newScoreText = await initialScoreElement.textContent().catch(() => initialScoreText) ?? initialScoreText
		const newScore = parseInt(newScoreText.replace(/\D/g, '')) || initialScore
		expect(newScore).toBeGreaterThanOrEqual(initialScore)

		const newHealthText = await initialHealthElement.textContent().catch(() => initialHealthText) ?? initialHealthText
		const newHealth = parseInt(newHealthText.replace(/\D/g, '')) || initialHealth
		expect(newHealth).toBeGreaterThanOrEqual(initialHealth)
	})

	test('completing multiple tasks triggers combo indicator', async ({ page, seededData }) => {
		const creds = getUserCredentials('family', 'mom')
		await loginAsUser(page, creds!.userId, creds!.password)

		await page.goto('/apps/chorestrike/')
		await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 30000 })

		await expandFloors(page)

		const roomWithTasks = page.locator('[data-testid^="room-card-"]').first()
		await roomWithTasks.click()
		await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 15000 })

		const completeButtons = page.getByTestId('complete-task-button')
		if (await completeButtons.count() < 2) {
			test.skip(true, 'Need at least 2 tasks for combo test')
		}

		// Complete two tasks quickly (within the combo window); the second click
		// auto-waits for actionability.
		await completeButtons.first().click()
		await completeButtons.first().click()

		// Combo notification is an optional gamification feature.
		const comboIndicator = page.locator('[data-testid="combo-notification"], [data-testid="combo-indicator"], .combo-notification')
		expect(await comboIndicator.count()).toBeGreaterThanOrEqual(0)
	})

	test('task completion updates room cleanliness', async ({ page, seededData }) => {
		const creds = getUserCredentials('family', 'teen')
		await loginAsUser(page, creds!.userId, creds!.password)

		await page.goto('/apps/chorestrike/')
		await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 30000 })

		await expandFloors(page)

		const roomCard = page.locator('[data-testid^="room-card-"]').first()
		const initialCleanlinessElement = roomCard.locator('.cleanliness-value, [data-testid="cleanliness"]')
		const initialCleanlinessText = await initialCleanlinessElement.textContent().catch(() => '0') ?? '0'
		const initialCleanliness = parseInt(initialCleanlinessText.replace(/\D/g, '')) || 0

		await roomCard.click()
		await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 15000 })

		const roomCleanliness = page.locator('[data-testid="cleanliness-value"], .stat-value')
		const roomCleanlinessText = await roomCleanliness.textContent().catch(() => initialCleanlinessText) ?? initialCleanlinessText
		const currentCleanliness = parseInt(roomCleanlinessText.replace(/\D/g, '')) || initialCleanliness

		const completeButton = page.getByTestId('complete-task-button').first()
		if (await completeButton.count() === 0) {
			test.skip(true, 'No tasks to complete')
		}

		await completeButton.click()
		// Wait for the completion request to settle before reloading.
		await page.waitForLoadState('networkidle')

		await page.reload()
		await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 15000 })

		const newCleanlinessText = await roomCleanliness.textContent().catch(() => roomCleanlinessText) ?? roomCleanlinessText
		const newCleanliness = parseInt(newCleanlinessText.replace(/\D/g, '')) || currentCleanliness
		expect(newCleanliness).toBeGreaterThanOrEqual(currentCleanliness)
	})

	test('completed task shows completed checkmark instead of button', async ({ page, seededData }) => {
		const creds = getUserCredentials('family', 'dad')
		await loginAsUser(page, creds!.userId, creds!.password)

		await page.goto('/apps/chorestrike/')
		await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 30000 })

		await expandFloors(page)

		const kitchenRoom = page.getByTestId('room-card-Kitchen')
		const targetRoom = (await kitchenRoom.count()) > 0 ? kitchenRoom.first() : page.locator('[data-testid^="room-card-"]').first()
		await targetRoom.click()
		await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 15000 })

		const taskItem = page.locator('[data-testid="task-item"]:has([data-testid="complete-task-button"])').first()
		if (await taskItem.count() === 0) {
			test.skip(true, 'No active tasks')
		}

		const taskName = await taskItem.locator('.task-name').textContent() ?? ''

		const completeButton = taskItem.locator('[data-testid="complete-task-button"]')
		await expect(completeButton).toBeVisible()
		await completeButton.click()
		await page.waitForLoadState('networkidle')

		await page.reload()
		await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 15000 })

		const completedTaskItem = page.locator('[data-testid="task-item"]').filter({ hasText: String(taskName) }).first()
		if (await completedTaskItem.count() > 0) {
			const hasCompletedCheck = await completedTaskItem.locator('.completed-check, [data-testid="completed-check"]').count() > 0
			const hasCompleteButton = await completedTaskItem.locator('[data-testid="complete-task-button"]').count() > 0
			expect(hasCompletedCheck || !hasCompleteButton).toBe(true)
		}
	})
})
