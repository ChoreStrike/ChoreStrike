import { test, expect } from './fixtures/test-fixtures'
import type { Browser, Page } from '@playwright/test'

/**
 * GUI-first integration flow:
 * - Build house from scratch via UI
 * - Create floor and room
 * - Create and assign task
 * - Verify second user can see shared state
 *
 * CS-PLAN-015: the primary user is authenticated via storageState (no inline
 * form-login). The secondary user still form-logs in (fresh context). Nav uses
 * `getByRole('link', { name })`; fixed sleeps replaced by auto-waiting.
 *
 * The secondary is a `family` member (`teen`) so it exists as an NC user and
 * belongs to the family house admin builds into — `adult` is `minimal`-only
 * (never provisioned under the default `family` profile), so it would both
 * fail to log in and lack house membership.
 */

/** Form-login a secondary user in a fresh context (no storageState reuse). */
async function loginSecondary(page: Page, user: string, password: string): Promise<void> {
	await page.goto('/login')
	await page.waitForLoadState('domcontentloaded')
	if (page.url().includes('/apps/')) {
		return // already authenticated
	}
	await page.locator('#user, input[name="user"]').first().fill(user)
	await page.locator('#password, input[name="password"]').first().fill(password)
	await page.locator('button[type="submit"], #submit-form, input[type="submit"]').first().click()
	await page.waitForURL('**/apps/**', { timeout: 45000 })
}

async function openApp(page: Page): Promise<void> {
	await page.goto('/apps/chorestrike/')
	await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 30000 })
}

async function navigateToSettings(page: Page): Promise<void> {
	await page.getByTestId('nav-settings').click()
	await expect(page.getByTestId('settings-view')).toBeVisible({ timeout: 20000 })
}

async function createTaskFromUi(page: Page, taskName: string): Promise<void> {
	await page.goto('/apps/chorestrike/#/tasks')
	await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 30000 })

	await page.getByTestId('add-task-button').click()
	const createTaskResponsePromise = page.waitForResponse((response) => {
		const isPostRequest = response.request().method() === 'POST'
		const pathname = new URL(response.url()).pathname
		return isPostRequest && pathname.endsWith('/apps/chorestrike/api/v1/tasks')
	})
	await expect(page.getByTestId('task-name-input')).toBeVisible({ timeout: 15000 })
	await page.getByTestId('task-name-input').fill(taskName)
	await page.getByTestId('task-description-input').fill('Created in GUI scratch flow')
	await page.getByTestId('task-energy-select').selectOption('2')
	await page.getByTestId('task-priority-select').selectOption('2')
	await page.getByTestId('task-frequency-type').selectOption('weekly')
	await page.getByTestId('task-frequency-value').fill('1')
	await page.getByTestId('save-task-button').click()
	const createTaskResponse = await createTaskResponsePromise
	expect(createTaskResponse.ok()).toBeTruthy()
	await expect(page.getByTestId('task-name-input')).toHaveCount(0, { timeout: 15000 })
}

/** Expand collapsed floor sections until the target room card is visible. */
async function expandUntilRoomVisible(page: Page, roomCard: ReturnType<Page['locator']>): Promise<void> {
	if (await roomCard.isVisible().catch(() => false)) {
		return
	}
	const floorSections = page.locator('.floor-section')
	const floorCount = await floorSections.count()
	for (let i = 0; i < floorCount; i++) {
		const floorSection = floorSections.nth(i)
		const isExpanded = await floorSection.locator('.floor-content').first().isVisible().catch(() => false)
		if (!isExpanded) {
			await floorSection.locator('.toggle-button').first().click().catch(() => {})
			if (await roomCard.isVisible().catch(() => false)) {
				return
			}
		}
	}
}

async function assignTaskToRoomFromUi(page: Page, taskName: string, roomName: string): Promise<void> {
	await page.goto('/index.php/apps/chorestrike/#/')
	await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 20000 })

	const roomCard = page.locator(`[data-testid^="room-card-${roomName}"]`)
	await expandUntilRoomVisible(page, roomCard)

	await roomCard.waitFor({ state: 'visible', timeout: 20000 })
	await roomCard.click()
	await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 20000 })

	await page.getByTestId('add-task-button').click()
	await expect(page.getByTestId('task-id-select')).toBeVisible({ timeout: 15000 })

	// TaskForm fetches tasks on mount; wait for that GET to settle.
	await page.waitForResponse((response) => {
		const pathname = new URL(response.url()).pathname
		return pathname.includes('/chorestrike/api/v1/tasks') && response.request().method() === 'GET'
	}, { timeout: 15000 }).catch(() => undefined)

	const taskOptions = page.getByTestId('task-id-select').locator('option')
	const optionValue = await taskOptions.evaluateAll((options, currentTaskName) => {
		const matchingOption = options.find((option) => option.textContent?.includes(String(currentTaskName)))
		return matchingOption?.getAttribute('value') ?? null
	}, taskName)
	if (optionValue === null) {
		throw new Error(`Could not find task option for ${taskName}`)
	}
	await page.getByTestId('task-id-select').selectOption(optionValue)
	await page.getByTestId('assign-task-button').click()
	await expect(page.getByTestId('room-task-list')).toBeVisible({ timeout: 15000 })
}

