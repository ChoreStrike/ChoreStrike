import { expect, test } from '@playwright/test'
import { createUserContext, userStatePath } from './fixtures/auth-contexts.ts'
import { createRoomViaSettings, setupTestHouse } from './fixtures/test-house.ts'

/**
 * E2E Test: 3-Floor House Scenario
 *
 * House configuration:
 * - Keller: WC, Dusche
 * - Erdgeschoss (EG): Gästebad, Garten
 * - 1. Obergeschoss (1.OG): WC
 *
 * Testszenarien:
 * 1. Haus-Setup (alle Etagen erstellen)
 * 2. Navigation durch alle Etagen
 * 3. Räume verwalten (WC, Gästebad, Dusche, Garten) — via Settings
 *    (CS-PLAN-042: the Dashboard FloorList is read-only; Settings is the
 *    sole structure CRUD surface)
 * 4. Tasks für Badezimmer-Räume erstellen
 *
 * Runs as `mom` (a seeded family-house OWNER): structure CRUD is
 * owner-gated since CS-PLAN-042 and the default `admin` identity is a
 * plain member under the family profile.
 */

test.describe('3-Floor House: Keller, EG, 1.OG', () => {
	test.use({ storageState: userStatePath('mom') })

	test.beforeEach(async ({ page }) => {
		test.setTimeout(90000)

		// Navigate to app (assuming already logged in)
		await page.goto('/apps/chorestrike/#/dashboard')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 20000 })
	})

	test('Setup: Create all 3 floors (Keller, EG, 1.OG)', async ({ page }) => {
		await setupTestHouse(page)

		// We're already on settings view from setupTestHouse, verify floors exist
		// Check Keller exists
		await expect(page.locator('[data-testid="floor-Keller"]')).toBeVisible()

		// Check Erdgeschoss exists
		await expect(page.locator('[data-testid="floor-Erdgeschoss"]')).toBeVisible()

		// Check 1. Obergeschoss exists
		await expect(page.locator('[data-testid="floor-1. Obergeschoss"]')).toBeVisible()
	})

	test('Navigation: Can navigate to all 3 floors from dashboard', async ({ page }) => {
		// Ensure house is set up
		await setupTestHouse(page)

		// Navigate to dashboard
		await page.click('[data-testid="nav-dashboard"]')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 10000 })

		// Verify the three scenario floors are visible on the dashboard. The
		// seeded family house contributes its own floors (Ground/First/Second),
		// so assert presence + a floor count of at least three instead of an
		// exact count that would couple the spec to the seed contents.
		for (const name of ['Keller', 'Erdgeschoss', '1. Obergeschoss']) {
			await expect(page.getByTestId(`floor-${name}`)).toBeVisible({ timeout: 15000 })
		}
		const sectionCount = await page.locator('.floor-section').count()
		expect(sectionCount).toBeGreaterThanOrEqual(3)
	})

	test('Room Management: Create WC in Keller (Settings surface)', async ({ page }) => {
		await setupTestHouse(page)
		await createRoomViaSettings(page, 'Keller', 'WC')
	})

	test('Room Management: Create Gästebad in EG (Settings surface)', async ({ page }) => {
		await setupTestHouse(page)
		await createRoomViaSettings(page, 'Erdgeschoss', 'Gästebad')
	})

	test('Room Management: Create Garten in EG (Settings surface)', async ({ page }) => {
		await setupTestHouse(page)
		await createRoomViaSettings(page, 'Erdgeschoss', 'Garten')
	})

	test('Room Management: Create Dusche in Keller (Settings surface)', async ({ page }) => {
		await setupTestHouse(page)
		await createRoomViaSettings(page, 'Keller', 'Dusche')
	})

	test('Room Management: Create WC in 1.OG (Settings surface)', async ({ page }) => {
		await setupTestHouse(page)
		await createRoomViaSettings(page, '1. Obergeschoss', 'WC')
	})

	test('Task Management: Add cleaning task to Gästebad', async ({ page }) => {
		// Setup house with rooms
		await setupTestHouse(page)
		await createRoomViaSettings(page, 'Erdgeschoss', 'Gästebad')

		// Unified task creation (CS-PLAN-038): the definition form includes
		// the room picker; saving chains definition → room task.
		await page.goto('/apps/chorestrike/#/tasks')
		await page.waitForSelector('[data-testid="task-manager-view"]', { timeout: 10000 })
		await page.getByTestId('add-task-button').click()
		await expect(page.getByTestId('task-name-input')).toBeVisible({ timeout: 15000 })

		await page.getByTestId('task-name-input').fill('Gästebad putzen')
		await page.getByTestId('task-description-input').fill('Boden wischen, Spiegel reinigen')
		await page.getByTestId('task-energy-select').selectOption('2')
		await page.getByTestId('task-priority-select').selectOption('2')
		await page.getByTestId('task-frequency-type').selectOption('weekly')

		// Two-level picker (floor → rooms of that floor): select the target
		// floor first, otherwise only the default floor's rooms are offered.
		await page.getByTestId('room-picker-floor').selectOption({ label: 'Erdgeschoss' })
		await page.getByTestId('room-picker-room').selectOption({ label: 'Gästebad' })

		const roomTaskResponsePromise = page.waitForResponse((response) => {
			const isPostRequest = response.request().method() === 'POST'
			const pathname = new URL(response.url()).pathname
			return isPostRequest && pathname.endsWith('/apps/chorestrike/api/v1/room-tasks')
		})
		await page.getByTestId('save-task-button').click()

		// The room task materialized (201 response) and the form closed —
		// no invisible definitions anymore (UX-001).
		const roomTaskResponse = await roomTaskResponsePromise
		expect(roomTaskResponse.ok()).toBeTruthy()
		await expect(page.getByTestId('task-name-input')).toHaveCount(0, { timeout: 15000 })
	})

	test('Atomic Journey: create-with-assignee renders the assignee from ONE request (CS-PLAN-063)', async ({ page }) => {
		await setupTestHouse(page)
		await createRoomViaSettings(page, 'Erdgeschoss', 'Gästebad')

		await page.goto('/apps/chorestrike/#/tasks')
		await page.waitForSelector('[data-testid="task-manager-view"]', { timeout: 10000 })
		await page.getByTestId('add-task-button').click()
		await expect(page.getByTestId('task-name-input')).toBeVisible({ timeout: 15000 })

		await page.getByTestId('task-name-input').fill('Atomic Journey Task')
		await page.getByTestId('task-energy-select').selectOption('2')
		await page.getByTestId('room-picker-floor').selectOption({ label: 'Erdgeschoss' })
		await page.getByTestId('room-picker-room').selectOption({ label: 'Gästebad' })

		// Pick one assignee chip (any house member). CS-PLAN-068: the chips
		// render from the members prop the form now feeds — a missing chip
		// is a FAILURE, not a skip.
		const chip = page.locator('[data-testid^="assignee-chip-"]').first()
		await expect(chip).toBeVisible({ timeout: 15000 })
		await chip.click()

		let createPosts = 0
		let assigneePuts = 0
		page.on('request', (request) => {
			if (request.method() === 'POST' && request.url().includes('/api/v1/room-tasks')) {
				createPosts++
			}
			if (request.method() === 'PUT' && request.url().includes('/assignees')) {
				assigneePuts++
			}
		})

		const aggregatePromise = page.waitForResponse((response) => {
			const isPostRequest = response.request().method() === 'POST'
			const pathname = new URL(response.url()).pathname
			return isPostRequest && pathname.endsWith('/apps/chorestrike/api/v1/room-tasks')
		})
		await page.getByTestId('save-task-button').click()

		const aggregateResponse = await aggregatePromise
		expect(aggregateResponse.ok()).toBeTruthy()
		const body = await aggregateResponse.json()
		expect(body.data.task.name).toBe('Atomic Journey Task')
		expect(body.data.assignees).toHaveLength(1)

		// The modal closes and the row renders with the assignee badge
		// immediately — driven by the aggregate response, not a second PUT.
		await expect(page.getByTestId('task-name-input')).toHaveCount(0, { timeout: 15000 })
		// The list opens on "My tasks"; the chip may name another member
		// (e.g. admin after the parallel pool joined), so show everyone and
		// search for the row (20 per page) — client-side, no request.
		await page.getByTestId('tasks-filter-all').click()
		await page.getByTestId('search-input').fill('Atomic Journey Task')
		const row = page.locator('[data-testid="task-item"]').filter({ hasText: 'Atomic Journey Task' }).first()
		await expect(row).toBeVisible({ timeout: 15000 })
		await expect(row.locator('.meta-item.assigned')).toBeVisible()

		// Exactly one creation request, zero follow-up assignment requests.
		expect(createPosts).toBe(1)
		expect(assigneePuts).toBe(0)
	})

	test('Creation failure leaves no orphan definition (CS-PLAN-063)', async ({ browser }) => {
		// CS-PLAN-068: this test calls the JSON API directly — only
		// fresh-login sessions may do that (the container's CSRF gate
		// answers 412 to restored storageState contexts).
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await setupTestHouse(page)

			// A valid room for the failed attempt — the test house setup
			// always creates at least one room (CS-PLAN-068: precondition is
			// asserted, never skipped).
			const floorsResponse = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms', {
				headers: { Accept: 'application/json' },
			})
			expect(floorsResponse.status()).toBe(200)
			const roomId = ((await floorsResponse.json()).data ?? [])
				.flatMap((entry: { rooms?: Array<{ id: number }> }) => entry.rooms ?? [])[0]?.id
			expect(roomId, 'the test house setup must provide a room').toBeTruthy()

			// flexDays 8 violates the ratified [0, 7] bound → 400, zero writes.
			const failed = await page.request.post('/apps/chorestrike/api/v1/room-tasks', {
				data: {
					roomId,
					task: { name: 'Orphan Probe', energyCost: 2, flexDays: 8 },
				},
				headers: { Accept: 'application/json' },
			})
			expect(failed.status()).toBe(400)

			// The task list must not contain the rejected definition.
			await page.goto('/apps/chorestrike/#/tasks')
			await page.waitForSelector('[data-testid="task-manager-view"]', { timeout: 20000 })
			await expect(page.locator('[data-testid="task-item"]').filter({ hasText: 'Orphan Probe' })).toHaveCount(0)
		} finally {
			await context.close()
		}
	})

	test('Settings: Verify all floors listed in settings', async ({ page }) => {
		// Setup house
		await setupTestHouse(page)

		// Navigate to settings
		await page.click('[data-testid="nav-settings"]')
		await page.waitForSelector('[data-testid="settings-view"]', { timeout: 10000 })

		// Verify floors section exists
		await expect(page.locator('[data-testid="floors-list"], .floors-list')).toBeVisible()

		// Count floor items
		const floorItems = page.locator('[data-testid^="floor-"]')
		const count = await floorItems.count()
		expect(count).toBeGreaterThanOrEqual(3)

		// Verify specific floors (testid-scoped: a bare text= locator would
		// be a strict-mode hazard once several floors exist)
		await expect(page.getByTestId('floor-Keller')).toBeVisible()
		await expect(page.getByTestId('floor-Erdgeschoss')).toBeVisible()
		await expect(page.getByTestId('floor-1. Obergeschoss')).toBeVisible()
	})

	test('Complete Workflow: Setup house with all rooms and verify', async ({ page }) => {
		// Full setup
		await setupTestHouse(page)

		// Navigate through all views
		// 1. Dashboard
		await page.click('[data-testid="nav-dashboard"]')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 10000 })

		// 2. Tasks
		await page.click('[data-testid="nav-tasks"]')
		await page.waitForSelector('[data-testid="task-manager-view"]', { timeout: 10000 })

		// 3. Settings
		await page.click('[data-testid="nav-settings"]')
		await page.waitForSelector('[data-testid="settings-view"]', { timeout: 10000 })

		// 4. Leaderboard
		await page.click('[data-testid="nav-leaderboard"]')
		await page.waitForSelector('[data-testid="leaderboard-view"]', { timeout: 10000 })

		// Return to dashboard
		await page.click('[data-testid="nav-dashboard"]')
		await page.waitForSelector('[data-testid="dashboard-view"]', { timeout: 10000 })

		// Verify floors are still there
		await expect(page.locator('text=Keller').first()).toBeVisible()
		await expect(page.locator('text=Erdgeschoss').first()).toBeVisible()
		await expect(page.locator('text=1. Obergeschoss').first()).toBeVisible()
	})
})
