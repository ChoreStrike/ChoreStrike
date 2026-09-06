/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Shared visibility-probe fixture for the icon specs (CS-PLAN-051 D2):
 * TaskList rows are `{ task, roomTask }` pairs (`TaskList.vue`), so a task
 * definition only renders in #/tasks when linked to a room task. Creates the
 * definition plus link deterministically via the API — the same flow as the
 * insights spec — instead of relying on seed contents.
 *
 * Returns false on any non-200 step so callers can `test.skip` honestly
 * (`release-visibility-roles.spec.ts` precedent).
 */

import type { Page } from '@playwright/test'

export async function createVisibilityFixture(
	page: Page,
	name: string,
	visibilityType: string,
): Promise<boolean> {
	const api = page.request

	// /rooms requires a floorId; floors/with-rooms yields any room directly.
	const floors = await api.get('/apps/chorestrike/api/v1/floors/with-rooms', {
		headers: { Accept: 'application/json' },
	})
	if (floors.status() !== 200) {
		return false
	}
	const roomId = ((await floors.json()).data ?? [])
		.flatMap((entry: { rooms?: Array<{ id: number }> }) => entry.rooms ?? [])[0]?.id
	if (!roomId) {
		return false
	}

	// CS-PLAN-063: one atomic aggregate request (definition + room-task link).
	const aggregate = await api.post('/apps/chorestrike/api/v1/room-tasks', {
		data: { roomId, task: { name, energyCost: 5, visibilityType } },
		headers: { Accept: 'application/json' },
	})
	return aggregate.status() === 200
}
