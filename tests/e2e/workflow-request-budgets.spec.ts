import { expect, test } from '@playwright/test'
import { createUserContext } from './fixtures/auth-contexts'

/**
 * Workflow request budgets over the real HTTP boundary (CS-PLAN-067 §3.5
 * D5): the TaskManager and Scheduling views must load their instance data
 * with exactly ONE house-wide GET /api/v1/room-tasks request — never the
 * per-room loop (an N+1 of requests) the house batch read replaced.
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

	test('Dashboard loads the room-card task counts with ONE house-wide request (CS-PLAN-084 WP-C)', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const roomTaskRequests: string[] = []

			page.on('request', (request) => {
				const url = new URL(request.url())
				if (url.pathname.endsWith('/api/v1/room-tasks')) {
					roomTaskRequests.push(url.searchParams.get('roomId') ?? 'house')
				}
			})

			await page.goto('/apps/chorestrike/#/dashboard')
			await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 30000 })
			await expect(page.getByTestId('floor-list')).toBeVisible({ timeout: 15000 })
			// Revealing every room renders all room cards: still no per-room read.
			await page.getByRole('button', { name: /Total Rooms/ }).click()
			await expect(page.locator('.room-card').first()).toBeVisible()
			await page.waitForLoadState('networkidle')

			expect(roomTaskRequests).toEqual(['house'])
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
