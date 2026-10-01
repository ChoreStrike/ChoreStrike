import { test, expect } from '@playwright/test'
import { userStatePath } from './fixtures/auth-contexts'
import { expandAllFloors } from './fixtures/dashboard-floors'
import type { Page } from '@playwright/test'

/** Resolve the Kitchen room at runtime — the DELETE-based test reset never
 * restarts AUTO_INCREMENT, so room ids grow. Read via an IN-PAGE fetch (the
 * restored session's request context has no CSRF token; the page's own
 * fetch carries OC.requestToken). */
async function kitchenRoomId(page: Page): Promise<number> {
	const rooms = await page.evaluate(async () => {
		const response = await fetch('/apps/chorestrike/api/v1/floors/with-rooms', {
			headers: {
				Accept: 'application/json',
				requesttoken: (window as unknown as { OC: { requestToken: string } }).OC.requestToken,
			},
		})
		const body = await response.json()
		return (body.data ?? []) as Array<{ rooms: Array<{ id: number; name: string }> }>
	})
	const kitchen = rooms
		.flatMap((floor) => floor.rooms)
		.find((room) => room.name === 'Kitchen')
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
 * CS-PLAN-068 (CR-WF-05): no fixture-dependent skips — the Dashboard renders
 * floors COLLAPSED, so room cards appear only after `expandAllFloors`; the
 * former bare-count guards skipped forever on that collapsed default. The
 * family seed guarantees the Kitchen room and an active task in it.
 */
test.describe('Core App Functionality', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)
		await page.goto('/apps/chorestrike/#/dashboard')
		await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 30000 })
	})

	test('Dashboard loads and displays basic components', async ({ page }) => {
		await expect(page.getByTestId('house-health')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('floor-list')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('quick-stats')).toBeVisible({ timeout: 15000 })
	})

	test('Navigation between main views works', async ({ page }) => {
		await page.getByTestId('nav-tasks').click()
		await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 15000 })

		await page.getByTestId('nav-leaderboard').click()
		await expect(page.getByTestId('leaderboard-view')).toBeVisible({ timeout: 15000 })

		await page.getByTestId('nav-settings').click()
		await expect(page.getByTestId('settings-view')).toBeVisible({ timeout: 15000 })

		await page.getByTestId('nav-dashboard').click()
		await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 15000 })
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

			await expect(page.getByTestId('house-name-input')).toBeVisible({ timeout: 15000 })
			await expect(page.getByTestId('floors-list')).toBeVisible({ timeout: 15000 })
			await expect(page.getByTestId('add-floor-button')).toBeVisible({ timeout: 15000 })
		})
	})

	test('Leaderboard page loads', async ({ page }) => {
		await page.getByTestId('nav-leaderboard').click()
		await expect(page.getByTestId('leaderboard-view')).toBeVisible({ timeout: 20000 })
	})

	test('Room cards are clickable and navigate to room detail', async ({ page }) => {
		await expandAllFloors(page)

		const roomCards = page.locator('[data-testid^="room-card-"]')
		await expect(roomCards.first()).toBeAttached({ timeout: 15000 })

		await roomCards.first().click()
		await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 15000 })
	})

	test('Task completion flow works', async ({ page }) => {
		await expandAllFloors(page)

		// Room-task completion lives in the room detail view; the family
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
					// CS-PLAN-072 WP1: due TODAY (the creation default is
					// tomorrow) so the occurrence window is open and the UI
					// completion resolves a shown target.
					nextDue: new Date().toISOString().slice(0, 10),
					task: { name, energyCost: 1, frequencyType: 'daily', frequencyValue: 1 },
				}),
			})
			return response.status
		}, { roomId: kitchen, name: probeName })
		expect(probeStatus).toBe(200)

		await page.getByTestId('room-card-Kitchen').first().click()
		await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 15000 })

		const probeRow = page.locator('[data-testid="task-item"]').filter({ hasText: probeName }).first()
		await expect(probeRow).toBeVisible({ timeout: 15000 })
		const completeResponsePromise = page.waitForResponse((response) => {
			const pathname = new URL(response.url()).pathname
			return response.request().method() === 'POST' && pathname.endsWith('/complete')
		})
		await probeRow.locator('[data-testid="complete-task-button"]').click()
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
