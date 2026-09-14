/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Release E2E regression — Task Lifecycle (CS-PLAN-024, Phase 2; re-scoped
 * by CS-PLAN-055; re-pinned by CS-PLAN-064).
 *
 * The task-level skip/reschedule routes and the standalone deactivate route
 * were pruned in CS-PLAN-055 (no production consumer). This spec now pins
 * the surviving lifecycle surface:
 *  - due-date changes via `PUT /room-tasks/{id}/due-date` (the production
 *    path: RoomTaskEditModal persists due dates through it),
 *  - the completion-undo aggregate the UI performs: ONE
 *    `POST /room-tasks/{id}/undo` (roomTasks store
 *    `undoRoomTaskCompletion`; the former split `uncomplete` + `reactivate`
 *    chain was pruned by CS-PLAN-064, decision D5).
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
			expect(target, 'family seed must provide an active occurrence').not.toBeNull()

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

	test('completing then undoing restores the room task (one undo aggregate)', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const target = await pickActiveRoomTask(page)
			expect(target, 'family seed must provide an active occurrence').not.toBeNull()

			const completeResponse = await page.request.post(
				`/apps/chorestrike/api/v1/room-tasks/${target.id}/complete`,
				{ headers: { Accept: 'application/json' } },
			)
			expect(completeResponse.status()).toBe(200)
			const completed = (await completeResponse.json()).data
			expect(completed.roomTask).toBeTruthy()
			expect(completed.rewards).toBeTruthy()
			// Effect record (decision D10): the response carries the
			// completer's authoritative totals for owning-store reconcile.
			expect(Number.isFinite(completed.rewards.totalCoins)).toBe(true)

			// Undo is the ONE idempotent aggregate (CS-PLAN-064 D3/D5): it
			// restores the recorded before-state instead of only clearing
			// lastCompleted (the former split uncomplete+reactivate chain).
			const undoResponse = await page.request.post(
				`/apps/chorestrike/api/v1/room-tasks/${target.id}/undo`,
				{ headers: { Accept: 'application/json' } },
			)
			expect(undoResponse.status()).toBe(200)
			const undone = (await undoResponse.json()).data
			expect(undone.undone).toBe(true)
			expect(undone.roomTask.lastCompleted).toBeNull()
			expect(undone.roomTask.isActive).toBe(target.isActive)
			// Exact recurrence inverse: the pre-completion nextDue is back.
			expect(undone.roomTask.nextDue).toBe(target.nextDue)

			// Second undo: nothing revertible anymore → 200 no-op
			// (decision D3, zero writes).
			const noopResponse = await page.request.post(
				`/apps/chorestrike/api/v1/room-tasks/${target.id}/undo`,
				{ headers: { Accept: 'application/json' } },
			)
			expect(noopResponse.status()).toBe(200)
			expect((await noopResponse.json()).data.undone).toBe(false)
		} finally {
			await context.close()
		}
	})
})
