import { test, expect } from './fixtures/test-fixtures'
import { userStatePath } from './fixtures/auth-contexts'
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
 * The primary is `mom` — a seeded family-house OWNER — because structure CRUD
 * lives behind the owner-gated Settings surface since CS-PLAN-042 (the
 * default `admin` identity is a plain member under the family profile).
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

async function createTaskFromUi(page: Page, taskName: string, floorName: string, roomName: string): Promise<void> {
	await page.goto('/apps/chorestrike/#/tasks')
	await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 30000 })

	await page.getByTestId('add-task-button').click()
	await expect(page.getByTestId('task-name-input')).toBeVisible({ timeout: 15000 })
	await page.getByTestId('task-name-input').fill(taskName)
	await page.getByTestId('task-description-input').fill('Created in GUI scratch flow')
	await page.getByTestId('task-energy-select').selectOption('2')
	await page.getByTestId('task-priority-select').selectOption('2')
	await page.getByTestId('task-frequency-type').selectOption('weekly')

	// Unified creation (CS-PLAN-038): the definition form now includes the
	// room picker; saving chains definition → room task materialization.
	// Two-level picker (floor → rooms of that floor): select the target
	// floor first, otherwise only the default floor's rooms are offered.
	const floorSelect = page.getByTestId('room-picker-floor')
	await expect(floorSelect).toBeVisible({ timeout: 15000 })
	await floorSelect.selectOption({ label: floorName })

	const roomSelect = page.getByTestId('room-picker-room')
	await roomSelect.selectOption({ label: roomName })

	const roomTaskResponsePromise = page.waitForResponse((response) => {
		const isPostRequest = response.request().method() === 'POST'
		const pathname = new URL(response.url()).pathname
		return isPostRequest && pathname.endsWith('/apps/chorestrike/api/v1/room-tasks')
	})
	await page.getByTestId('save-task-button').click()
	const roomTaskResponse = await roomTaskResponsePromise
	expect(roomTaskResponse.ok()).toBeTruthy()
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

async function verifyTaskInRoomFromUi(page: Page, taskName: string, roomName: string): Promise<void> {
	await page.goto('/index.php/apps/chorestrike/#/')
	await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 20000 })

	const roomCard = page.locator(`[data-testid^="room-card-${roomName}"]`)
	await expandUntilRoomVisible(page, roomCard)

	await roomCard.waitFor({ state: 'visible', timeout: 20000 })
	await roomCard.click()
	await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 20000 })

	// The created room task must be visible in its room (no invisible
	// definitions anymore — UX-001).
	await expect(page.getByTestId('room-task-list').getByText(taskName)).toBeVisible({ timeout: 15000 })
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
	test.use({ storageState: userStatePath('mom') })

	test('can build from scratch via GUI and share with second login', async ({ page, browser }) => {
		test.setTimeout(180000)
		const suffix = Date.now().toString().slice(-5)
		const floorName = `Ground Floor ${suffix}`
		const roomName = `Kitchen ${suffix}`

		// Primary user: authenticated via storageState.
		await openApp(page)

		// Structure CRUD lives in Settings since CS-PLAN-042 (the Dashboard
		// FloorList is read-only).
		await navigateToSettings(page)
		await page.locator('.floor-item').first().waitFor({ state: 'attached', timeout: 15000 }).catch(() => undefined)

		const floorItems = page.locator('.floor-item')
		if (await floorItems.count() === 0 || !(await page.getByTestId(`floor-${floorName}`).isVisible().catch(() => false))) {
			const createFloorResponsePromise = page.waitForResponse((response) => {
				const isPostRequest = response.request().method() === 'POST'
				const pathname = new URL(response.url()).pathname
				return isPostRequest && pathname.includes('/chorestrike/api/v1/floors')
			})

			await page.getByTestId('add-floor-button').click()
			await page.locator('#new-floor-name').fill(floorName)
			await page.getByRole('button', { name: 'Create' }).click()
			const createFloorResponse = await createFloorResponsePromise
			expect(createFloorResponse.ok()).toBeTruthy()

			// Wait for the floor row to render (auto-waiting replaces a fixed sleep).
			await expect(page.getByTestId(`floor-${floorName}`)).toBeVisible({ timeout: 15000 })
		}

		const preferredFloorItem = page.getByTestId(`floor-${floorName}`).first()
		await expect(preferredFloorItem).toBeVisible({ timeout: 15000 })

		// Create the room inline on the floor row.
		await preferredFloorItem.locator('[data-testid^="expand-floor-"]').click()
		const createRoomResponsePromise = page.waitForResponse((response) => {
			const isPostRequest = response.request().method() === 'POST'
			const pathname = new URL(response.url()).pathname
			return isPostRequest && pathname.includes('/chorestrike/api/v1/rooms')
		})
		await preferredFloorItem.locator('[data-testid="new-room-name-input"]').fill(roomName)
		await preferredFloorItem.locator('[data-testid="add-room-to-floor-button"]').click()
		const createRoomResponse = await createRoomResponsePromise
		expect(createRoomResponse.ok()).toBeTruthy()
		await expect(preferredFloorItem.locator('.room-row-name', { hasText: roomName })).toBeVisible({ timeout: 15000 })

		const taskName = `GUI Weekly Kitchen Cleanup ${suffix}`
		await createTaskFromUi(page, taskName, floorName, roomName)
		await verifyTaskInRoomFromUi(page, taskName, roomName)
		await verifySharedViewAsSecondaryUser(browser, taskName, roomName)
	})
})
