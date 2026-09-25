/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * WP6 exit journey — actionable scheduling through the owner plan
 * (CS-PLAN-065 §4.1): the owner creates a real task THROUGH THE UI, sets the
 * child's availability and daily-task limit through the owner UI, previews →
 * adjusts → applies one batch, and a CHILD session verifies the exact
 * persisted result in My Day and the Calendar — all without a page reload.
 * Plus the role-cannot-apply matrix (member + child), the keyboard-operable
 * preview→apply cycle, the mobile touch journey and the stale-preview
 * recovery.
 *
 * Runs as `dad` (owner) against the `family` profile; cross-role checks spin
 * up fresh contexts (`kid`, `grandma`). Requires the Docker test environment
 * with the `family` database profile.
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import type { Page } from '@playwright/test'

/** House-local day key — the Docker test environment runs the house on UTC. */
const utcDayKey = (offsetDays = 0): string =>
	new Date(Date.now() + offsetDays * 24 * 60 * 60 * 1000).toISOString().slice(0, 10)

test.describe('Scheduling apply flow (owner plan)', () => {
	test.use({ databaseProfile: 'family' })

	interface SeededShape {
		data: {
			tasks: Array<{ id: number; name: string }>
			roomTasks: Array<{ id: number; taskId: number }>
		}
	}

	/** Resolve a room task by its task NAME from the seeded payload. */
	const roomTaskByName = (seeded: SeededShape, name: string) => {
		const task = seeded.data.tasks.find(entry => entry.name === name)!
		return seeded.data.roomTasks.find(roomTask => roomTask.taskId === task.id)!
	}

	const goToScheduling = async (page: Page) => {
		// One-off load flakes (CS-PLAN-068): retry the NAVIGATION against the
		// durable outcome instead of a single fixed wait.
		await expect(async () => {
			await page.goto('/apps/chorestrike/')
			// CS-PLAN-066 (D4): a child's bare-root visit lands on My Day,
			// owners/members on the Dashboard — both are valid entry points.
			await page.getByTestId('dashboard-view')
				.or(page.getByTestId('today-view'))
				.first()
				.waitFor({ state: 'visible' })
			await page.getByTestId('nav-scheduling').click()
			await page.getByTestId('scheduling-view').waitFor({ state: 'visible', timeout: 10000 })
		}).toPass({ timeout: 45000 })
	}

	const activeTestId = (page: Page) =>
		page.evaluate(() => document.activeElement?.getAttribute('data-testid') ?? '')

	/** Press Tab until the focus lands on the control with `testid`. */
	const tabUntil = async (page: Page, testid: string, maxTabs = 40) => {
		for (let i = 0; i <= maxTabs; i++) {
			if ((await activeTestId(page)) === testid) {
				return
			}
			await page.keyboard.press('Tab')
		}
		throw new Error(`Keyboard focus never reached [data-testid="${testid}"]`)
	}

	/**
	 * Create a real room task through the owner UI (task-manager creation
	 * form, CS-PLAN-063 aggregate) and return the new room-task id from the
	 * authoritative `{task, roomTask, assignees}` response.
	 */
	const createTaskViaUi = async (
		page: Page,
		name: string,
		dueKey: string,
		floorName: string,
		roomName: string,
	): Promise<number> => {
		await page.goto('/apps/chorestrike/#/tasks')
		await page.getByTestId('task-manager-view').waitFor({ state: 'visible' })
		await page.getByTestId('add-task-button').click()
		await page.getByTestId('task-name-input').waitFor({ state: 'visible' })
		await page.getByTestId('task-name-input').fill(name)
		await page.getByTestId('task-energy-select').selectOption('2')
		await page.getByTestId('task-priority-select').selectOption('2')
		await page.getByTestId('task-next-due-input').fill(dueKey)
		// Dynamic scheduling with flex 0 (form defaults) → the preview's only
		// legal day is the explicit first due date: fully deterministic.
		// Two-level picker (floor → rooms of that floor), as in gui-scratch-flow.
		const floorSelect = page.getByTestId('room-picker-floor')
		await expect(floorSelect).toBeVisible({ timeout: 15000 })
		await floorSelect.selectOption({ label: floorName })
		await page.getByTestId('room-picker-room').selectOption({ label: roomName })

		const createResponse = page.waitForResponse((response) => {
			const isPost = response.request().method() === 'POST'
			return isPost && new URL(response.url()).pathname.endsWith('/apps/chorestrike/api/v1/room-tasks')
		})
		await page.getByTestId('save-task-button').click()
		const created = await createResponse
		expect(created.ok()).toBeTruthy()
		const body = await created.json()
		const roomTaskId = body.data.roomTask.id as number
		expect(roomTaskId).toBeGreaterThan(0)
		await expect(page.getByTestId('task-name-input')).toHaveCount(0)
		return roomTaskId
	}

	test('exit journey: owner creates a task, sets the child limit, applies the plan, and the child session sees exactly the persisted result', async ({ browser, seededData }) => {
		test.setTimeout(150000)
		expect(seededData).not.toBeNull()
		const todayKey = utcDayKey(0)
		const taskName = 'Water the plants'

		const { context, page } = await createUserContext(browser, 'dad')

		// 1. The owner creates a REAL task through the UI — due today, in the
		//    Kitchen, unassigned (the plan §4.1 forbids seeding this row).
		const roomTaskId = await createTaskViaUi(page, taskName, todayKey, 'Ground Floor', 'Kitchen')

		await goToScheduling(page)

		// 2. Owner UI: make the child available every day and raise the daily
		//    task limit from the seeded 2 to 3 — without both, the planner
		//    may not place a third today-task on the child (D5/D6) and the
		//    child's My Day would stay empty on weekdays.
		const kidCard = page.getByTestId('availability-settings')
			.locator('.member-card')
			.filter({ hasText: 'Kid' })
		await expect(kidCard).toHaveCount(1)
		await kidCard.getByTestId('edit-availability-button').click()
		await expect(page.getByTestId('edit-availability-modal')).toBeVisible()
		for (const day of ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']) {
			const toggle = page.getByTestId(`availability-${day}`)
			if (!(await toggle.isChecked())) {
				await page.locator('label').filter({ has: toggle }).click()
			}
			await expect(toggle).toBeChecked()
		}
		await expect(page.getByTestId('limit-enabled-toggle')).toBeChecked()
		await expect(page.getByTestId('limit-value-input')).toHaveValue('2')
		await page.getByTestId('limit-value-input').fill('3')

		const limitPut = page.waitForResponse((response) =>
			response.request().method() === 'PUT'
			&& response.url().includes('/scheduling/members/kid'))
		await page.getByTestId('save-availability-button').click()
		const limitResponse = await limitPut
		expect(limitResponse.status()).toBe(200)
		const limitBody = JSON.parse(limitResponse.request().postData() ?? '{}')
		expect(limitBody.maxDailyTasks).toBe(3)
		expect(limitBody.weekdayAvailability).toEqual({
			monday: true,
			tuesday: true,
			wednesday: true,
			thursday: true,
			friday: true,
			saturday: true,
			sunday: true,
		})
		await expect(page.getByTestId('edit-availability-modal')).toHaveCount(0)
		await expect(kidCard.getByTestId('member-task-limit')).toContainText('max 3')

		// 3. Preview over the freshly created real task.
		const previewResponse = page.waitForResponse((response) =>
			response.request().method() === 'POST'
			&& response.url().includes('/scheduling/suggestions')
			&& response.status() === 200)
		await page.getByTestId(`planner-task-checkbox-${roomTaskId}`).check()
		await page.getByTestId('preview-plan-button').click()
		const previewBody = await (await previewResponse).json()
		expect(previewBody.data.changes).toHaveLength(1)
		expect(previewBody.data.changes[0].roomTaskId).toBe(roomTaskId)
		expect(previewBody.data.changes[0].before.assignees).toEqual([])
		expect(previewBody.data.changes[0].proposed.dueDate).toBe(todayKey)
		await expect(page.getByTestId(`plan-change-${roomTaskId}`)).toBeVisible()

		// 4. Adjust: override the proposed person to the child — this
		//    invalidates the confirmation until a fresh preview exists.
		await page.getByTestId(`planner-assignee-${roomTaskId}`).selectOption('kid')
		await expect(page.getByTestId('plan-stale')).toBeVisible()
		await expect(page.getByTestId('apply-plan-button')).toBeDisabled()

		// 5. Re-preview with the override, then confirm exactly once.
		const preview2 = page.waitForResponse((response) =>
			response.request().method() === 'POST'
			&& response.url().includes('/scheduling/suggestions')
			&& response.status() === 200)
		await page.getByTestId('preview-plan-button').click()
		const preview2Body = await (await preview2).json()
		expect(preview2Body.data.changes[0].proposed.assignees).toEqual(['kid'])
		expect(preview2Body.data.changes[0].proposed.dueDate).toBe(todayKey)
		await expect(page.getByTestId('plan-stale')).toHaveCount(0)

		// WP2 (RF-073-WP2-06/13): the exact share label rides the proposal
		// and apply stays gated on the owner's explicit consent attestation.
		await expect(page.getByTestId(`plan-change-shares-${roomTaskId}`)).toContainText('100%')
		await expect(page.getByTestId('apply-plan-button')).toBeDisabled()
		await expect(page.getByTestId('planner-consent-hint')).toBeVisible()
		await page.getByTestId('planner-consent').check()
		await expect(page.getByTestId('apply-plan-button')).toBeEnabled()

		const applyResponse = page.waitForResponse((response) =>
			response.request().method() === 'POST'
			&& response.url().includes('/scheduling/apply'))
		await page.getByTestId('apply-plan-button').click()
		const apply = await applyResponse
		expect(apply.status()).toBe(200)
		const applyRequestBody = JSON.parse(apply.request().postData() ?? '{}')
		expect(applyRequestBody.consent).toBe(true)
		const applyBody = await apply.json()
		expect(applyBody.data.appliedCount).toBe(1)
		expect(applyBody.data.roomTasks[0].id).toBe(roomTaskId)
		expect(applyBody.data.roomTasks[0].assignees).toEqual(['kid'])
		await expect(page.getByTestId('plan-applied')).toBeVisible()
		// The planner's task list reconciled from the authoritative response.
		await expect(page.getByTestId(`planner-task-row-${roomTaskId}`)).toContainText('Kid')

		// 6. The owner's household calendar shows the persisted occurrence
		//    exactly once — SPA navigation only, no page reload.
		await page.getByTestId('nav-calendar').click()
		await page.getByTestId('calendar-view').waitFor({ state: 'visible' })
		await page.getByTestId(`calendar-day-${todayKey}`).click()
		await expect(
			page.getByTestId('calendar-day-list').getByText(taskName),
		).toHaveCount(1)

		// 7. The CHILD session sees exactly the expected assigned due tasks
		//    in My Day: the applied task plus the two seeded kid rows, and
		//    nothing else (limit 3 set through the owner UI above; the
		//    seeded limit of 2 would have truncated this set).
		const { context: kidContext, page: kidPage } = await createUserContext(browser, 'kid')
		try {
			await kidPage.goto('/apps/chorestrike/')
			// CS-PLAN-066 (D4): the child lands directly on My Day.
			await kidPage.getByTestId('today-view').waitFor({ state: 'visible' })

			const todaySection = kidPage.locator('section.today-section')
				.filter({ has: kidPage.getByTestId('today-section-today') })
			const items = todaySection.getByTestId('today-task-item')
			await expect(items).toHaveCount(3)
			await expect(items.filter({ hasText: taskName })).toHaveCount(1)
			await expect(items.filter({ hasText: 'Tidy up toys' })).toHaveCount(1)
			await expect(items.filter({ hasText: 'Make bed' })).toHaveCount(1)

			// The child's calendar shows the applied occurrence exactly once.
			await kidPage.getByTestId('nav-calendar').click()
			await kidPage.getByTestId('calendar-view').waitFor({ state: 'visible' })
			await kidPage.getByTestId(`calendar-day-${todayKey}`).click()
			await expect(
				kidPage.getByTestId('calendar-day-list').getByText(taskName),
			).toHaveCount(1)
		} finally {
			await kidContext.close()
		}

		await context.close()
	})

	test('member and child roles see no planner and are rejected by both plan endpoints', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const toilet = roomTaskByName(seededData!, 'Clean toilet')
		const todayKey = utcDayKey(0)

		// grandma = member, kid = child: neither may plan for the household.
		for (const userId of ['grandma', 'kid']) {
			const { context, page } = await createUserContext(browser, userId)
			try {
				await goToScheduling(page)
				// The owner-only planner section never renders (§3.4).
				await expect(page.getByTestId('schedule-planner')).toHaveCount(0)
				// Personal settings remain available.
				await expect(page.getByTestId('self-availability-settings')).toBeVisible()

				// Direct API calls with well-formed bodies are forbidden —
				// the owner gate runs before any plan computation.
				const statuses = await page.evaluate(
					async ({ todayKey, roomTaskId }: { todayKey: string; roomTaskId: number }) => {
						const token = document.head.querySelector('meta[name="requesttoken"]')?.content
						const post = async (url: string, body: object) => {
							const response = await fetch(url, {
								method: 'POST',
								headers: {
									'Content-Type': 'application/json',
									requesttoken: String(token ?? ''),
								},
								body: JSON.stringify(body),
							})
							return response.status
						}
						const preview = await post('/apps/chorestrike/api/v1/scheduling/suggestions', {
							startDate: todayKey,
							roomTaskIds: [roomTaskId],
							overrides: {},
						})
						const apply = await post('/apps/chorestrike/api/v1/scheduling/apply', {
							startDate: todayKey,
							roomTaskIds: [roomTaskId],
							overrides: {},
							previewVersion: 'forged-version',
							commandId: 'c0ffee00-0000-4000-8000-000000000001',
							consent: true,
						})
						return { preview, apply }
					},
					{ todayKey, roomTaskId: toilet.id },
				)
				expect(statuses.preview).toBe(403)
				expect(statuses.apply).toBe(403)
			} finally {
				await context.close()
			}
		}
	})

	test('the preview → apply cycle is fully keyboard-operable with visible focus', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		// Seeded unassigned row: "Clean toilet" (energy 3, due tomorrow,
		// flex 0) — the preview always proposes exactly one change for it.
		const toilet = roomTaskByName(seededData!, 'Clean toilet')

		const { context, page } = await createUserContext(browser, 'dad')
		await goToScheduling(page)

		// Focus the task checkbox and prove the visible focus ring appears
		// on its wrapping label (:focus-within outline, §3.4).
		const checkbox = page.getByTestId(`planner-task-checkbox-${toilet.id}`)
		await checkbox.focus()
		expect(await activeTestId(page)).toBe(`planner-task-checkbox-${toilet.id}`)
		const outline = await page.evaluate((roomTaskId: number) => {
			const input = document.querySelector(`[data-testid="planner-task-checkbox-${roomTaskId}"]`)
			const label = input?.closest('label')
			if (!label) return null
			const style = getComputedStyle(label)
			return { width: style.outlineWidth, style: style.outlineStyle }
		}, toilet.id)
		expect(outline).toEqual({ width: '2px', style: 'solid' })

		// Space selects the task — no mouse anywhere in this journey.
		await page.keyboard.press('Space')
		await expect(checkbox).toBeChecked()

		await tabUntil(page, 'preview-plan-button')
		await page.keyboard.press('Enter')
		await expect(page.getByTestId(`plan-change-${toilet.id}`)).toBeVisible()

		// WP2: the consent attestation is a native checkbox — Space toggles
		// it and only then does apply become reachable/enabled.
		await tabUntil(page, 'planner-consent')
		await page.keyboard.press('Space')
		await expect(page.getByTestId('planner-consent')).toBeChecked()

		await tabUntil(page, 'apply-plan-button')
		const applyResponse = page.waitForResponse((response) =>
			response.request().method() === 'POST'
			&& response.url().includes('/scheduling/apply'))
		await page.keyboard.press('Enter')
		expect((await applyResponse).status()).toBe(200)
		await expect(page.getByTestId('plan-applied')).toBeVisible()

		await context.close()
	})

	test('the preview → apply cycle works on a mobile touch viewport with 44px targets', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const toilet = roomTaskByName(seededData!, 'Clean toilet')

		const { context, page } = await createUserContext(browser, 'dad', {
			viewport: { width: 390, height: 844 },
			hasTouch: true,
		})
		// Direct hash navigation: the app navigation collapses behind the
		// mobile header at this width.
		await page.goto('/apps/chorestrike/#/scheduling')
		await page.getByTestId('schedule-planner').waitFor({ state: 'visible' })

		// Touch targets are at least 44px tall at the mobile width (§3.4).
		const taskRow = page.getByTestId(`planner-task-row-${toilet.id}`)
		const taskLabel = taskRow.locator('label')
		await expect(taskLabel).toBeVisible()
		expect((await taskLabel.boundingBox())!.height).toBeGreaterThanOrEqual(44)
		const previewButton = page.getByTestId('preview-plan-button')
		expect((await previewButton.boundingBox())!.height).toBeGreaterThanOrEqual(44)

		// Tap-driven cycle: select → preview → apply.
		await taskLabel.tap()
		await expect(page.getByTestId(`planner-task-checkbox-${toilet.id}`)).toBeChecked()
		await previewButton.tap()
		await expect(page.getByTestId(`plan-change-${toilet.id}`)).toBeVisible()

		const assigneeSelect = page.getByTestId(`planner-assignee-${toilet.id}`)
		expect((await assigneeSelect.boundingBox())!.height).toBeGreaterThanOrEqual(44)

		// WP2: the consent attestation is a 44px touch target and gates apply.
		const consentLabel = page.locator('label.consent-label')
		expect((await consentLabel.boundingBox())!.height).toBeGreaterThanOrEqual(44)
		const applyButton = page.getByTestId('apply-plan-button')
		await expect(applyButton).toBeDisabled()
		await consentLabel.tap()
		await expect(page.getByTestId('planner-consent')).toBeChecked()
		await expect(applyButton).toBeEnabled()
		expect((await applyButton.boundingBox())!.height).toBeGreaterThanOrEqual(44)

		const applyResponse = page.waitForResponse((response) =>
			response.request().method() === 'POST'
			&& response.url().includes('/scheduling/apply'))
		await applyButton.tap()
		expect((await applyResponse).status()).toBe(200)
		await expect(page.getByTestId('plan-applied')).toBeVisible()

		await context.close()
	})

	test('a stale preview answers 409 cleanly and leaves the planner usable', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const vacuum = roomTaskByName(seededData!, 'Vacuum living room')
		const toilet = roomTaskByName(seededData!, 'Clean toilet')

		const { context, page } = await createUserContext(browser, 'dad')
		await goToScheduling(page)

		await page.getByTestId(`planner-task-checkbox-${toilet.id}`).check()
		await page.getByTestId('preview-plan-button').click()
		await expect(page.getByTestId(`plan-change-${toilet.id}`)).toBeVisible()
		await page.getByTestId('planner-consent').check()

		// External mutation behind the preview's back: someone else assigns
		// the vacuum task through the regular API.
		const assignStatus = await page.evaluate(async (roomTaskId: number) => {
			const token = document.head.querySelector('meta[name="requesttoken"]')?.content
			const response = await fetch(
				`/apps/chorestrike/api/v1/room-tasks/${roomTaskId}/assignees`,
				{
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: String(token ?? ''),
					},
					body: JSON.stringify({ userIds: ['teen'] }),
				},
			)
			return response.status
		}, vacuum.id)
		expect(assignStatus).toBe(200)

		// The confirmation must be rejected — no partial writes.
		const applyResponse = page.waitForResponse((response) =>
			response.request().method() === 'POST'
			&& response.url().includes('/scheduling/apply'))
		await page.getByTestId('apply-plan-button').click()
		expect((await applyResponse).status()).toBe(409)
		await expect(page.getByTestId('plan-stale')).toBeVisible()
		await expect(page.getByTestId('plan-applied')).toHaveCount(0)

		// RF-073-WP2-13: the draft is preserved after the 409 — the task
		// selection AND the recorded consent attestation survive the retry
		// window (the command UUID is retained store-side, pinned by the
		// scheduling store spec).
		await expect(page.getByTestId(`planner-task-checkbox-${toilet.id}`)).toBeChecked()
		await expect(page.getByTestId('planner-consent')).toBeChecked()

		// The planner stays usable: a fresh preview succeeds and still
		// proposes a change for the untouched sibling task — and a fresh
		// preview requires a FRESH consent attestation.
		await page.getByTestId('preview-plan-button').click()
		await expect(page.getByTestId(`plan-change-${toilet.id}`)).toBeVisible()
		await expect(page.getByTestId('planner-consent')).not.toBeChecked()
		await expect(page.getByTestId('apply-plan-button')).toBeDisabled()

		await context.close()
	})

	test('an apply without the explicit consent attestation is rejected 400', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const toilet = roomTaskByName(seededData!, 'Clean toilet')

		const { context, page } = await createUserContext(browser, 'dad')
		await goToScheduling(page)

		// The UI cannot produce this request (apply is gated on the
		// checkbox) — the API must still refuse a consent-less apply.
		const status = await page.evaluate(
			async ({ todayKey, roomTaskId }: { todayKey: string; roomTaskId: number }) => {
				const token = document.head.querySelector('meta[name="requesttoken"]')?.content
				const post = async (url: string, body: object) => {
					const response = await fetch(url, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: String(token ?? ''),
						},
						body: JSON.stringify(body),
					})
					return response
				}
				const preview = await post('/apps/chorestrike/api/v1/scheduling/suggestions', {
					startDate: todayKey,
					roomTaskIds: [roomTaskId],
					overrides: {},
				})
				const previewBody = await preview.json()
				const apply = await post('/apps/chorestrike/api/v1/scheduling/apply', {
					startDate: todayKey,
					roomTaskIds: [roomTaskId],
					overrides: {},
					previewVersion: previewBody.data.previewVersion,
					commandId: 'c0ffee00-0000-4000-8000-000000000002',
				})
				return apply.status
			},
			{ todayKey: utcDayKey(0), roomTaskId: toilet.id },
		)
		expect(status).toBe(400)

		await context.close()
	})

	test('an overdue zero-flex task is reported unplaced, never rescheduled', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		// "Take out trash" is seeded overdue (two days back) with flex 0 —
		// it has no legal day inside the window.
		const trash = roomTaskByName(seededData!, 'Take out trash')

		const { context, page } = await createUserContext(browser, 'dad')
		await goToScheduling(page)

		await page.getByTestId(`planner-task-checkbox-${trash.id}`).check()
		await page.getByTestId('preview-plan-button').click()
		await expect(page.getByTestId(`plan-unplaced-${trash.id}`)).toBeVisible()
		await expect(page.getByTestId('plan-unplaced')).toContainText('no legal day')
		await expect(page.getByTestId('apply-plan-button')).toBeDisabled()

		await context.close()
	})

	test('the retired taskCount advice contract is rejected without a fallback', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'dad')
		await page.goto('/apps/chorestrike/')
		await page.getByTestId('dashboard-view').waitFor({ state: 'visible' })

		const status = await page.evaluate(async () => {
			const token = document.head.querySelector('meta[name="requesttoken"]')?.content
			const response = await fetch('/apps/chorestrike/api/v1/scheduling/suggestions', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: String(token ?? ''),
				},
				body: JSON.stringify({ taskCount: 3 }),
			})
			return response.status
		})
		expect(status).toBe(400)

		await context.close()
	})
})
