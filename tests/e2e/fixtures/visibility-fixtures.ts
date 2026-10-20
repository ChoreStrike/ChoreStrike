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
 * CS-PLAN-068 §3.2: a failing precondition is a FAILURE, not a runtime
 * skip. The function throws a sanitized diagnostic (HTTP status or missing
 * room — no response bodies, tokens or stack internals) so the test fails
 * loudly with the actual gate instead of silently skipping (`test.skip` on
 * a false return could swallow a broken environment as "not applicable").
 */

import type { Page } from '@playwright/test'

export async function createVisibilityFixture(
	page: Page,
	name: string,
	visibilityType: string,
): Promise<void> {
	const api = page.request

	// /rooms requires a floorId; floors/with-rooms yields any room directly.
	const floors = await api.get('/apps/chorestrike/api/v1/floors/with-rooms', {
		headers: { Accept: 'application/json' },
	})
	if (floors.status() !== 200) {
		throw new Error(`visibility fixture precondition failed: GET floors/with-rooms answered ${floors.status()} (expected 200)`,)
	}
	const roomId = ((await floors.json()).data ?? [])
		.flatMap((entry: { rooms?: Array<{ id: number }> }) => entry.rooms ?? [])[0]?.id
	if (!roomId) {
		throw new Error('visibility fixture precondition failed: the seeded house exposes no room to attach the probe task to',)
	}

	// CS-PLAN-063: one atomic aggregate request (definition + room-task link).
	const aggregate = await api.post('/apps/chorestrike/api/v1/room-tasks', {
		data: { roomId, task: { name, energyCost: 5, visibilityType } },
		headers: { Accept: 'application/json' },
	})
	if (aggregate.status() !== 200) {
		throw new Error(`visibility fixture precondition failed: POST room-tasks answered ${aggregate.status()} (expected 200)`,)
	}
}
