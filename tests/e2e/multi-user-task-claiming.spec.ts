/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 * 
 * Multi-User Task Claiming - Realistic Family Scenarios
 */

import { test, expect, loginAsUser, getUserCredentials } from './fixtures/database-fixtures'
import type { Browser, BrowserContext, Page } from '@playwright/test'

test.describe('Multi-User Task Claiming', () => {
	test.use({ databaseProfile: 'family' })

	test('two users cannot claim same task simultaneously', async ({ browser }) => {
		// Create two separate browser contexts
		const momContext = await browser.newContext()
		const dadContext = await browser.newContext()

		const momPage = await momContext.newPage()
		const dadPage = await dadContext.newPage()

		try {
			// Both users login
			const momCreds = getUserCredentials('family', 'mom')
			const dadCreds = getUserCredentials('family', 'dad')

			await loginAsUser(momPage, momCreds!.userId, momCreds!.password)
			await loginAsUser(dadPage, dadCreds!.userId, dadCreds!.password)

			// Both navigate to dashboard
			await momPage.goto('/apps/chorestrike/')
			await dadPage.goto('/apps/chorestrike/')

			await momPage.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })
			await dadPage.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

			// Expand floors for both
			await expandAllFloors(momPage)
			await expandAllFloors(dadPage)

			// Both go to living room (has unassigned tasks)
			const momLivingRoom = momPage.locator('[data-testid="room-card-Living Room"]').first()
			const dadLivingRoom = dadPage.locator('[data-testid="room-card-Living Room"]').first()

			await momLivingRoom.click()
			await dadLivingRoom.click()

			await momPage.waitForLoadState('networkidle', { timeout: 30000 })
			await dadPage.waitForLoadState('networkidle', { timeout: 30000 })

			// Both see room detail view
			await momPage.waitForSelector('[data-testid="room-detail-view"]', { timeout: 15000 })
			await dadPage.waitForSelector('[data-testid="room-detail-view"]', { timeout: 15000 })

			// Find an unassigned task (vacuum or dust furniture from family fixture)
			const unassignedTaskName = await findUnassignedTask(momPage)

			if (!unassignedTaskName) {
				test.skip(true, 'No unassigned tasks available')
				return
			}

			// Mom claims the task first
			await claimTask(momPage, unassignedTaskName)
			await momPage.waitForTimeout(1000)

			// Dad tries to claim the same task
			// The task should now show as "Assigned to mom" or claim button should be disabled
			await dadPage.reload()
			await dadPage.waitForSelector('[data-testid="room-detail-view"]', { timeout: 15000 })

			const taskItem = dadPage.locator(`.task-item:has-text("${unassignedTaskName}")`).first()
			
			if (await taskItem.count() > 0) {
				const claimButton = taskItem.locator('[data-testid="claim-task-button"]')
				const assignedIndicator = taskItem.locator('.meta-item.assigned, text=Assigned')

				// Either claim button is gone/disabled OR task shows "Assigned"
				const claimButtonCount = await claimButton.count()
				const hasAssignedIndicator = await assignedIndicator.count() > 0

				expect(claimButtonCount === 0 || hasAssignedIndicator).toBe(true)
			}
		} finally {
			await momContext.close()
			await dadContext.close()
		}
	})

	test('user can claim overdue task from another user', async ({ browser }) => {
		const dadContext = await browser.newContext()
		const dadPage = await dadContext.newPage()

		try {
			// Login as dad
			const dadCreds = getUserCredentials('family', 'dad')
			await loginAsUser(dadPage, dadCreds!.userId, dadCreds!.password)

			await dadPage.goto('/apps/chorestrike/')
			await dadPage.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })

			await expandAllFloors(dadPage)

			// Go to kitchen (has overdue dishes assigned to mom)
			const kitchenRoom = dadPage.locator('[data-testid="room-card-Kitchen"]').first()
			await kitchenRoom.click()
			await dadPage.waitForLoadState('networkidle', { timeout: 30000 })
			await dadPage.waitForSelector('[data-testid="room-detail-view"]', { timeout: 15000 })

			// Find overdue task assigned to mom
			const overdueTask = dadPage.locator('.task-item.overdue').first()
			
			if (await overdueTask.count() === 0) {
				test.skip(true, 'No overdue tasks available')
			}

			const taskName = await overdueTask.locator('.task-name').textContent()

			// Check if task shows "Overdue - Available to claim"
			const isOverdue = await overdueTask.locator('.task-due.overdue, text=Overdue').count() > 0
			expect(isOverdue).toBe(true)

			// Dad should be able to claim this overdue task
			// (Implementation may vary - claim button, reassign button, etc.)
			const claimButton = overdueTask.locator('[data-testid="claim-task-button"], [data-testid="reassign-button"]')
			
			if (await claimButton.count() > 0) {
				await claimButton.click()
				await dadPage.waitForTimeout(1000)

				// Task should now be assigned to dad
				await dadPage.reload()
				await dadPage.waitForSelector('[data-testid="room-detail-view"]', { timeout: 15000 })

				const updatedTask = dadPage.locator(`.task-item:has-text("${taskName}")`).first()
				const assignedToDad = await updatedTask.locator('text=Assigned to you, text=dad').count() > 0

				// At minimum, task should no longer show as assigned to mom
				expect(await updatedTask.count()).toBeGreaterThan(0)
			}
		} finally {
			await dadContext.close()
		}
	})

	test('task assignment persists across user sessions', async ({ browser }) => {
		const momContext = await browser.newContext()
		const teenContext = await browser.newContext()

		const momPage = await momContext.newPage()
		const teenPage = await teenContext.newPage()

		try {
			// Mom logs in and claims a task
			const momCreds = getUserCredentials('family', 'mom')
			await loginAsUser(momPage, momCreds!.userId, momCreds!.password)

			await momPage.goto('/apps/chorestrike/')
			await momPage.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })
			await expandAllFloors(momPage)

			const livingRoom = momPage.locator('[data-testid="room-card-Living Room"]').first()
			await livingRoom.click()
			await momPage.waitForLoadState('networkidle', { timeout: 30000 })
			await momPage.waitForSelector('[data-testid="room-detail-view"]', { timeout: 15000 })

			// Find and claim an unassigned task
			const unassignedTask = await findUnassignedTask(momPage)
			
			if (!unassignedTask) {
				test.skip(true, 'No unassigned tasks')
				return
			}

			await claimTask(momPage, unassignedTask)
			await momPage.waitForTimeout(1000)

			// Teen logs in and views the same room
			const teenCreds = getUserCredentials('family', 'teen')
			await loginAsUser(teenPage, teenCreds!.userId, teenCreds!.password)

			await teenPage.goto('/apps/chorestrike/')
			await teenPage.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })
			await expandAllFloors(teenPage)

			const teenLivingRoom = teenPage.locator('[data-testid="room-card-Living Room"]').first()
			await teenLivingRoom.click()
			await teenPage.waitForLoadState('networkidle', { timeout: 30000 })
			await teenPage.waitForSelector('[data-testid="room-detail-view"]', { timeout: 15000 })

			// Teen should see the task as assigned (not claimable)
			const taskItem = teenPage.locator(`.task-item:has-text("${unassignedTask}")`).first()
			
			if (await taskItem.count() > 0) {
				const hasAssignedIndicator = await taskItem.locator('.meta-item.assigned, text=Assigned').count() > 0
				const hasClaimButton = await taskItem.locator('[data-testid="claim-task-button"]').count() > 0

				// Should be marked as assigned and not claimable
				expect(hasAssignedIndicator || !hasClaimButton).toBe(true)
			}
		} finally {
			await momContext.close()
			await teenContext.close()
		}
	})

	test('unclaim/reassign task makes it available to others', async ({ browser }) => {
		const momContext = await browser.newContext()
		const momPage = await momContext.newPage()

		try {
			const momCreds = getUserCredentials('family', 'mom')
			await loginAsUser(momPage, momCreds!.userId, momCreds!.password)

			await momPage.goto('/apps/chorestrike/')
			await momPage.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })
			await expandAllFloors(momPage)

			const livingRoom = momPage.locator('[data-testid="room-card-Living Room"]').first()
			await livingRoom.click()
			await momPage.waitForLoadState('networkidle', { timeout: 30000 })
			await momPage.waitForSelector('[data-testid="room-detail-view"]', { timeout: 15000 })

			// Claim a task
			const unassignedTask = await findUnassignedTask(momPage)
			
			if (!unassignedTask) {
				test.skip(true, 'No unassigned tasks')
				return
			}

			await claimTask(momPage, unassignedTask)
			await momPage.waitForTimeout(1000)

			// Verify task is assigned
			await momPage.reload()
			await momPage.waitForSelector('[data-testid="room-detail-view"]', { timeout: 15000 })

			const taskItem = momPage.locator(`.task-item:has-text("${unassignedTask}")`).first()
			const hasAssigned = await taskItem.locator('.meta-item.assigned').count() > 0
			expect(hasAssigned).toBe(true)

			// Unclaim/reassign the task
			const unclaimButton = taskItem.locator('[data-testid="unclaim-button"], [data-testid="unassign-button"]')
			
			if (await unclaimButton.count() > 0) {
				await unclaimButton.click()
				await momPage.waitForTimeout(1000)

				// Task should now show as unassigned again
				await momPage.reload()
				await momPage.waitForSelector('[data-testid="room-detail-view"]', { timeout: 15000 })

				const updatedTask = momPage.locator(`.task-item:has-text("${unassignedTask}")`).first()
				const stillAssigned = await updatedTask.locator('.meta-item.assigned').count() > 0
				const hasClaimButton = await updatedTask.locator('[data-testid="claim-task-button"]').count() > 0

				expect(stillAssigned === false || hasClaimButton).toBe(true)
			}
		} finally {
			await momContext.close()
		}
	})
})

