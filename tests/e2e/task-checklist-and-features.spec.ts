/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import type { Browser, BrowserContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { createUserContext } from './fixtures/auth-contexts.ts'
import { addSubTasks, chooseFormRoom, openEditForm, openNewTaskForm, openRowDetails } from './fixtures/task-form.ts'

/**
 * E2E tests for sub-tasks and the rhythm and template parts of the one form
 * (CS-PLAN-091 WP-B6, FEAT-UX-040).
 *
 * Sub-tasks are written in the form and ticked in the opened row. Each test
 * creates its own task with its own sub-tasks through the creation
 * aggregate (`checklistItems`) as the seeded owner `mom`, so no test
 * depends on what the pool's other specs left behind.
 */

interface OwnerTask {
	context: BrowserContext
	page: Page
	name: string
	roomTaskId: number
}

/**
 * Create a task with sub-tasks as `mom` and open the household task list on it.
 *
 * @param browser - The test's browser.
 * @param labels - The sub-tasks of the new task, in order.
 */
async function ownerTaskWithSubTasks(browser: Browser, labels: string[]): Promise<OwnerTask> {
	const { context, page } = await createUserContext(browser, 'mom')
	const name = `Sub-task probe ${Date.now()}`
	const floors = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms')
	expect(floors.status()).toBe(200)
	const roomId = ((await floors.json()).data as Array<{ rooms: Array<{ id: number }> }>).flatMap((floor) => floor.rooms)[0]?.id
	expect(roomId, 'the seed provides a room').toBeTruthy()
	const created = await page.request.post('/apps/chorestrike/api/v1/room-tasks', {
		data: { roomId, assigneeUserIds: ['mom'], checklistItems: labels.map((label) => ({ label })), task: { name, energyCost: 1, frequencyType: 'weekly', frequencyValue: 1 } },
	})
	expect(created.status(), await created.text()).toBe(200)
	const roomTaskId = (await created.json()).data.roomTask.id as number

	await page.goto('/apps/chorestrike/#/tasks?scope=all')
	await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })
	await page.getByTestId('search-input').fill(name)
	return { context, page, name, roomTaskId }
}

/**
 * The labels in the form's sub-task editor, in order.
 *
 * @param page - Page showing the form.
 */
async function editorLabels(page: Page): Promise<string[]> {
	return page.getByTestId('task-subtasks').getByTestId('task-subtask-label')
		.evaluateAll((inputs) => inputs.map((input) => (input as HTMLInputElement).value))
}

/**
 * The row of the probe task.
 *
 * @param owner - The probe task and its owner session.
 */
function rowOf(owner: OwnerTask): Locator {
	return owner.page.getByTestId('task-item').filter({ hasText: owner.name })
}

