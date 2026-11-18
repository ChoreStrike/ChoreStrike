import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { userStatePath } from './fixtures/auth-contexts.ts'
import { holdToConfirm } from './fixtures/hold-to-confirm.ts'
import { openRanking } from './fixtures/insights.ts'
import { expectRoomOpen, listRooms, openRoom } from './fixtures/rooms.ts'
import { openSettingsCategory } from './fixtures/settings.ts'

/** Resolve the Kitchen room at runtime — the DELETE-based test reset never
 * restarts AUTO_INCREMENT, so room ids grow. */
async function kitchenRoomId(page: Page): Promise<number> {
	const kitchen = (await listRooms(page)).find((room) => room.name === 'Kitchen')
	expect(kitchen, 'the family seed always provides the Kitchen room').toBeTruthy()
	return kitchen!.id
}

/**
 * E2E Tests for Core App Functionality
 * Tests basic navigation, data loading, and UI components.
 *
 * CS-PLAN-015: nav uses `getByRole('link', { name })` (unlocked by the App.vue
 * testid/role passthrough fix); auth via storageState; fixed sleeps replaced by
 * auto-waiting assertions.
 * CS-PLAN-068 (CR-WF-05): no fixture-dependent skips. The family seed
 * guarantees the Kitchen room and an active task in it.
 * CS-PLAN-090 WP-B: Today is the landing page; a room opens through the
 * shared `fixtures/rooms.ts` helper.
 */
test.describe('Core App Functionality', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)
		await page.goto('/apps/chorestrike/#/today')
		await expect(page.getByTestId('today-view')).toBeVisible({ timeout: 30000 })
	})

	test('Today loads and displays its basic components', async ({ page }) => {
		await expect(page.getByTestId('today-title')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('today-status-line')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('today-filter-mine')).toBeVisible({ timeout: 15000 })
	})

	test('Navigation between main views works', async ({ page }) => {
		await page.getByTestId('nav-tasks').click()
		await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 15000 })

		await openRanking(page)

		await page.getByTestId('nav-settings').click()
		await expect(page.getByTestId('settings-view')).toBeVisible({ timeout: 15000 })

		await page.getByTestId('nav-today').click()
		await expect(page.getByTestId('today-view')).toBeVisible({ timeout: 15000 })
	})

	test('Task Manager loads and displays controls', async ({ page }) => {
		await page.getByTestId('nav-tasks').click()
		await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })

		await expect(page.getByTestId('status-filter')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('priority-filter')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('difficulty-filter')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('search-input')).toBeVisible()
		await expect(page.getByTestId('add-task-button')).toBeVisible({ timeout: 15000 })
	})

	// CS-PLAN-042: house-name/floors controls are owner-only; assert them as
	// `mom` (seeded family-house owner), not the default member `admin`.
	test.describe('Settings page controls (owner surface)', () => {
		test.use({ storageState: userStatePath('mom') })

		test('Settings page loads and displays controls', async ({ page }) => {
			await page.getByTestId('nav-settings').click()
			await expect(page.getByTestId('settings-view')).toBeVisible({ timeout: 20000 })

			await openSettingsCategory(page, 'house')
			await openSettingsCategory(page, 'structure')
			await expect(page.getByTestId('house-name-input')).toBeVisible({ timeout: 15000 })
			await expect(page.getByTestId('floors-list')).toBeVisible({ timeout: 15000 })
			await expect(page.getByTestId('add-floor-button')).toBeVisible({ timeout: 15000 })
		})
	})

	// CS-PLAN-090 WP-E: the Leaderboard page became a section of Insights;
	// its address (bookmarks) leads there.
	test('The old Leaderboard address opens the ranking in Insights', async ({ page }) => {
		await page.goto('/apps/chorestrike/#/leaderboard')

		await expect(page).toHaveURL(/#\/insights#ranking$/)
		await expect(page.getByTestId('insights-ranking').getByTestId('ranking-panel')).toBeVisible({ timeout: 20000 })
		// The view follows the anchor itself (hash routing, D-090-54).
		await expect(page.getByTestId('insights-ranking').getByRole('heading', { name: 'Ranking', exact: true })).toBeInViewport()
		await expect(page.getByTestId('nav-leaderboard')).toHaveCount(0)
	})

	// CS-PLAN-090 WP-C: the room page went into the task list; its address
	// (bookmarks) redirects to that room there.
	test('The old room address opens that room in the task list by room', async ({ page }) => {
		const [room] = await listRooms(page)

		await page.goto(`/apps/chorestrike/#/room/${room.id}`)

		await expectRoomOpen(page, room)
		await expect(page).toHaveURL(new RegExp(`#/tasks\\?group=room&room=${room.id}$`))
		await expect(page.getByTestId('tasks-group-room')).toHaveAttribute('aria-pressed', 'true')
	})

	test('Task completion flow works', async ({ page }) => {
		// A room's tasks are completed in the task list by room; the family
		// seed guarantees the Kitchen room. CS-PLAN-068: complete a DEDICATED
		// probe task — parallel-pool siblings also complete Kitchen rows, so
		// "first complete button in the room" is not a stable target. The
		// probe is created via an IN-PAGE fetch (the restored session's
		// request context has no CSRF token; the page's own fetch carries
		// OC.requestToken).
		const kitchen = await kitchenRoomId(page)
		const probeName = `Core Flow Probe ${Date.now()}`
		const probeStatus = await page.evaluate(async ({ roomId, name }) => {
			const response = await fetch('/apps/chorestrike/api/v1/room-tasks', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: (window as unknown as { OC: { requestToken: string } }).OC.requestToken,
				},
				body: JSON.stringify({
					roomId,
					// First day TODAY (the creation default is tomorrow) so the
					// cycle is open and the UI completion resolves a shown target.
					firstDate: new Date().toISOString().slice(0, 10),
					task: { name, energyCost: 1, frequencyType: 'daily', frequencyValue: 1 },
				}),
			})
			return response.status
		}, { roomId: kitchen, name: probeName })
		expect(probeStatus).toBe(200)

		await openRoom(page, 'Kitchen')

		const probeRow = page.locator('[data-testid="task-item"]').filter({ hasText: probeName }).first()
		await expect(probeRow).toBeVisible({ timeout: 15000 })
		const completeResponsePromise = page.waitForResponse((response) => {
			const pathname = new URL(response.url()).pathname
			return response.request().method() === 'POST' && pathname.endsWith('/complete')
		})
		await holdToConfirm(probeRow.locator('[data-testid="complete-task-button"]'))
		const receipt = await (await completeResponsePromise).json()
		expect(receipt.success).toBe(true)
		// A reward/state change confirms completion: the queued-undo
		// snackbar carries the reward summary.
		await expect(page.getByTestId('undo-snackbar')).toBeVisible({ timeout: 15000 })
	})

	test('Filters and search work in Task Manager', async ({ page }) => {
		await page.getByTestId('nav-tasks').click()
		await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })

		await page.getByTestId('status-filter').selectOption('active')
		await page.getByTestId('priority-filter').selectOption('urgent')
		await page.getByTestId('search-input').fill('test')
		await page.getByTestId('search-input').fill('')

		// View stays mounted after all interactions.
		await expect(page.getByTestId('task-manager-view')).toBeVisible()
	})
})
