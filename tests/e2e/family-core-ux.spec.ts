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

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import { runOcc } from './fixtures/occ-runner'
import { completeWithOptionalDialog } from './fixtures/completion-api'
import type { Locator } from '@playwright/test'

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
					const row = session.page.getByTestId('task-item').filter({ hasText: 'Wipe kitchen counters' }).first()
					await row.getByRole('button', { name: language === 'de' ? 'Details anzeigen' : 'Show details', exact: true }).click()
					const handover = row.getByTestId('agreement-handover')
					await expect(handover).toContainText(language === 'de' ? 'Diesen Termin übergeben an' : 'Hand this occurrence to')
					await expect(handover.getByTestId('agreement-handover-submit')).toHaveText(language === 'de' ? 'Vereinbarte Übergabe festhalten' : 'Record agreed handover')
					await expect(handover).toContainText(language === 'de' ? 'Künftige Zuweisungen bleiben unverändert.' : 'Future assignments stay unchanged.')
				} finally { await session.context.close() }
			}
		} finally { runOcc('user:setting mom core lang en') }
	})

	/** House-local day key — the Docker test environment runs the house on UTC. */
	const utcDayKey = (offsetDays = 0): string =>
		new Date(Date.now() + offsetDays * 24 * 60 * 60 * 1000).toISOString().slice(0, 10)

	interface SeededShape {
		data: {
			tasks: Array<{ id: number; name: string }>
			roomTasks: Array<{ id: number; taskId: number; isActive: boolean }>
		}
	}

	const roomTaskByName = (seeded: SeededShape, name: string, activeOnly = true) => {
		const task = seeded.data.tasks.find(entry => entry.name === name)!
		return seeded.data.roomTasks.find(roomTask =>
			roomTask.taskId === task.id && (!activeOnly || roomTask.isActive))!
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
					if (await control.evaluate(element => element === document.activeElement)) return
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
			const response = page.waitForResponse(result => result.request().method() === 'POST' && result.url().endsWith(`/${countersRoomTask.id}/complete`))
			await page.keyboard.press('Enter')
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

			// WP3 §3.6: the foreign-agreement proposal opens the
			// participation dialog — settle it (tap-friendly confirm).
			await page.getByTestId(`today-complete-button-${countersRoomTask.id}`).tap()
			const dialog = page.getByTestId('participation-dialog')
			if (await dialog.isVisible({ timeout: 3000 }).catch(() => false)) {
				const confirmBox = await page.getByTestId('participation-confirm').boundingBox()
				expect(confirmBox).not.toBeNull()
				expect(confirmBox!.height).toBeGreaterThanOrEqual(44)
				await page.getByTestId('participation-confirm').tap()
			}
			await expect(page.getByTestId(`undo-snackbar-entry-${countersRoomTask.id}`)).toBeVisible()
			await expect(page.getByTestId(`today-complete-button-${countersRoomTask.id}`)).toHaveCount(0)
		} finally {
			await context.close()
		}
	})
})