test.describe('Task sub-tasks', () => {
	test.beforeEach(() => {
		test.setTimeout(60000)
	})

	test('an opened row shows its sub-tasks with their progress', async ({ browser }) => {
		const owner = await ownerTaskWithSubTasks(browser, ['Clear the shelf', 'Wipe the shelf'])
		try {
			const details = await openRowDetails(rowOf(owner))
			const checklist = details.getByTestId('task-checklist')
			await expect(checklist).toBeVisible()
			await expect(checklist.locator('.checklist-item')).toHaveText(['Clear the shelf', 'Wipe the shelf'])
			await expect(checklist.locator('.progress-badge')).toHaveText('0/2')
		} finally {
			await owner.context.close()
		}
	})

	test('ticking a sub-task marks it done and strikes it through', async ({ browser }) => {
		const owner = await ownerTaskWithSubTasks(browser, ['Water the plants'])
		try {
			const details = await openRowDetails(rowOf(owner))
			const item = details.locator('.checklist-item', { hasText: 'Water the plants' })
			// The raw input is visually hidden behind the custom checkmark — click the label.
			const checkbox = item.locator('input[type="checkbox"]')
			await expect(checkbox).not.toBeChecked()
			const toggled = owner.page.waitForResponse((response) => response.url().includes(`/checklists/toggle/${owner.roomTaskId}/`))
			await item.locator('label.item-checkbox').click()
			expect((await toggled).status()).toBe(200)
			await expect(checkbox).toBeChecked({ timeout: 15000 })
			await expect(item.locator('.item-checkbox')).toHaveClass(/is-completed/)
			await expect(item.locator('.item-title')).toHaveCSS('text-decoration', /line-through/)
			await expect(details.locator('.progress-badge')).toHaveText('1/1')
		} finally {
			await owner.context.close()
		}
	})

	test('closing the row hides its sub-tasks', async ({ browser }) => {
		const owner = await ownerTaskWithSubTasks(browser, ['Sort the mail'])
		try {
			const row = rowOf(owner)
			const details = await openRowDetails(row)
			await expect(details.getByTestId('task-checklist')).toBeVisible()
			await expect(row.getByTestId('task-expand')).toHaveAttribute('aria-expanded', 'true')

			await row.getByTestId('task-expand').click()
			await expect(row.getByTestId('task-expand')).toHaveAttribute('aria-expanded', 'false')
			await expect(details).toHaveCount(0)
		} finally {
			await owner.context.close()
		}
	})

	test('the form adds, orders and removes sub-tasks in one save', async ({ browser }) => {
		const owner = await ownerTaskWithSubTasks(browser, ['First', 'Second'])
		try {
			const { page } = owner
			await openEditForm(page, rowOf(owner))
			const list = page.getByTestId('task-subtasks')
			await expect(list.getByTestId('task-subtask-label')).toHaveCount(2)
			await addSubTasks(page, ['Third'])
			// "Third" moves up before "Second", then "First" goes.
			await list.getByTestId('task-subtask-up').nth(1).click()
			await expect.poll(() => editorLabels(page)).toEqual(['First', 'Third', 'Second'])
			await list.getByTestId('task-subtask-remove').first().click()
			await expect.poll(() => editorLabels(page)).toEqual(['Third', 'Second'])

			const saved = page.waitForResponse((response) => response.request().method() === 'PUT'
				&& new URL(response.url()).pathname.endsWith(`/room-tasks/${owner.roomTaskId}`))
			await page.getByTestId('save-task-button').click()
			const response = await saved
			expect(response.status(), await response.text()).toBe(200)
			const sent = response.request().postDataJSON().checklistItems as Array<{ id?: number, label: string }>
			expect(sent.map((entry) => entry.label)).toEqual(['Third', 'Second'])
			expect(sent[0].id).toBeUndefined()
			expect(sent[1].id).toEqual(expect.any(Number))
			expect(((await response.json()).data.checklistItems as Array<{ label: string }>).map((item) => item.label)).toEqual(['Third', 'Second'])

			await expect(page.getByTestId('task-form')).toHaveCount(0)
			const details = rowOf(owner).locator('xpath=..').getByTestId('task-row-details')
			await expect(details.locator('.checklist-item')).toHaveText(['Third', 'Second'])
		} finally {
			await owner.context.close()
		}
	})
})

/**
 * E2E Tests for Group Assignment (Feature 5)
 */
test.describe('Group Assignment', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)

		// Navigate to app (assuming already logged in)
		await page.goto('/apps/chorestrike/#/today')
		await page.waitForLoadState('networkidle', { timeout: 30000 })
		await page.waitForSelector('[data-testid="today-view"]', { timeout: 20000 })
	})

	test('should show assigned status for tasks', async ({ page }) => {
		// Navigate to task manager
		await page.getByTestId('nav-tasks').click()
		await page.waitForSelector('[data-testid="task-manager-view"]')

		// The family seed guarantees assigned room tasks; the assigned
		// indicator is rendered for every task with at least one assignee.
		const assignedIndicators = page.locator('.meta-item.assigned')
		await expect(assignedIndicators.first()).toBeVisible({ timeout: 15000 })
		await expect(assignedIndicators.first()).toContainText(/assigned/i)
	})
})

