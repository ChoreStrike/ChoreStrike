import { expect, test } from '@playwright/test'
import { createUserContext } from './fixtures/auth-contexts.ts'

/**
 * Workflow request budgets over the real HTTP boundary (CS-PLAN-067 §3.5
 * D5): the TaskManager and Scheduling views must load their instance data
 * with exactly ONE house-wide GET /api/v1/room-tasks request — never the
 * per-room loop (an N+1 of requests) the house batch read replaced. Today
 * reads its own three projections once each and no instance list at all.
 *
 * Read-only spec: it only navigates and counts XHRs, so it stays in the
 * default (non-serial) Playwright pool.
 */
test.describe('Workflow request budgets (CS-PLAN-067)', () => {
	test('TaskManager initialization issues ONE house-wide room-task request', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const roomTaskRequests: string[] = []

			page.on('request', (request) => {
				const url = new URL(request.url())
				if (url.pathname.endsWith('/api/v1/room-tasks')) {
					// Record the effective selector mode: house-wide (no roomId)
					// vs. the per-room loop shape, plus the active-only flag
					// (the finished-task read is on demand only, D-084-07).
					roomTaskRequests.push(`${url.searchParams.get('roomId') ?? 'house'}:${url.searchParams.get('activeOnly')}`)
				}
			})

			await page.goto('/apps/chorestrike/#/tasks')
			await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 30000 })
			// Wait for the instance list to actually render.
			await expect(page.getByTestId('room-task-list')).toBeVisible({ timeout: 15000 })
			// Give late XHRs a beat to be recorded.
			await page.waitForLoadState('networkidle')

			// The frozen budget: ONE active-only house-wide read, ZERO per-room reads.
			expect(roomTaskRequests).toEqual(['house:true'])
		} finally {
			await context.close()
		}
	})

	test('Today reads its three projections once each and no instance list (CS-PLAN-090 WP-B)', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const roomTaskRequests: string[] = []

			page.on('request', (request) => {
				const url = new URL(request.url())
				const match = url.pathname.match(/\/api\/v1\/room-tasks(\/.*)?$/)
				if (match && request.method() === 'GET') {
					roomTaskRequests.push(match[1] ?? `list:${url.searchParams.get('roomId') ?? 'house'}`)
				}
			})

			await page.goto('/apps/chorestrike/#/today')
			await expect(page.getByTestId('today-view')).toBeVisible({ timeout: 30000 })
			await expect(page.getByTestId('today-status-line')).toBeVisible({ timeout: 15000 })
			// Showing everyone's rows is a filter on what was read, not a read.
			await page.getByTestId('today-filter-all').click()
			await page.waitForLoadState('networkidle')

			// The frozen budget: the day, the overdue work, the counters —
			// no house-wide or per-room list, no range read for appointments.
			expect([...roomTaskRequests].sort()).toEqual(['/due-today', '/filtered-daily', '/overdue'])
		} finally {
			await context.close()
		}
	})

	test('SchedulingView preloads its planning candidates with ONE house-wide request', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'dad')
		try {
			const roomTaskRequests: string[] = []

			page.on('request', (request) => {
				const url = new URL(request.url())
				if (url.pathname.endsWith('/api/v1/room-tasks')) {
					roomTaskRequests.push(url.searchParams.get('roomId') ?? 'house')
				}
			})

			await page.goto('/apps/chorestrike/#/scheduling')
			await expect(page.getByTestId('scheduling-view')).toBeVisible({ timeout: 30000 })
			await page.waitForLoadState('networkidle')

			// The overcapacity-warning preload is the single batched read.
			expect(roomTaskRequests).toEqual(['house'])
		} finally {
			await context.close()
		}
	})
})
