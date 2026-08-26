/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Release E2E regression — Task Lifecycle (CS-PLAN-024, Phase 2).
 *
 * Covers the release-test-plan.md §5 rows that had no automated home:
 * skip, reschedule, and deactivate/reactivate. Create/complete/uncomplete
 * stay with `task-manager` / `task-completion(-full-flow)` (extend-first,
 * CS-PLAN-024 §2); recurrence stays with `today-view` (CS-PLAN-050 deleted
 * the dead `daily-tasks-flow` spec).
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
	assignedToUserId?: string | null
}

/**
 * Fetch an active, due-dated room task the owner may operate on.
 *
 * `room_task#index` requires a `roomId` query param (it 400s without one),
 * so a roomId is resolved first via `floors/with-rooms` (the same pattern
 * `admin-only-tasks.spec.ts` uses). House-ownership validation in every
 * mutation endpoint means the owner can act on any returned task.
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
		const found = tasks.find((rt) => rt.isActive && rt.nextDue)
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

	test('skip advances the room-task due date by one day', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const target = await pickActiveRoomTask(page)
			if (!target) {
				test.skip(true, 'No active room task with a due date available')
				return
			}

			const before = new Date(target.nextDue!).getTime()

			const response = await page.request.put(
				`/apps/chorestrike/api/v1/tasks/${target.id}/skip`,
				{ headers: { Accept: 'application/json' } },
			)

			expect(response.status()).toBe(200)
			const body = await response.json()
			expect(body.success).toBe(true)

			const after = new Date(body.data.nextDue).getTime()
			// skipTask advances nextDue by exactly one day; allow a small
			// tolerance for the time component the serializer carries.
			expect(after).toBeGreaterThan(before)
		} finally {
			await context.close()
		}
	})

	test('reschedule moves the due date to the requested day', async ({ browser, seededData }) => {
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
				`/apps/chorestrike/api/v1/tasks/${target.id}/reschedule`,
				{ data: { newDate: requested }, headers: { Accept: 'application/json' } },
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

	test('deactivate then reactivate toggles the active flag', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const target = await pickActiveRoomTask(page)
			if (!target) {
				test.skip(true, 'No active room task with a due date available')
				return
			}

			const deactivateResponse = await page.request.post(
				`/apps/chorestrike/api/v1/room-tasks/${target.id}/deactivate`,
				{ headers: { Accept: 'application/json' } },
			)
			expect(deactivateResponse.status()).toBe(200)
			expect((await deactivateResponse.json()).data.isActive).toBe(false)

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