/**
 * The rhythm of the one form (CS-PLAN-091 WP-B6): after completion, a fixed
 * day, or once.
 */
test.describe('Task rhythm in the form', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(60000)
		await page.goto('/apps/chorestrike/#/tasks')
		await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })
	})

	test('the form offers after completion, a fixed day and once', async ({ page }) => {
		await openNewTaskForm(page)
		await expect(page.getByTestId('task-rhythm-after')).toHaveText('After completion')
		await expect(page.getByTestId('task-rhythm-fixed')).toHaveText('Fixed day')
		await expect(page.getByTestId('task-rhythm-once')).toHaveText('Once')
		// A new task repeats weekly after completion.
		await expect(page.getByTestId('task-rhythm-after')).toHaveAttribute('aria-pressed', 'true')
		await expect(page.getByTestId('task-frequency-unit')).toHaveValue('week')
	})

	test('a fixed day asks for weekdays, a monthly one for the day of the month, once for neither', async ({ page }) => {
		await openNewTaskForm(page)
		await page.getByTestId('task-name-input').fill('Rhythm probe')
		await page.getByTestId('task-rhythm-fixed').click()
		await expect(page.getByTestId('task-weekday-monday')).toBeVisible()
		// Without a weekday the rhythm is incomplete and cannot be saved.
		await expect(page.getByTestId('task-rhythm-problem')).toBeVisible()
		await expect(page.getByTestId('save-task-button')).toBeDisabled()
		await page.getByTestId('task-weekday-monday').click()
		await expect(page.getByTestId('task-weekday-monday')).toHaveAttribute('aria-pressed', 'true')
		await expect(page.getByTestId('task-rhythm-problem')).toHaveCount(0)

		await page.getByTestId('task-frequency-unit').selectOption('month')
		await expect(page.getByTestId('task-weekday-monday')).toHaveCount(0)
		await expect(page.getByTestId('task-month-day')).toBeVisible()

		await page.getByTestId('task-rhythm-once').click()
		await expect(page.getByTestId('task-month-day')).toHaveCount(0)
		await expect(page.getByTestId('task-frequency-value')).toHaveCount(0)
	})
})

/**
 * A catalog template only fills the one form (FEAT-UX-043, CS-PLAN-091 WP-B6).
 */
test.describe('Template fill', () => {
	test('a template fills the form, and the save creates a task of its own', async ({ browser }) => {
		test.setTimeout(60000)
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await page.goto('/apps/chorestrike/#/tasks')
			await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })
			await openNewTaskForm(page)

			await page.getByTestId('task-template-open').click()
			await page.getByTestId('task-template-room-type').selectOption('kitchen')
			await page.getByTestId('task-template-kitchen_sink').click()
			await expect(page.getByTestId('task-template-kitchen_sink')).toHaveCount(0)
			await expect(page.getByTestId('task-name-input')).toHaveValue('Clean kitchen sink')
			await expect(page.getByTestId('task-energy-select')).toHaveValue('2')
			await expect(page.getByTestId('task-frequency-unit')).toHaveValue('week')

			const name = `Clean kitchen sink ${Date.now()}`
			await page.getByTestId('task-name-input').fill(name)
			await chooseFormRoom(page)
			const created = page.waitForResponse((response) => response.request().method() === 'POST'
				&& new URL(response.url()).pathname.endsWith('/api/v1/room-tasks'))
			await page.getByTestId('save-task-button').click()
			const response = await created
			expect(response.status(), await response.text()).toBe(200)
			expect((await response.json()).data.task).toMatchObject({ name, energyCost: 2, frequencyType: 'weekly', frequencyValue: 1 })
			await expect(page.getByTestId('task-form')).toHaveCount(0)
		} finally {
			await context.close()
		}
	})
})
