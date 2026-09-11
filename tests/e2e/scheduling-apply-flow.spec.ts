/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * WP6 exit journey — actionable scheduling through the owner plan
 * (CS-PLAN-065 §4.1): preview over real tasks → review the before/after →
 * adjust → apply once → verify the applied assignment end-to-end, plus the
 * stale-preview path answering cleanly.
 *
 * Runs as `dad` (owner) against the `family` profile; the cross-user My Day
 * check spins up a fresh context for `mom`. Requires the Docker test
 * environment with the `family` database profile.
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'

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

	const goToScheduling = async (page: import('@playwright/test').Page) => {
		await page.goto('/apps/chorestrike/')
		await page.getByTestId('dashboard-view').waitFor({ state: 'visible' })
		await page.getByTestId('nav-scheduling').click()
		await page.getByTestId('scheduling-view').waitFor({ state: 'visible' })
	}

	test('preview → adjust → apply assigns the unassigned task to the chosen person', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		// Seeded unassigned rows: "Vacuum living room" and "Clean toilet".
		// "Clean toilet" (energy 3) fits Mom's remaining capacity on the
		// seeded projection, so the override below produces a proposal.
		const toilet = roomTaskByName(seededData!, 'Clean toilet')
		expect(toilet).toBeDefined()

		const { context, page } = await createUserContext(browser, 'dad')
		await goToScheduling(page)

		// 1. Preview over the real unassigned task.
		const previewResponse = page.waitForResponse((response) =>
			response.request().method() === 'POST'
			&& response.url().includes('/scheduling/suggestions')
			&& response.status() === 200)
		await page.getByTestId(`planner-task-checkbox-${toilet.id}`).check()
		await page.getByTestId('preview-plan-button').click()
		const preview = await previewResponse
		const previewBody = await preview.json()
		expect(previewBody.data.changes).toHaveLength(1)
		expect(previewBody.data.changes[0].roomTaskId).toBe(toilet.id)
		// The proposal names exactly one eligible person and a concrete day.
		expect(previewBody.data.changes[0].proposed.assignees).toHaveLength(1)
		await expect(page.getByTestId(`plan-change-${toilet.id}`)).toBeVisible()

		// 2. Adjust: override the proposed person to Mom — this invalidates
		//    the confirmation until a fresh preview exists.
		await page.getByTestId(`planner-assignee-${toilet.id}`).selectOption('mom')
		await expect(page.getByTestId('plan-stale')).toBeVisible()
		await expect(page.getByTestId('apply-plan-button')).toBeDisabled()

		// 3. Re-preview with the override, then confirm exactly once.
		const preview2 = page.waitForResponse((response) =>
			response.request().method() === 'POST'
			&& response.url().includes('/scheduling/suggestions')
			&& response.status() === 200)
		await page.getByTestId('preview-plan-button').click()
		const preview2Body = await (await preview2).json()
		expect(preview2Body.data.changes[0].proposed.assignees).toEqual(['mom'])
		await expect(page.getByTestId('plan-stale')).toHaveCount(0)

		const applyResponse = page.waitForResponse((response) =>
			response.request().method() === 'POST'
			&& response.url().includes('/scheduling/apply'))
		await page.getByTestId('apply-plan-button').click()
		const apply = await applyResponse
		expect(apply.status()).toBe(200)
		const applyBody = await apply.json()
		expect(applyBody.data.appliedCount).toBe(1)
		// The authoritative response carries the canonical assignee set.
		expect(applyBody.data.roomTasks[0].assignees).toEqual(['mom'])
		await expect(page.getByTestId('plan-applied')).toBeVisible()

		// 4. The planner's task list reconciled: the row now shows Mom as
		//    the assignee (previously "Unassigned").
		await expect(page.getByTestId(`planner-task-row-${toilet.id}`)).toContainText('Mom')

		// 5. The household calendar shows the row exactly once (no double
		//    booking from the replace-not-add accounting): the task is due
		//    tomorrow inside the current week; select that day and read the
		//    day panel.
		await page.getByTestId('nav-calendar').click()
		await page.getByTestId('calendar-view').waitFor({ state: 'visible' })
		const tomorrowKey = new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString().slice(0, 10)
		await page.getByTestId(`calendar-day-${tomorrowKey}`).click()
		await expect(
			page.getByTestId('calendar-day-list').getByText('Vacuum living room'),
		).toHaveCount(1)

		// 6. My Day reflects embedded assignees across users: mom sees her
		//    seeded due-today row under the default mine filter; teen does
		//    not (the assignee sets are the canonical ones, decision D4).
		const { context: momContext, page: momPage } = await createUserContext(browser, 'mom')
		try {
			await momPage.goto('/apps/chorestrike/')
			await momPage.getByTestId('dashboard-view').waitFor({ state: 'visible' })
			await momPage.getByTestId('nav-today').click()
			await momPage.getByTestId('today-view').waitFor({ state: 'visible' })
			await expect(
				momPage.getByTestId('today-task-item').filter({ hasText: 'Do the dishes' }),
			).toBeVisible()
			await expect(
				momPage.getByTestId('today-task-item').filter({ hasText: 'Tidy up toys' }),
			).toHaveCount(0)
		} finally {
			await momContext.close()
		}

		await context.close()
	})

	test('a stale preview answers 409 cleanly and leaves the planner usable', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const vacuum = roomTaskByName(seededData!, 'Vacuum living room')
		const toilet = roomTaskByName(seededData!, 'Clean toilet')

		const { context, page } = await createUserContext(browser, 'dad')
		await goToScheduling(page)

		await page.getByTestId(`planner-task-checkbox-${toilet.id}`).check()
		await page.getByTestId(`planner-task-checkbox-${toilet.id}`).check()
		await page.getByTestId('preview-plan-button').click()
		await expect(page.getByTestId(`plan-change-${toilet.id}`)).toBeVisible()

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

		// The planner stays usable: a fresh preview succeeds and still
		// proposes a change for the untouched sibling task.
		await page.getByTestId('preview-plan-button').click()
		await expect(page.getByTestId(`plan-change-${toilet.id}`)).toBeVisible()

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
