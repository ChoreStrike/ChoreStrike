/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * The one task form and the one task row (CS-PLAN-091 WP-B6, FEAT-UX-040).
 *
 * A task is created and edited in one form: *Add task* (or *New* in a room
 * heading) opens it empty, *Edit* in an opened row opens it with the task.
 * Description, instructions, priority, category and visibility sit folded
 * under *More* (D-091-85). A row opens in place with its facts, sub-tasks,
 * actions and history. Direct-API specs save the form's edit aggregate
 * `PUT /room-tasks/{id}` with the receipt of the row's `cycle` block.
 */

import type { APIResponse, Locator, Page } from '@playwright/test'

import { expect } from '@playwright/test'
import { newCommandId, readFlatRow } from './completion-api.ts'

/**
 * Open the form of a new task with the task list's *Add task*.
 *
 * @param page - Page showing the task list, of a member who may manage tasks.
 */
export async function openNewTaskForm(page: Page): Promise<Locator> {
	await page.getByTestId('add-task-button').click()
	const form = page.getByTestId('task-form')
	await expect(form).toBeVisible({ timeout: 15000 })
	return form
}

/**
 * Choose the room of the open form: its one button opens the floors, a
 * floor opens its rooms, a room is the choice. Without names it takes the
 * first room of the first floor.
 *
 * @param page - Page showing the form.
 * @param names - The floor and the room to take, by their names.
 * @return Whether a room was there to choose.
 */
export async function chooseFormRoom(page: Page, names: { floor?: string, room?: string } = {}): Promise<boolean> {
	const picker = page.getByTestId('task-form').getByTestId('room-picker')
	await picker.getByTestId('room-picker-toggle').click()
	const floors = picker.locator('[data-testid^="room-picker-floor-"]')
	const floor = names.floor ? floors.filter({ hasText: names.floor }) : floors.first()
	if (await floor.count() === 0) {
		return false
	}
	if (await floor.getAttribute('aria-expanded') !== 'true') {
		await floor.click()
	}
	const rooms = picker.locator('[data-testid^="room-picker-room-"]')
	await (names.room ? rooms.filter({ hasText: names.room }) : rooms).first().click()
	await expect(picker.getByTestId('room-picker-toggle')).toHaveAttribute('aria-expanded', 'false')
	return true
}

/**
 * Unfold *More*: description, instructions, priority, category, visibility.
 *
 * @param page - Page showing the form.
 */
export async function openFormMore(page: Page): Promise<void> {
	const more = page.getByTestId('task-form-more')
	if (await more.getAttribute('open') === null) {
		await more.locator('summary').click()
	}
	await expect(more).toHaveAttribute('open', '')
}

/**
 * Step the flex days to a value with the form's − and + buttons.
 *
 * @param page - Page showing the form.
 * @param days - The flex days the form ends with.
 */
export async function setFlexDays(page: Page, days: number): Promise<void> {
	const value = page.getByTestId('task-flex-days')
	const current = Number(((await value.textContent()) ?? '').replace(/\D/g, '')) || 0
	const button = page.getByTestId(current < days ? 'task-flex-more' : 'task-flex-less')
	for (let step = 0; step < Math.abs(days - current); step++) {
		await button.click()
	}
	await expect(value).toHaveText(`± ${days}`)
}

/**
 * Add sub-tasks in the form, in this order.
 *
 * @param page - Page showing the form.
 * @param labels - The labels of the new sub-tasks.
 */
export async function addSubTasks(page: Page, labels: string[]): Promise<void> {
	const list = page.getByTestId('task-subtasks')
	const before = await list.getByTestId('task-subtask-label').count()
	for (const label of labels) {
		await list.getByTestId('task-subtask-new').fill(label)
		await list.getByTestId('task-subtask-add').click()
	}
	await expect(list.getByTestId('task-subtask-label')).toHaveCount(before + labels.length)
}

/**
 * Open a task row in place and return its details: the facts, the
 * sub-tasks, the actions (postpone, for someone else, edit, sleep) and the
 * history.
 *
 * @param row - The row (`task-item` in the task list, `today-task-item` on Today).
 */
export async function openRowDetails(row: Locator): Promise<Locator> {
	await row.getByTestId('task-expand').click()
	const details = row.locator('xpath=..').getByTestId('task-row-details')
	await expect(details).toBeVisible({ timeout: 15000 })
	return details
}

/**
 * Open the form of an existing task from its row.
 *
 * @param page - Page showing the row.
 * @param row - The row of the task.
 */
export async function openEditForm(page: Page, row: Locator): Promise<Locator> {
	const details = await openRowDetails(row)
	await details.getByTestId('task-edit-button').click()
	const form = page.getByTestId('task-form')
	await expect(form).toBeVisible({ timeout: 15000 })
	return form
}

/**
 * Save the form's edit aggregate through the API: a fresh command id, the
 * version of the row's `cycle` block and the changed form fields (`task`,
 * `roomId`, `assigneeUserIds`, `checklistItems`, `firstDate`, `sleeping`,
 * `wakeOn`, `scope`).
 *
 * @param page - Page of a member who may manage tasks; its request context carries the token.
 * @param roomTaskId - The room task to edit.
 * @param fields - The form fields of the save.
 */
export async function putRoomTask(page: Page, roomTaskId: number, fields: Record<string, unknown>): Promise<APIResponse> {
	const row = await readFlatRow(page, roomTaskId)
	expect(row?.cycle, `room task ${roomTaskId} is visible with a cycle block`).toBeTruthy()
	return page.request.put(`/apps/chorestrike/api/v1/room-tasks/${roomTaskId}`, {
		data: { commandId: newCommandId(), expectedVersion: row!.cycle!.expectedVersion, ...fields },
		headers: { Accept: 'application/json' },
	})
}
