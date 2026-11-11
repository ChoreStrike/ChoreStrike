import type { Page } from '@playwright/test'

import { expect } from '@playwright/test'

/**
 * Ways into the app and into a room for specs that only pass through
 * (CS-PLAN-090 WP-B). Today is the one landing page. The Dashboard and its
 * room cards are gone; until the tasks are grouped by room (WP-C) a room
 * page is opened by its address, and this helper is the one place that
 * changes then.
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
 * Open a room's page and wait for it: the room with the given name, or the
 * first room of the house. Fails loudly when the member sees no such room.
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
	await page.goto(`/apps/chorestrike/#/room/${room.id}`)
	await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 30000 })
	await expect(page.getByTestId('room-name')).toHaveText(room.name)
	return room
}
