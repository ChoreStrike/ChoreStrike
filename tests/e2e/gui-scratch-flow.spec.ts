import { test, expect } from './fixtures/test-fixtures'
import type { Browser, Page } from '@playwright/test'

/**
 * GUI-first integration flow:
 * - Build house from scratch via UI
 * - Create floor and room
 * - Create and assign task
 * - Verify second user can see shared state
 */

async function login(page: Page, user: string, password: string): Promise<void> {
	await page.goto('/')

	const userInput = page.locator('#user, input[name="user"]').first()
	if (await userInput.isVisible().catch(() => false)) {
		await userInput.fill(user)
		await page.fill('#password, input[name="password"]', password)
		await page.click('#submit-form, button[type="submit"], button:has-text("Log in")')
		await page.waitForLoadState('networkidle', { timeout: 45000 })
	}
}

async function openApp(page: Page): Promise<void> {
	await page.goto('/apps/chorestrike/')
	await page.waitForLoadState('networkidle', { timeout: 45000 })
	await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 30000 })
}

async function navigateToSettings(page: Page): Promise<void> {
	await page.click('text=Settings')
	await page.waitForSelector('[data-testid="settings-view"]', { timeout: 20000 })
}

async function createTaskFromUi(page: Page, taskName: string): Promise<void> {
	await page.goto('/apps/chorestrike/#/tasks')
	await page.waitForLoadState('networkidle', { timeout: 30000 })
	await page.waitForTimeout(800)

	await page.click('[data-testid="add-task-button"]')
	const createTaskResponsePromise = page.waitForResponse(response => {
		const isPostRequest = response.request().method() === 'POST'
		const pathname = new URL(response.url()).pathname
		return isPostRequest && pathname.endsWith('/apps/chorestrike/api/v1/tasks')
	})
	await page.waitForSelector('[data-testid="task-name-input"]', { timeout: 15000 })
	await page.fill('[data-testid="task-name-input"]', taskName)
	await page.fill('[data-testid="task-description-input"]', 'Created in GUI scratch flow')
	await page.selectOption('[data-testid="task-energy-select"]', '2')
	await page.selectOption('[data-testid="task-priority-select"]', '2')
	await page.selectOption('[data-testid="task-frequency-type"]', 'weekly')
	await page.fill('[data-testid="task-frequency-value"]', '1')
	await page.click('[data-testid="save-task-button"]')
	const createTaskResponse = await createTaskResponsePromise
	expect(createTaskResponse.ok()).toBeTruthy()
	await expect(page.locator('[data-testid="task-name-input"]')).toHaveCount(0, { timeout: 15000 })
}

async function assignTaskToRoomFromUi(page: Page, taskName: string, roomName: string): Promise<void> {
	await page.goto('/apps/chorestrike/#/')
	await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })

	await page.click(`[data-testid="room-card-${roomName}"]`)
	await page.waitForLoadState('networkidle', { timeout: 30000 })

	await page.click('[data-testid="add-task-button"]')
	await page.waitForSelector('[data-testid="task-id-select"]', { timeout: 15000 })
	const taskOptions = page.locator('[data-testid="task-id-select"] option')
	const optionValue = await taskOptions.evaluateAll((options, currentTaskName) => {
		const matchingOption = options.find(option => option.textContent?.includes(String(currentTaskName)))
		return matchingOption?.getAttribute('value') ?? null
	}, taskName)
	if (optionValue === null) {
		throw new Error(`Could not find task option for ${taskName}`)
	}
	await page.selectOption('[data-testid="task-id-select"]', optionValue)
	await page.click('[data-testid="assign-task-button"]')
	await expect(page.locator('[data-testid="room-task-list"]')).toBeVisible({ timeout: 15000 })
}

