/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Release E2E regression — Task Lifecycle (CS-PLAN-024, Phase 2; re-scoped
 * by CS-PLAN-055).
 *
 * The task-level skip/reschedule routes and the standalone deactivate route
 * were pruned in CS-PLAN-055 (no production consumer). This spec now pins
 * the surviving lifecycle surface:
 *  - due-date changes via `PUT /room-tasks/{id}/due-date` (the production
 *    path: RoomTaskEditModal persists due dates through it),
 *  - the completion-undo chain the UI performs: `PUT /tasks/{id}/uncomplete`
 *    + `POST /room-tasks/{id}/reactivate` (roomTasks store
 *    `undoRoomTaskCompletion`).
 * UI-level complete/create coverage stays with `task-manager` /
 * `task-completion(-full-flow)` (extend-first, CS-PLAN-024 §2); recurrence
 * stays with `today-view`.
 *
 * The assertions are API-driven (the deterministic data path) rather than
 * UI-driven, because these rows are mechanically repeatable state checks
 * (release-test-plan §1.1) — exactly what CS-024 §2 lifts from manual.
 *
 * Seed-mutating: registered in `SEED_DEPENDENT_SPECS` (chromium-serial-db).
 * Requires: Docker test environment with the `family` database profile.
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import type { Page } from '@playwright/test'

interface RoomTaskJson {
	id: number
	nextDue: string | null
	isActive: boolean
	lastCompleted?: string | null
	assignees?: string[]
}

/**
 * Fetch an active, due-dated room task the owner may operate on.
 *
 * `room_task#index` requires a `roomId` query param (it 400s without one),
 * so a roomId is resolved first via `floors/with-rooms` (the same pattern
 * `admin-only-tasks.spec.ts` uses). The pick is restricted to unassigned
 * tasks (or tasks assigned to the acting owner herself): since
 * CS-PLAN-056 the lifecycle mutations enforce the visibility matrix, so a
 * private task assigned to another member would 403 for the owner.
 *
 * @returns The chosen room task, or `null` when none is suitable.
 */
async function pickActiveRoomTask(page: Page): Promise<RoomTaskJson | null> {
	const floorsResponse = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms', {
		headers: { Accept: 'application/json' },
	})
	if (floorsResponse.status() !== 200) {
		return null
	}
	const floors = ((await floorsResponse.json()).data ?? []) as Array<{ rooms?: Array<{ id: number }> }>
	const roomIds = floors.flatMap((f) => f.rooms ?? []).map((r) => r.id)

	for (const roomId of roomIds) {
		const response = await page.request.get(
			`/apps/chorestrike/api/v1/room-tasks?roomId=${roomId}`,
			{ headers: { Accept: 'application/json' } },
		)
		if (response.status() !== 200) {
			continue
		}
		const tasks = ((await response.json()).data || []) as RoomTaskJson[]
		const found = tasks.find(
			(rt) => rt.isActive && rt.nextDue && ((rt.assignees ?? []).length === 0 || rt.assignees!.includes('mom')),
		)
		if (found) {
			return found
		}
	}
	return null
}

/** ISO date `offsetDays` from today, as the backend expects (YYYY-MM-DD). */
function offsetDate(offsetDays: number): string {
	const d = new Date()
	d.setDate(d.getDate() + offsetDays)
	return d.toISOString().slice(0, 10)
}

test.describe('Release: Task Lifecycle', () => {
	test.use({ databaseProfile: 'family' })

	test('updating the due date persists the requested day', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const target = await pickActiveRoomTask(page)
			if (!target) {
				test.skip(true, 'No active room task with a due date available')
				return
			}

			const requested = offsetDate(5)

			const response = await page.request.put(
				`/apps/chorestrike/api/v1/room-tasks/${target.id}/due-date`,
				{ data: { nextDue: requested }, headers: { Accept: 'application/json' } },
			)

			expect(response.status()).toBe(200)
			const body = await response.json()
			expect(body.success).toBe(true)
			// The serialized nextDue is ISO-8601; its calendar day matches.
			expect(String(body.data.nextDue).slice(0, 10)).toBe(requested)
		} finally {
			await context.close()
		}
	})

	test('completing then undoing restores the room task (uncomplete + reactivate)', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const target = await pickActiveRoomTask(page)
			if (!target) {
				test.skip(true, 'No active room task with a due date available')
				return
			}

			const completeResponse = await page.request.post(
				`/apps/chorestrike/api/v1/room-tasks/${target.id}/complete`,
				{ headers: { Accept: 'application/json' } },
			)
			expect(completeResponse.status()).toBe(200)
			expect((await completeResponse.json()).data.roomTask).toBeTruthy()

			// Undo primitive 1: clears lastCompleted (lives under /tasks/{id}
			// but operates on the room task id — TaskController::uncomplete).
			const uncompleteResponse = await page.request.put(
				`/apps/chorestrike/api/v1/tasks/${target.id}/uncomplete`,
				{ headers: { Accept: 'application/json' } },
			)
			expect(uncompleteResponse.status()).toBe(200)
			expect((await uncompleteResponse.json()).data.lastCompleted).toBeNull()

			// Undo primitive 2: reactivates one-time tasks (mirrors the
			// conditional branch of undoRoomTaskCompletion; idempotent on an
			// already-active recurrence task).
			const reactivateResponse = await page.request.post(
				`/apps/chorestrike/api/v1/room-tasks/${target.id}/reactivate`,
				{ headers: { Accept: 'application/json' } },
			)
			expect(reactivateResponse.status()).toBe(200)
			expect((await reactivateResponse.json()).data.isActive).toBe(true)
		} finally {
			await context.close()
		}
	})
})