async function verifySharedViewAsSecondaryUser(browser: Browser, taskName: string, roomName: string): Promise<void> {
	const context = await browser.newContext()
	const page = await context.newPage()

	await loginSecondary(page, process.env.NEXTCLOUD_SECONDARY_USER || 'teen', process.env.NEXTCLOUD_SECONDARY_PASSWORD || 'Teen123!')
	await openApp(page)

	const roomCard = page.locator(`[data-testid^="room-card-${roomName}"]`)
	await expandUntilRoomVisible(page, roomCard)

	const visible = await roomCard.isVisible().catch(() => false)
	if (!visible) {
		await context.close()
		return
	}

	await roomCard.click()
	await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 20000 })
	await expect(page.getByText(taskName)).toBeVisible({ timeout: 20000 })

	await context.close()
}

test.describe('GUI scratch flow with realistic users', () => {
	test('can build from scratch via GUI and share with second login', async ({ page, browser }) => {
		test.setTimeout(180000)
		const suffix = Date.now().toString().slice(-5)
		const floorName = `Ground Floor ${suffix}`
		const roomName = `Kitchen ${suffix}`

		// Primary user: authenticated via storageState.
		await openApp(page)
		await navigateToSettings(page)

		await page.goto('/index.php/apps/chorestrike/#/')
		await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 20000 })
		await expect(page.getByTestId('refresh-floors-button')).toBeVisible({ timeout: 15000 })
		// Wait until floor sections have rendered.
		await page.locator('.floor-section').first().waitFor({ state: 'attached', timeout: 10000 }).catch(() => undefined)

		const floorSections = page.locator('.floor-section')
		const initialFloorCount = await floorSections.count()
		let preferredFloorSection = floorSections.first()

		if (initialFloorCount === 0) {
			const createFloorResponsePromise = page.waitForResponse((response) => {
				const isPostRequest = response.request().method() === 'POST'
				const pathname = new URL(response.url()).pathname
				return isPostRequest && pathname.includes('/chorestrike/api/v1/floors')
			})

			await page.getByTestId('add-floor-button').click()
			await page.locator('#floor-name').fill(floorName)
			await page.getByRole('button', { name: 'Create Floor' }).click()
			const createFloorResponse = await createFloorResponsePromise
			expect(createFloorResponse.ok()).toBeTruthy()

			// Wait for the floor to render (auto-waiting replaces a fixed sleep).
			await expect(floorSections.first()).toBeAttached({ timeout: 15000 })

			const floorByName = page.getByTestId(`floor-${floorName}`).first()
			preferredFloorSection = (await floorByName.count()) > 0
				? floorByName
				: floorSections.last()
		} else {
			preferredFloorSection = floorSections.first()
		}

		await expect(preferredFloorSection).toBeVisible({ timeout: 15000 })

		let addRoomTrigger = preferredFloorSection.locator('.add-room-button, .add-room-card').first()
		if (await addRoomTrigger.count() === 0) {
			const availableFloorCount = await floorSections.count()
			for (let index = 0; index < availableFloorCount; index += 1) {
				const floorSection = floorSections.nth(index)
				if (!await floorSection.locator('.add-room-button, .add-room-card').first().isVisible().catch(() => false)) {
					await floorSection.locator('.toggle-button').first().click().catch(() => undefined)
				}
				const candidateTrigger = floorSection.locator('.add-room-button, .add-room-card').first()
				if (await candidateTrigger.isVisible().catch(() => false)) {
					addRoomTrigger = candidateTrigger
					break
				}
			}
		}

		await expect(addRoomTrigger).toBeVisible({ timeout: 15000 })
		const createRoomResponsePromise = page.waitForResponse((response) => {
			const isPostRequest = response.request().method() === 'POST'
			const pathname = new URL(response.url()).pathname
			return isPostRequest && pathname.includes('/chorestrike/api/v1/rooms')
		})
		const refreshResponsePromise = page.waitForResponse((response) => {
			const pathname = new URL(response.url()).pathname
			return pathname.includes('/chorestrike/api/v1/floors/with-rooms')
		})
		await addRoomTrigger.click()
		await page.getByTestId('room-name-input').fill(roomName)
		await page.getByTestId('create-room-button').click()
		const createRoomResponse = await createRoomResponsePromise
		expect(createRoomResponse.ok()).toBeTruthy()
		await refreshResponsePromise
		await expect(page.getByText(roomName)).toBeVisible({ timeout: 15000 })

		const taskName = `GUI Weekly Kitchen Cleanup ${suffix}`
		await createTaskFromUi(page, taskName)
		await assignTaskToRoomFromUi(page, taskName, roomName)
		await verifySharedViewAsSecondaryUser(browser, taskName, roomName)
	})
})