async function verifySharedViewAsSecondaryUser(browser: Browser, taskName: string, roomName: string): Promise<void> {
	const context = await browser.newContext()
	const page = await context.newPage()

	await login(page, process.env.NEXTCLOUD_SECONDARY_USER || 'adult', process.env.NEXTCLOUD_SECONDARY_PASSWORD || 'Adult123!')
	await openApp(page)

	await expect(page.locator(`[data-testid="room-card-${roomName}"]`)).toBeVisible({ timeout: 20000 })
	await page.click(`[data-testid="room-card-${roomName}"]`)
	await page.waitForLoadState('networkidle', { timeout: 30000 })
	await expect(page.locator(`text=${taskName}`)).toBeVisible({ timeout: 20000 })

	await context.close()
}

test.describe('GUI scratch flow with realistic users', () => {
	test('can build from scratch via GUI and share with second login', async ({ page, browser }) => {
		test.setTimeout(180000)
		const suffix = Date.now().toString().slice(-5)
		const floorName = `Ground Floor ${suffix}`
		const roomName = `Kitchen ${suffix}`

		await login(page, process.env.NEXTCLOUD_USER || 'admin', process.env.NEXTCLOUD_PASSWORD || 'admin')
		await openApp(page)
		await navigateToSettings(page)

		await page.goto('/apps/chorestrike/#/')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })

		const floorSections = page.locator('.floor-section')
		const initialFloorCount = await floorSections.count()

		const createFloorResponsePromise = page.waitForResponse(response => {
			const isPostRequest = response.request().method() === 'POST'
			const pathname = new URL(response.url()).pathname
			const isCreateFloorEndpoint = pathname.endsWith('/apps/chorestrike/api/v1/floors')
			return isPostRequest && isCreateFloorEndpoint
		})

		await page.click('[data-testid="add-floor-button"]')
		await page.fill('#floor-name', floorName)
		await page.click('button:has-text("Create Floor")')
		const createFloorResponse = await createFloorResponsePromise
		expect(createFloorResponse.ok()).toBeTruthy()
		const createdFloorPayload = await createFloorResponse.json()
		const createdFloorData = Array.isArray(createdFloorPayload?.data)
			? createdFloorPayload.data[0]
			: createdFloorPayload?.data
		const createdFloorId = createdFloorData?.id
		await page.waitForTimeout(1200)
		const currentFloorCount = await floorSections.count()
		expect(currentFloorCount).toBeGreaterThan(0)
		expect(currentFloorCount).toBeGreaterThan(initialFloorCount)

		const floorByName = page.locator(`[data-testid="floor-${floorName}"]`).first()
		const preferredFloorSection = await floorByName.count() > 0
			? floorByName
			: floorSections.nth(currentFloorCount - 1)
		await expect(preferredFloorSection).toBeVisible({ timeout: 15000 })

		let addRoomTrigger = preferredFloorSection.locator('.add-room-button, .add-room-card').first()
		if (await addRoomTrigger.count() === 0) {
			for (let index = 0; index < currentFloorCount; index += 1) {
				const floorSection = floorSections.nth(index)
				const toggleButton = floorSection.locator('.toggle-button').first()
				if (!await floorSection.locator('.add-room-button, .add-room-card').first().isVisible().catch(() => false)) {
					await toggleButton.click().catch(() => undefined)
					await page.waitForTimeout(500)
				}

				const candidateTrigger = floorSection.locator('.add-room-button, .add-room-card').first()
				if (await candidateTrigger.isVisible().catch(() => false)) {
					addRoomTrigger = candidateTrigger
					break
				}
			}
		}

		await expect(addRoomTrigger).toBeVisible({ timeout: 15000 })
		await addRoomTrigger.click()
		await page.fill('[data-testid="room-name-input"]', roomName)
		await page.click('[data-testid="create-room-button"]')
		await expect(page.locator(`text=${roomName}`)).toBeVisible({ timeout: 15000 })

		const taskName = `GUI Weekly Kitchen Cleanup ${suffix}`
		await createTaskFromUi(page, taskName)
		await assignTaskToRoomFromUi(page, taskName, roomName)
		await verifySharedViewAsSecondaryUser(browser, taskName, roomName)
	})
})
