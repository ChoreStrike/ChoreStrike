/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * WP7 exit journey — canonical client state + family UX (CS-PLAN-066 §4.3).
 *
 * Pins the full family matrix against the `family` profile:
 *  - Owner desktop: cold Dashboard count projections (D2), the real HTTP
 *    PUT /tasks/{id} edit roundtrip (D6/BUG-API-001), complete → coordinated
 *    reconciliation without reload (D3/D10) and the queued undo.
 *  - Child (teen): the D4 landing redirect (bare root → My Day), explicit
 *    deep links stay usable, non-actionable manage controls are hidden, and
 *    the execute-only My Day checklist.
 *  - Member/child mobile viewport: My Day completion + checklist execution
 *    with 44px touch targets.
 *
 * Requires: Docker test environment with the `family` database profile.
 */

import type { Locator } from '@playwright/test'

import { AxeBuilder } from '@axe-core/playwright'
import { createUserContext } from './fixtures/auth-contexts'
import { completeRoomTaskViaApi, completeWithOptionalDialog } from './fixtures/completion-api'
import { expect, test } from './fixtures/database-fixtures'
import { holdToConfirm } from './fixtures/hold-to-confirm'
import { runOcc } from './fixtures/occ-runner'

test.describe('Family core UX (CS-PLAN-066)', () => {
	test.use({ databaseProfile: 'family' })

	test('real Nextcloud DE and EN core rendering uses the shipped translations', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		try {
			for (const [language, myDay, tasks] of [['de', 'Mein Tag', 'Aufgabenverwaltung'], ['en', 'My Day', 'Task Manager']]) {
				runOcc(`user:setting mom core lang ${language}`)
				const session = await createUserContext(browser, 'mom', { locale: language })
				try {
					await session.page.goto('/apps/chorestrike/')
					await expect(session.page.getByTestId('nav-today')).toContainText(myDay)
					await session.page.getByTestId('nav-tasks').click()
					await expect(session.page.getByTestId('task-manager-view').getByRole('heading', { level: 1 })).toHaveText(tasks)
					await session.page.getByTestId('tasks-filter-all').click()
					const row = session.page.getByTestId('task-item').filter({ hasText: 'Wipe kitchen counters' }).first()
					await row.getByRole('button', { name: language === 'de' ? 'Details anzeigen' : 'Show details', exact: true }).click()
					const handover = row.getByTestId('agreement-handover')
					await expect(handover).toContainText(language === 'de' ? 'Diesen Termin übergeben an' : 'Hand this occurrence to')
					await expect(handover.getByTestId('agreement-handover-submit')).toHaveText(language === 'de' ? 'Vereinbarte Übergabe festhalten' : 'Record agreed handover')
					await expect(handover).toContainText(language === 'de' ? 'Künftige Zuweisungen bleiben unverändert.' : 'Future assignments stay unchanged.')
				} finally {
					await session.context.close()
				}
			}
		} finally {
			runOcc('user:setting mom core lang en')
		}
	})

	/** House-local day key — the Docker test environment runs the house on UTC. */
	const utcDayKey = (offsetDays = 0): string => new Date(Date.now() + offsetDays * 24 * 60 * 60 * 1000).toISOString().slice(0, 10)

	test('core task filters show own and unassigned work with names and clickable appointments', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const structureResponse = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms')
			expect(structureResponse.ok()).toBe(true)
			const floors = (await structureResponse.json()).data
			const kitchenFloor = floors.find((entry) => entry.rooms.some((room) => room.name === 'Kitchen'))
			const kitchen = kitchenFloor.rooms.find((room) => room.name === 'Kitchen')
			for (const [name, assignees] of [['Own filter probe', ['mom']], ['Unassigned filter probe', []], ['Other filter probe', ['teen']]] as const) {
				const created = await page.request.post('/apps/chorestrike/api/v1/room-tasks', {
					data: { roomId: kitchen.id, assigneeUserIds: assignees, nextDue: utcDayKey(0), task: { name, energyCost: 1, frequencyType: 'daily', frequencyValue: 1 } },
				})
				expect(created.ok()).toBe(true)
			}
			for (const width of [390, 1440]) {
				await page.setViewportSize({ width, height: 900 })
				await page.goto('/apps/chorestrike/#/tasks')
				const own = page.getByTestId('task-item').filter({ hasText: 'Own filter probe' })
				const unassigned = page.getByTestId('task-item').filter({ hasText: 'Unassigned filter probe' })
				const other = page.getByTestId('task-item').filter({ hasText: 'Other filter probe' })
				await expect(own).toBeVisible()
				await expect(unassigned).toBeVisible()
				await expect(other).toHaveCount(0)
				await expect(own.getByTestId('task-assignees')).toContainText('Assigned to: Mom')
				await page.getByTestId('tasks-filter-unassigned').click()
				await expect(own).toHaveCount(0)
				await expect(unassigned).toBeVisible()
				// CS-PLAN-084 D-084-02: the filter panel starts collapsed on phones.
				const filterToggle = page.getByTestId('tasks-filter-toggle')
				await expect(filterToggle).toHaveAttribute('aria-expanded', width < 768 ? 'false' : 'true')
				if (width < 768) {
					await filterToggle.click()
				}
				await page.getByTestId('person-filter').selectOption('teen')
				await expect(other).toBeVisible()
				await expect(unassigned).toHaveCount(0)
				await page.getByTestId('floor-filter').selectOption(String(kitchenFloor.floor.id))
				await page.getByTestId('room-filter').selectOption(String(kitchen.id))
				await expect(other).toBeVisible()
				await page.getByTestId('tasks-filter-mine').focus()
				await page.keyboard.press('Enter')
				await expect(own).toBeVisible()
				await expect(other).toHaveCount(0)
				expect(await page.getByTestId('tasks-filter-mine').evaluate((el) => el.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44)
				await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
				await page.goto('/apps/chorestrike/#/today')
				const appointment = page.getByTestId('today-appointment').first().getByRole('link')
				await expect(appointment).toBeVisible()
				const appointmentName = await appointment.locator('.appointment-name').innerText()
				await appointment.focus()
				await page.keyboard.press('Enter')
				await expect(page.getByTestId('room-detail-view')).toBeVisible()
				await expect(page.getByTestId('task-item').filter({ hasText: appointmentName }).first()).toBeVisible()
			}
		} finally {
			await context.close()
		}
	})

	test('owner phone: the all-tasks list pages by 20 and "Completed" loads finished work on demand (CS-PLAN-084 WP-D)', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		test.setTimeout(180000)
		const { context, page } = await createUserContext(browser, 'mom', { viewport: { width: 390, height: 844 } })
		try {
			const structure = (await (await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms')).json()).data
			const kitchen = structure.flatMap((entry) => entry.rooms).find((room) => room.name === 'Kitchen')
			const createTask = async (name: string, extra: Record<string, unknown> = {}) => {
				const response = await page.request.post('/apps/chorestrike/api/v1/room-tasks', {
					data: { roomId: kitchen.id, assigneeUserIds: ['mom'], nextDue: utcDayKey(0), task: { name, energyCost: 1, frequencyType: 'daily', frequencyValue: 1, ...extra } },
				})
				expect(response.ok()).toBe(true)
				return (await response.json()).data.roomTask.id as number
			}
			// A finished one-time task (completion deactivates it) and a deleted one.
			const finishedId = await createTask('Finished once probe', { frequencyType: 'weekly', recurrenceMode: 'once' })
			expect((await completeRoomTaskViaApi(page, finishedId)).ok()).toBe(true)
			const deletedId = await createTask('Deleted probe')
			expect((await page.request.delete(`/apps/chorestrike/api/v1/room-tasks/${deletedId}`)).ok()).toBe(true)
			// At least 21 active household tasks → two pages.
			const active = (await (await page.request.get('/apps/chorestrike/api/v1/room-tasks')).json()).data.length
			for (let index = active; index < 21; index++) {
				await createTask(`Paging probe ${index}`)
			}
			const total = Math.max(active, 21)
			const pages = Math.ceil(total / 20)
			const secondPageRows = Math.min(20, total - 20)

			const roomTaskReads: string[] = []
			page.on('request', (request) => {
				const url = new URL(request.url())
				if (url.pathname.endsWith('/api/v1/room-tasks')) {
					roomTaskReads.push(url.searchParams.get('activeOnly') ?? '')
				}
			})
			await page.goto('/apps/chorestrike/#/tasks?scope=all')
			await expect(page.getByTestId('task-item')).toHaveCount(20)
			await expect(page.getByTestId('tasks-filter-toggle')).toHaveAttribute('aria-expanded', 'false')
			await expect(page.getByTestId('status-filter')).toBeHidden()
			await expect(page.getByTestId('task-pager-status')).toHaveText(`Page 1 of ${pages}`)

			await page.getByTestId('task-pager-next').click()
			await expect(page).toHaveURL(/[?&]page=2/)
			await expect(page.getByTestId('task-item')).toHaveCount(secondPageRows)
			await page.reload()
			await expect(page.getByTestId('task-pager-status')).toHaveText(`Page 2 of ${pages}`)
			await expect(page.getByTestId('task-item')).toHaveCount(secondPageRows)
			await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
			expect(roomTaskReads).toEqual(['true', 'true'])

			await page.getByTestId('tasks-filter-toggle').click()
			await page.getByTestId('status-filter').selectOption('completed')
			const finished = page.getByTestId('task-item').filter({ hasText: 'Finished once probe' })
			await expect(finished).toHaveClass(/completed/)
			await expect(page.getByTestId('task-item').filter({ hasText: 'Deleted probe' })).toHaveCount(0)
			await expect(page.locator('[data-testid="task-item"]:not(.completed)')).toHaveCount(0)
			await expect(page).not.toHaveURL(/[?&]page=/)
			await expect(page.getByTestId('tasks-filter-toggle')).toContainText('Filters (1 active)')
			expect(roomTaskReads).toEqual(['true', 'true', 'false'])
			const contrast = await new AxeBuilder({ page }).include('#content').withRules(['color-contrast']).analyze()
			expect(contrast.violations.flatMap((violation) => violation.nodes.map((node) => node.target.join(' ')))).toEqual([])
		} finally {
			await context.close()
		}
	})

	interface SeededShape {
		data: {
			tasks: Array<{ id: number, name: string }>
			roomTasks: Array<{ id: number, taskId: number, isActive: boolean }>
		}
	}

	const roomTaskByName = (seeded: SeededShape, name: string, activeOnly = true) => {
		const task = seeded.data.tasks.find((entry) => entry.name === name)!
		return seeded.data.roomTasks.find((roomTask) => roomTask.taskId === task.id && (!activeOnly || roomTask.isActive))!
	}

	test('owner desktop: cold Dashboard counters are correct without any room visit (D2)', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await page.goto('/apps/chorestrike/#/dashboard')
			await page.getByTestId('dashboard-view').waitFor({ state: 'visible' })

			// QuickStats counters render from the dedicated projections —
			// non-zero on a cold Dashboard (the seed holds due/overdue rows).
			await expect(page.getByTestId('quick-stats')).toContainText('Overdue')
			const dueToday = page.locator('.stat-card.due-today')
			await expect(dueToday).toBeVisible()
			await expect(dueToday).not.toContainText('0')
		} finally {
			await context.close()
		}
	})

	test('owner desktop: overview tiles lead to their targets and Quick clean needs a hold (CS-PLAN-084 WP-C)', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const kidsBedroom = seededData.data.rooms!.find((room) => room.name === 'Kids Bedroom')!
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await page.goto('/apps/chorestrike/#/dashboard')
			await page.getByTestId('dashboard-view').waitFor({ state: 'visible' })

			// Overdue tile → household task list filtered to overdue work.
			await page.getByTestId('overdue-stat-card').click()
			await expect(page.getByTestId('task-manager-view')).toBeVisible()
			await expect(page.getByTestId('status-filter')).toHaveValue('overdue')
			await expect(page.getByTestId('task-item').first()).toBeVisible()
			await expect(page.locator('[data-testid="task-item"]:not(.overdue)')).toHaveCount(0)

			// Average cleanliness tile → every floor expanded, list focused.
			await page.goto('/apps/chorestrike/#/dashboard')
			await page.getByTestId('stat-average-cleanliness').click()
			await expect(page.getByTestId('floor-list')).toBeFocused()
			const card = page.getByTestId('room-card-Kids Bedroom')
			await expect(card.getByTestId('cleanliness-value')).toHaveText(`${kidsBedroom.cleanliness}%`)

			// A short tap explains the hold and sends nothing; the hold cleans once.
			const cleanRequests: string[] = []
			page.on('request', (request) => {
				if (request.method() === 'PUT' && request.url().endsWith(`/rooms/${kidsBedroom.id}/cleanliness`)) {
					cleanRequests.push(request.url())
				}
			})
			const quickClean = page.getByTestId(`quick-clean-${kidsBedroom.id}`)
			const box = await quickClean.boundingBox()
			expect(box!.height).toBeGreaterThanOrEqual(44)
			await quickClean.click()
			await expect(page.getByTestId(`quick-clean-${kidsBedroom.id}-hint`)).toHaveText('Press and hold to confirm')
			expect(cleanRequests).toEqual([])
			await expect(page.getByTestId('dashboard-view')).toBeVisible()

			const cleaned = page.waitForResponse((response) => response.request().method() === 'PUT' && response.url().endsWith(`/rooms/${kidsBedroom.id}/cleanliness`))
			await holdToConfirm(quickClean)
			expect((await cleaned).ok()).toBe(true)
			await expect(card.getByTestId('cleanliness-value')).toHaveText(`${kidsBedroom.cleanliness + 10}%`)
			expect(cleanRequests).toHaveLength(1)
		} finally {
			await context.close()
		}
	})

	test('owner desktop: the real HTTP PUT /tasks/{id} edit roundtrip works with the production body shape (D6)', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await page.goto('/apps/chorestrike/#/dashboard')
			await page.getByTestId('dashboard-view').waitFor({ state: 'visible' })
			await page.getByTestId('nav-tasks').click()
			await page.getByTestId('task-manager-view').waitFor({ state: 'visible' })

			// Open the seeded dishes row's edit form.
			const row = page.locator('.task-item').filter({ hasText: 'Do the dishes' }).first()
			await row.locator('button[title="Edit"]').click()
			await page.getByTestId('task-name-input').waitFor({ state: 'visible' })

			const updatedName = 'Do the dishes properly'
			await page.getByTestId('task-name-input').fill(updatedName)

			// BUG-API-001 (D6): the client sends the edited fields as the
			// TOP-LEVEL JSON body — before the fix this request died with a
			// 500 (TypeError: array argument bound from a missing key).
			const putEdit = page.waitForResponse((response) => {
				const pathname = new URL(response.url()).pathname
				return response.request().method() === 'PUT'
					&& /^\/apps\/chorestrike\/api\/v1\/tasks\/\d+$/.test(pathname)
			})
			await page.getByTestId('save-task-button').click()
			const response = await putEdit
			expect(response.ok()).toBeTruthy()

			const body = await response.json()
			expect(body.data.name).toBe(updatedName)

			// The reconciled definition shows without any reload.
			await expect(page.locator('.task-item').filter({ hasText: updatedName }).first()).toBeVisible()
		} finally {
			await context.close()
		}
	})

	test('owner desktop: complete from My Day reconciles counters without reload and the queued undo restores them (D3/D10)', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const countersRoomTask = roomTaskByName(seededData, 'Wipe kitchen counters')

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await page.goto('/apps/chorestrike/#/dashboard')
			await page.getByTestId('dashboard-view').waitFor({ state: 'visible' })
			await page.getByTestId('nav-today').click()
			await page.getByTestId('today-view').waitFor({ state: 'visible' })

			// The counters row belongs to teen — switch to the shared view.
			await page.getByTestId('today-filter-all').click()

			// Complete teen's counters task (owner may complete any row) —
			// WP3 §3.6: the foreign-agreement proposal opens the
			// participation dialog; confirming it posts the explicit split.
			// The daily-recurring row leaves Today, and the coordinated D3
			// refresh updates the overdue/due counts in place.
			const completeResponse = await completeWithOptionalDialog(
				page,
				page.getByTestId(`today-complete-button-${countersRoomTask.id}`),
			)
			expect(completeResponse.ok()).toBeTruthy()
			// Daily recurring task: the occurrence advances to tomorrow —
			// that is what removes the row from Today (isActive stays true).
			const completedBody = await completeResponse.json()
			expect(completedBody.data.roomTask.isActive).toBe(true)
			expect(String(completedBody.data.roomTask.nextDue)).toContain(utcDayKey(1))

			// No reload happened (same page still shows My Day), the row is
			// reconciled away and the undo queue offers the restore.
			await expect(page.getByTestId(`today-complete-button-${countersRoomTask.id}`)).toHaveCount(0)
			await expect(page.getByTestId(`undo-snackbar-entry-${countersRoomTask.id}`)).toBeVisible()

			// Undo via the queue: the targeted correction request restores
			// the row and the derived counters refresh — still without a
			// reload (WP3 §3.4: POST /completions/{id}/undo).
			const putUndo = page.waitForResponse((response) => {
				const pathname = new URL(response.url()).pathname
				return response.request().method() === 'POST'
					&& /^\/apps\/chorestrike\/api\/v1\/completions\/\d+\/undo$/.test(pathname)
			})
			await page.getByTestId(`undo-snackbar-undo-button-${countersRoomTask.id}`).click()
			const undoResponse = await putUndo
			expect((await undoResponse.json()).data.undone).toBe(true)
			await expect(page.getByTestId(`today-complete-button-${countersRoomTask.id}`)).toBeVisible()
		} finally {
			await context.close()
		}
	})

	test('child: the initial bare root visit lands on My Day; deep links stay usable (D4)', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'teen')
		try {
			// Bare root visit: the child is redirected to the shared My Day.
			await page.goto('/apps/chorestrike/')
			await page.getByTestId('today-view').waitFor({ state: 'visible' })
			expect(new URL(page.url()).hash).toContain('/today')
			await expect(page.getByTestId('dashboard-view')).toHaveCount(0)

			// An explicit deep link keeps its target (hash-history URLs use
			// the plain `#/` form).
			await page.goto('/apps/chorestrike/#/calendar')
			await page.getByTestId('calendar-view').waitFor({ state: 'visible' })
		} finally {
			await context.close()
		}
	})

	test('child: My Day hides manage controls and executes the checklist read-only (D4)', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		// The owner adds one checklist item to teen's seeded counters task
		// via the TaskManager details editor (canEdit surface), so the child
		// has an execute-only checklist in My Day.
		const countersRoomTask = roomTaskByName(seededData, 'Wipe kitchen counters')
		const { context: ownerContext, page: ownerPage } = await createUserContext(browser, 'mom')
		const itemLabel = `Checklist regression ${Date.now()}`
		try {
			await ownerPage.goto('/apps/chorestrike/#/dashboard')
			await ownerPage.getByTestId('dashboard-view').waitFor({ state: 'visible' })
			await ownerPage.getByTestId('nav-tasks').click()
			await ownerPage.getByTestId('task-manager-view').waitFor({ state: 'visible' })

			await ownerPage.getByTestId('tasks-filter-all').click()
			const row = ownerPage.locator('.task-item').filter({ hasText: 'Wipe kitchen counters' }).first()
			await row.locator('button[title="Show details"]').click()
			await ownerPage.locator('[data-testid="task-checklist"]').waitFor({ state: 'visible' })
			await ownerPage.locator('.add-item-trigger').click()
			await ownerPage.getByTestId('checklist-new-item-input').fill(itemLabel)
			await ownerPage.getByTestId('checklist-add-item-button').click()
			await expect(ownerPage.locator('.checklist-item', { hasText: itemLabel })).toBeVisible()
		} finally {
			await ownerContext.close()
		}

		// The child sees the checklist toggle in My Day and can execute it —
		// but never the add/edit affordances.
		const { context, page } = await createUserContext(browser, 'teen')
		try {
			await page.goto('/apps/chorestrike/')
			await page.getByTestId('today-view').waitFor({ state: 'visible' })

			const row = page.getByTestId('today-task-item').filter({ hasText: 'Wipe kitchen counters' }).first()
			await expect(row).toBeVisible()

			// No non-actionable manage surfaces for children (§3.5).
			await expect(row.locator('button[title="Edit"]')).toHaveCount(0)
			await expect(row.locator('.task-select')).toHaveCount(0)

			// Traverse the actual tab order; programmatic focus would not prove reachability.
			const tabTo = async (control: Locator) => {
				for (let i = 0; i < 60; i++) {
					await page.keyboard.press('Tab')
					if (await control.evaluate((element) => element === document.activeElement)) {
						return
					}
				}
				throw new Error('Core control is unreachable by keyboard')
			}
			const toggle = page.getByTestId(`today-checklist-toggle-${countersRoomTask.id}`)
			await tabTo(toggle)
			await expect(toggle).toBeFocused()
			await page.keyboard.press('Enter')
			const item = page.locator('.checklist-item', { hasText: itemLabel })
			await expect(item).toBeVisible()
			await expect(page.getByTestId('checklist-add-item-button')).toHaveCount(0)

			await tabTo(item.getByRole('checkbox'))
			await page.keyboard.press('Space')
			await expect(item.locator('.item-content')).toHaveClass(/is-completed/)
			const complete = page.getByTestId(`today-complete-button-${countersRoomTask.id}`)
			await tabTo(complete)
			await expect(complete).toBeFocused()
			const response = page.waitForResponse((result) => result.request().method() === 'POST' && result.url().endsWith(`/${countersRoomTask.id}/complete`))
			// WP-E: a held Enter confirms (a single press only shows the hint).
			await holdToConfirm(complete, 'keyboard')
			expect((await response).ok()).toBe(true)
			await expect(complete).toHaveCount(0)
			await expect(page.getByTestId(`undo-snackbar-entry-${countersRoomTask.id}`)).toBeVisible()
		} finally {
			await context.close()
		}
	})

	test('member mobile: My Day completion and checklist execution work at the mobile viewport with 44px targets', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const countersRoomTask = roomTaskByName(seededData, 'Wipe kitchen counters')

		const { context, page } = await createUserContext(browser, 'mom', {
			viewport: { width: 390, height: 844 },
			hasTouch: true,
		})
		try {
			// WP-F haptics: record vibration calls (headless browsers do not vibrate).
			await page.addInitScript(() => {
				const calls: unknown[] = []
				Object.defineProperty(window, '__vibrations', { value: calls })
				Object.defineProperty(navigator, 'vibrate', {
					configurable: true,
					value: (pattern: unknown) => {
						calls.push(pattern); return true
					},
				})
			})
			// Direct hash navigation: the app navigation collapses behind the
			// mobile header at this width (scheduling-apply-flow precedent).
			await page.goto('/apps/chorestrike/#/today')
			await page.getByTestId('today-view').waitFor({ state: 'visible' })

			// Everyone filter: the counters row belongs to teen; the owner
			// completes it from the shared surface.
			await page.getByTestId('today-filter-all').click()
			const row = page.getByTestId('today-task-item').filter({ hasText: 'Wipe kitchen counters' }).first()
			await expect(row).toBeVisible()

			// Touch targets stay ≥ 44px tall at the mobile width (§3.4).
			const box = await page.getByTestId(`today-complete-button-${countersRoomTask.id}`).boundingBox()
			expect(box).not.toBeNull()
			expect(box!.height).toBeGreaterThanOrEqual(44)

			// WP-E: a short tap sends nothing and explains the hold gesture.
			const completeRequests: string[] = []
			page.on('request', (request) => {
				if (request.method() === 'POST' && request.url().endsWith('/complete')) {
					completeRequests.push(request.url())
				}
			})
			await page.getByTestId(`today-complete-button-${countersRoomTask.id}`).tap()
			await expect(page.getByTestId(`today-complete-button-${countersRoomTask.id}-hint`)).toHaveText('Press and hold to confirm')
			expect(completeRequests).toEqual([])

			// WP3 §3.6: the held Done opens the foreign-agreement participation
			// dialog — settle it (tap-friendly confirm).
			await holdToConfirm(page.getByTestId(`today-complete-button-${countersRoomTask.id}`))
			await expect(page.getByTestId('participation-dialog')).toBeVisible()
			const confirmBox = await page.getByTestId('participation-confirm').boundingBox()
			expect(confirmBox).not.toBeNull()
			expect(confirmBox!.height).toBeGreaterThanOrEqual(44)
			await page.getByTestId('participation-confirm').tap()
			await expect(page.getByTestId(`undo-snackbar-entry-${countersRoomTask.id}`)).toBeVisible()
			await expect(page.getByTestId(`today-complete-button-${countersRoomTask.id}`)).toHaveCount(0)
			// Always-on subtle feedback (D-084-03): one short pulse per completion, none for the short tap.
			expect(await page.evaluate(() => (window as unknown as { __vibrations: unknown[] }).__vibrations.length)).toBe(1)
		} finally {
			await context.close()
		}
	})
})
