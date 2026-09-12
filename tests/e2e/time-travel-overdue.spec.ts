/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * E2E: Time-Travel — overdue flow via seeded past due-date (CS-PLAN-023, E2E #1)
 *
 * The `family` seed includes a room task whose due-date is already three
 * days in the past ("Take out trash", assigned to dad). Against the real
 * clock it is immediately overdue — no multi-day wait, no clock shift.
 *
 * Verifies:
 * - the house overdue endpoint returns the seeded task
 * - the payload carries overdue semantics (negative daysUntilDue,
 *   status 'overdue')
 *
 * Requires: Docker test environment with the `family` database profile.
 * @see docs/plans/active/cs-plan-023-time-travel-testing.md §4
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'

interface OverdueRoomTask {
	id: number
	daysUntilDue?: number | null
	status?: string
	isActive?: boolean
}

/** CS-PLAN-066 (D1): overdue rows are the enriched `{roomTask, …}` shape. */
const asRoomTask = (row: Record<string, unknown>): OverdueRoomTask => {
	const roomTask = (row.roomTask ?? row) as OverdueRoomTask
	return {
		id: roomTask.id,
		daysUntilDue: roomTask.daysUntilDue,
		status: roomTask.status,
		isActive: roomTask.isActive,
	}
}

test.describe('Time-Travel: overdue via seeded past due-date', () => {
	test.use({ databaseProfile: 'family' })

	test('overdue endpoint returns the seeded overdue task', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		// The family seed's overdue task is assigned to dad (canonical
		// `assignees` key on the seed payload — CS-PLAN-060 WP1 D2).
		const dadTaskIds = (seededData!.data.roomTasks ?? [])
			.filter(rt => (rt.assignees ?? []).includes('dad'))
			.map(rt => rt.id)

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const response = await page.request.get('/apps/chorestrike/api/v1/room-tasks/overdue')
			expect(response.status()).toBe(200)

			const body = await response.json()
			expect(body.success).toBe(true)

			const overdueTasks: OverdueRoomTask[] = (body.data || []).map(asRoomTask)
			expect(overdueTasks.length).toBeGreaterThan(0)

			const seededOverdue = overdueTasks.find(task => dadTaskIds.includes(task.id))
			expect(seededOverdue).toBeDefined()
		} finally {
			await context.close()
		}
	})

	test('seeded overdue task carries overdue semantics against the real clock', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const dadTaskIds = (seededData!.data.roomTasks ?? [])
			.filter(rt => (rt.assignees ?? []).includes('dad'))
			.map(rt => rt.id)

		const { context, page } = await createUserContext(browser, 'dad')
		try {
			const response = await page.request.get('/apps/chorestrike/api/v1/room-tasks/overdue')
			expect(response.status()).toBe(200)

			const body = await response.json()
			const overdueTasks: OverdueRoomTask[] = (body.data || []).map(asRoomTask)

			const seededOverdue = overdueTasks.find(task => dadTaskIds.includes(task.id))
			expect(seededOverdue).toBeDefined()

			// Seeded three days ago → strictly past, still active.
			expect(seededOverdue!.isActive).toBe(true)
			expect(seededOverdue!.daysUntilDue).not.toBeNull()
			expect(seededOverdue!.daysUntilDue!).toBeLessThan(0)
			expect(seededOverdue!.status).toBe('overdue')
		} finally {
			await context.close()
		}
	})
})
