import { expect, test } from '@playwright/test'
import { createUserContext } from './fixtures/auth-contexts.ts'

/**
 * Workflow request budgets over the real HTTP boundary (CS-PLAN-067 §3.5
 * D5): the TaskManager must load its instance data with exactly ONE
 * house-wide GET /api/v1/room-tasks request — never the per-room loop (an
 * N+1 of requests) the house batch read replaced, also when the list is
 * grouped by room or person. Today reads its own three projections once
 * each (the day, the overdue work and the bonus tasks of CS-PLAN-091 WP-B4)
 * and no instance list at all; its daily read carries the live suggestion
 * (WP-C, ADR-022). The calendar's week reads the range and the suggestion
 * once each per shown week.
 *
 * Read-only spec: it only navigates and counts XHRs, so it stays in the
 * default (non-serial) Playwright pool.
 */
test.describe('Workflow request budgets (CS-PLAN-067)', () => {
	test('TaskManager issues ONE house-wide room-task request for every grouping (CS-PLAN-090 WP-C)', async ({ browser }) => {
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

			// Grouping by room or person and opening a group filter what was
			// read; a group's rows render from the same house-wide list.
			for (const grouping of ['room', 'person']) {
				await page.getByTestId(`tasks-group-${grouping}`).click()
				const toggle = page.getByTestId('task-group-toggle').first()
				if (await toggle.getAttribute('aria-expanded') !== 'true') {
					await toggle.click()
				}
				await expect(toggle).toHaveAttribute('aria-expanded', 'true')
				await expect(page.getByTestId('room-task-list').first()).toBeVisible({ timeout: 15000 })
			}
			await page.waitForLoadState('networkidle')

			// The frozen budget: ONE active-only house-wide read, ZERO per-room reads.
			expect(roomTaskRequests).toEqual(['house:true'])
		} finally {
			await context.close()
		}
	})

	test('Today reads its three projections once each and no instance list (CS-PLAN-090 WP-B, CS-PLAN-091 WP-B4)', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const roomTaskRequests: string[] = []
			const schedulingRequests: string[] = []

			page.on('request', (request) => {
				const url = new URL(request.url())
				const match = url.pathname.match(/\/api\/v1\/room-tasks(\/.*)?$/)
				if (match && request.method() === 'GET') {
					roomTaskRequests.push(match[1] ?? `list:${url.searchParams.get('roomId') ?? 'house'}`)
				}
				const scheduling = url.pathname.match(/\/api\/v1\/scheduling(\/.*)$/)
				if (scheduling && request.method() === 'GET') {
					schedulingRequests.push(scheduling[1])
				}
			})

			await page.goto('/apps/chorestrike/#/today')
			await expect(page.getByTestId('today-view')).toBeVisible({ timeout: 30000 })
			await expect(page.getByTestId('today-status-line')).toBeVisible({ timeout: 15000 })
			// Showing everyone's rows is a filter on what was read, not a read.
			await page.getByTestId('today-filter-all').click()
			await page.waitForLoadState('networkidle')

			// The frozen budget: the day, the overdue work and the bonus tasks
			// (their own read, D-091-69) — no due-today count, no house-wide or
			// per-room list, no range read.
			expect([...roomTaskRequests].sort()).toEqual(['/bonus', '/filtered-daily', '/overdue'])
			// The daily read carries today's suggestion (ADR-022): Today asks
			// for no suggestion of its own, only for the viewer's energy.
			expect(schedulingRequests).toEqual(['/preferences'])
		} finally {
			await context.close()
		}
	})

	// Successor of the weekly plan's budget (CS-PLAN-091 WP-C): that page
	// preloaded every room task to add up the load in the browser; the
	// server places the work now.
	test('the calendar week reads the range and the suggestion once each, per shown week', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'dad')
		try {
			const requests: string[] = []

			page.on('request', (request) => {
				const url = new URL(request.url())
				const match = url.pathname.match(/\/api\/v1\/(room-tasks|scheduling)(\/.*)?$/)
				if (match && request.method() === 'GET') {
					requests.push(`${match[1]}${match[2] ?? ''}`)
				}
			})

			await page.goto('/apps/chorestrike/#/calendar')
			await expect(page.getByTestId('calendar-view')).toBeVisible({ timeout: 30000 })
			await expect(page.getByTestId('calendar-agenda')).toBeVisible({ timeout: 15000 })
			// Showing everyone's entries is a filter on what was read, not a read.
			await page.getByTestId('calendar-filter-all').click()
			await page.waitForLoadState('networkidle')

			// The frozen budget: one range read and one suggestion read — no
			// house-wide or per-room list, no preferences, no member read.
			expect([...requests].sort()).toEqual(['room-tasks/range', 'scheduling/suggestion'])

			requests.length = 0
			await page.getByTestId('calendar-next').click()
			await expect(page.getByTestId('calendar-agenda')).toBeVisible({ timeout: 15000 })
			await page.waitForLoadState('networkidle')
			expect([...requests].sort()).toEqual(['room-tasks/range', 'scheduling/suggestion'])

			// The month keeps the grid of the planned days: a range read, no suggestion.
			requests.length = 0
			await page.getByTestId('calendar-mode-month').click()
			await expect(page.getByTestId('task-calendar')).toBeVisible({ timeout: 15000 })
			await page.waitForLoadState('networkidle')
			expect(requests).toEqual(['room-tasks/range'])
		} finally {
			await context.close()
		}
	})
})