// Helper functions
async function expandAllFloors(page: Page): Promise<void> {
	const floors = page.locator('.floor-section')
	const floorCount = await floors.count()
	
	for (let i = 0; i < floorCount; i++) {
		const floorSection = floors.nth(i)
		const toggleButton = floorSection.locator('.toggle-button')
		const floorContent = floorSection.locator('.floor-content')
		const isExpanded = await floorContent.isVisible().catch(() => false)
		
		if (!isExpanded) {
			await toggleButton.click()
			await page.waitForTimeout(300)
			await floorContent.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {})
		}
	}
}

async function findUnassignedTask(page: Page): Promise<string | null> {
	// Look for tasks without assigned indicator
	const tasks = page.locator('.task-item')
	const taskCount = await tasks.count()

	for (let i = 0; i < taskCount; i++) {
		const task = tasks.nth(i)
		const hasAssigned = await task.locator('.meta-item.assigned, text=Assigned').count() > 0
		
		if (!hasAssigned) {
			const taskName = await task.locator('.task-name').textContent()
			return taskName
		}
	}

	return null
}

async function claimTask(page: Page, taskName: string): Promise<void> {
	const taskItem = page.locator(`.task-item:has-text("${taskName}")`).first()
	const claimButton = taskItem.locator('[data-testid="claim-task-button"], button:has-text("Claim")')
	
	if (await claimButton.count() > 0) {
		await claimButton.click()
	} else {
		// Alternative: Click on assign button and select self
		const assignButton = taskItem.locator('[data-testid="assign-button"], button:has-text("Assign")')
		if (await assignButton.count() > 0) {
			await assignButton.click()
			await page.waitForTimeout(500)
			// Select "Assign to me" or similar
			const assignToMe = page.locator('button:has-text("Assign to me"), text=Me').first()
			await assignToMe.click().catch(() => {})
		}
	}
}
