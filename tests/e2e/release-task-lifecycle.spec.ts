/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Release E2E regression — Task Lifecycle (CS-PLAN-024, Phase 2; re-scoped
 * by CS-PLAN-055; re-pinned by CS-PLAN-064).
 *
 * The task-level skip/reschedule routes and the standalone deactivate route
 * were pruned in CS-PLAN-055 (no production consumer); the due-date and
 * last-done routes and the occurrence agreement routes followed with the
 * derived cycles (ADR-021, CS-PLAN-091). This spec now pins the surviving
 * lifecycle surface:
 *  - the retired routes answer 404 (nothing replaces them until the single
 *    date form of a later work package),
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

import type { Page } from '@playwright/test'

import { createUserContext } from './fixtures/auth-contexts.ts'
import { completeRoomTaskViaApi } from './fixtures/completion-api.ts'
import { expect, test } from './fixtures/database-fixtures.ts'

interface RoomTaskJson {
	id: number
	nextDue: string | null
	cycle: { cycleKey?: string } | null
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
		const found = tasks.find((rt) => rt.isActive && rt.nextDue && rt.cycle && ((rt.assignees ?? []).length === 0 || rt.assignees!.includes('mom')))
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

	test('the retired date, last-done and occurrence routes answer 404', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const target = await pickActiveRoomTask(page)
			expect(target, 'family seed must provide an active cycle').not.toBeNull()
			const headers = { Accept: 'application/json' }
			const base = '/apps/chorestrike/api/v1'

			// ADR-021: a date moves through a later date form, never through
			// these routes; the stored timeline they amended is gone.
			expect((await page.request.put(`${base}/room-tasks/${target.id}/due-date`, { data: { nextDue: offsetDate(5) }, headers })).status()).toBe(404)
			expect((await page.request.put(`${base}/room-tasks/${target.id}/last-done`, { data: { lastDone: offsetDate(-1) }, headers })).status()).toBe(404)
			for (const action of ['amend', 'catch-up', 'restart']) {
				expect((await page.request.post(`${base}/occurrences/1:1/${action}`, { data: {}, headers })).status(), action).toBe(404)
			}

			// None of them moved the task: it still shows its one current cycle.
			const rows = await (await page.request.get(`${base}/room-tasks`)).json()
			const row = (rows.data as Array<{ id: number, nextDue: string | null }>).find((entry) => entry.id === target.id)
			expect(row?.nextDue).toBe(target.nextDue)
		} finally {
			await context.close()
		}
	})

	test('completing then undoing restores the room task (targeted correction)', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const target = await pickActiveRoomTask(page)
			expect(target, 'family seed must provide an active cycle').not.toBeNull()

			// When the row's assignees are several people or someone else, the
			// helper sends the explicit split naming the actor; mom's own and
			// unassigned rows stay participant-less.
			const completeResponse = await completeRoomTaskViaApi(page, target.id)
			expect(completeResponse.status()).toBe(200)
			const completed = (await completeResponse.json()).data
			expect(completed.roomTask).toBeTruthy()
			expect(completed.rewards).toBeTruthy()
			// Work evidence (WP3 §3.6): the response carries the frozen
			// evidence, the participants and the caller's OWN wallet.
			expect(Number.isFinite(completed.rewards.completion.totalEnergy)).toBe(true)
			expect(completed.rewards.wallet).toBeTruthy()

			// Undo is the ONE idempotent targeted correction (CS-PLAN-064
			// D3/D5 + WP3 §3.4: exact completion id + receipt) — it restores
			// the recorded before-state instead of only clearing
			// lastCompleted (the former split uncomplete+reactivate chain).
			const commandId = crypto.randomUUID()
			const undoResponse = await page.request.post(
				`/apps/chorestrike/api/v1/completions/${completed.completionId}/undo`,
				{ data: { commandId, expectedVersion: 0 }, headers: { Accept: 'application/json' } },
			)
			expect(undoResponse.status()).toBe(200)
			const undone = (await undoResponse.json()).data
			expect(undone.undone).toBe(true)
			expect(undone.roomTask.lastCompleted).toBeNull()
			expect(undone.roomTask.isActive).toBe(target.isActive)
			// Exact inverse: the same cycle is current again, so the projected
			// nextDue is back.
			expect(undone.roomTask.nextDue).toBe(target.nextDue)

			// Same command replay: the idempotent receipt hit returns the
			// ORIGINAL identity — `undone: true` (THIS command did undo it,
			// exactly once; §3.4 replay semantics, zero new writes).
			const replayResponse = await page.request.post(
				`/apps/chorestrike/api/v1/completions/${completed.completionId}/undo`,
				{ data: { commandId, expectedVersion: 0 }, headers: { Accept: 'application/json' } },
			)
			expect(replayResponse.status()).toBe(200)
			expect((await replayResponse.json()).data.undone).toBe(true)
		} finally {
			await context.close()
		}
	})
})
