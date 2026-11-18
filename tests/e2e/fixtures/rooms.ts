import type { Locator, Page } from '@playwright/test'

import { expect } from '@playwright/test'

/**
 * Ways into the app and into a room for specs that only pass through.
 * Today is the one landing page (CS-PLAN-090 WP-B). A room is a group of
 * the task list by room (WP-C); the room page and its address redirect
 * there.
 */

export interface RoomRef {
	id: number
	name: string
}

/**
 * Open Today, the landing page of every role, and wait for it.
 *
 * @param page - Page of a signed-in household member.
 */
export async function openToday(page: Page): Promise<void> {
	await page.goto('/apps/chorestrike/#/today')
	await expect(page.getByTestId('today-view')).toBeVisible({ timeout: 30000 })
}

/**
 * The rooms the signed-in member can see, in floor order. Read by the
 * page's own fetch: it carries the request token, which the request
 * context of a restored session does not have. Opens Today first when the
 * page is not in the app yet.
 *
 * @param page - Page of a signed-in household member.
 */
export async function listRooms(page: Page): Promise<RoomRef[]> {
	if (!page.url().includes('/apps/chorestrike/')) {
		await openToday(page)
	}
	const result = await page.evaluate(async () => {
		const response = await fetch('/apps/chorestrike/api/v1/floors/with-rooms', {
			headers: {
				Accept: 'application/json',
				requesttoken: (window as unknown as { OC: { requestToken: string } }).OC.requestToken,
			},
		})
		return { status: response.status, body: await response.json() }
	})
	expect(result.status).toBe(200)
	const floors = (result.body.data ?? []) as Array<{ rooms: RoomRef[] }>
	return floors.flatMap((entry) => entry.rooms.map((room) => ({ id: room.id, name: room.name })))
}

/**
 * Create a task in a room and return its name. Sent by the page's own
 * fetch, like `listRooms`. A spec that completes or edits a row creates its
 * own: the seed's rows are shared with the pool's other specs, and one
 * assigned to someone else asks who did the work first.
 *
 * @param page - Page of a signed-in member who may manage tasks, inside the app.
 * @param roomId - The room the task belongs to.
 * @param label - Start of the task name; a timestamp makes it unique.
 * @param options - `once` for a one-time task; `dueToday` so it can be completed at once.
 * @param options.once - Whether the task is a one-time task.
 * @param options.dueToday - Whether the task is due today instead of tomorrow.
 */
export async function createRoomTask(page: Page, roomId: number, label: string, { once = false, dueToday = false } = {}): Promise<string> {
	const name = `${label} ${Date.now()}`
	const status = await page.evaluate(async (payload) => {
		const response = await fetch('/apps/chorestrike/api/v1/room-tasks', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: (window as unknown as { OC: { requestToken: string } }).OC.requestToken,
			},
			body: JSON.stringify(payload),
		})
		return response.status
	}, {
		roomId,
		...(dueToday ? { firstDate: new Date().toISOString().slice(0, 10) } : {}),
		task: { name, energyCost: 1, frequencyType: 'weekly', frequencyValue: 1, ...(once ? { recurrenceMode: 'once' } : {}) },
	})
	expect(status).toBe(200)
	return name
}

/**
 * The group of a room in the task list by room: its heading with the
 * counts, the cleanliness and *New*, and its rows while it is open.
 *
 * @param page - Page showing the task list grouped by room.
 * @param room - The room, as `openRoom` or `listRooms` returned it.
 */
export function roomGroup(page: Page, room: RoomRef): Locator {
	return page.getByTestId(`task-group-${room.id}`)
}

/**
 * Wait until a room is open in the task list by room, with its rows read.
 *
 * @param page - Page showing the task list grouped by room.
 * @param room - The room that must be open.
 */
export async function expectRoomOpen(page: Page, room: RoomRef): Promise<void> {
	await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 30000 })
	const group = roomGroup(page, room)
	await expect(group.getByTestId('task-group-toggle')).toHaveAttribute('aria-expanded', 'true', { timeout: 30000 })
	await expect(group.getByTestId('task-group-name')).toHaveText(room.name)
	await expect(group.getByTestId('room-task-list')).toBeVisible({ timeout: 30000 })
}

/**
 * Open a room in the task list grouped by room and wait for its rows: the
 * room with the given name, or the first room of the house. Fails loudly
 * when the member sees no such room. Only this room's rows are rendered, so
 * page-wide row locators stay inside it.
 *
 * @param page - Page of a signed-in household member.
 * @param roomName - Exact room name; omitted for the first room.
 * @param options - `orFirst` falls back to the first room when the named
 * one does not exist (specs that run against whatever the seed holds).
 * @param options.orFirst - Whether to fall back to the first room.
 */
export async function openRoom(page: Page, roomName?: string, { orFirst = false } = {}): Promise<RoomRef> {
	const rooms = await listRooms(page)
	const named = rooms.find((candidate) => candidate.name === roomName)
	const room = roomName === undefined || (orFirst && !named) ? rooms[0] : named
	expect(room, `room ${roomName ?? '(first)'} among ${rooms.map((candidate) => candidate.name).join(', ')}`).toBeDefined()
	await page.goto(`/apps/chorestrike/#/tasks?group=room&room=${room.id}`)
	await expectRoomOpen(page, room)
	return room
}
