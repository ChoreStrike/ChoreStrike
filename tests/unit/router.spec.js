/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * CS-PLAN-070 WP6 (C5f, Q-077-01 Option A): the all-age My Day landing.
 * A route-level redirect sends EVERY unqualified root visit to the shared
 * My Day surface; the Dashboard stays reachable as the secondary household
 * overview on its explicit /dashboard path. The retired child-only
 * `landingRouteForRole` helper is gone — this spec pins the new route
 * table instead.
 */

jest.mock('@nextcloud/router', () => ({
	generateUrl: (url) => url,
}))

jest.mock('../../src/views/Dashboard.vue', () => ({ default: {} }))
jest.mock('../../src/views/TodayView.vue', () => ({ default: {} }))
jest.mock('../../src/views/TaskManager.vue', () => ({ default: {} }))
jest.mock('../../src/views/RoomDetail.vue', () => ({ default: {} }))
jest.mock('../../src/views/Leaderboard.vue', () => ({ default: {} }))
jest.mock('../../src/views/InsightsView.vue', () => ({ default: {} }))
jest.mock('../../src/views/ArcadeView.vue', () => ({ default: {} }))
jest.mock('../../src/views/SchedulingView.vue', () => ({ default: {} }))
jest.mock('../../src/views/CalendarView.vue', () => ({ default: {} }))
jest.mock('../../src/views/Settings.vue', () => ({ default: {} }))

const { routes, default: router } = require('../../src/router.js')
describe('router routes (CS-PLAN-070 WP6)', () => {
	it('redirects the bare root to My Day for every role (C5f)', () => {
		const root = routes.find(route => route.path === '/')
		expect(root.redirect).toEqual({ name: 'today' })
	})

	it('keeps the Dashboard reachable on its explicit secondary path (Q-077-01 A)', () => {
		const dashboard = routes.find(route => route.name === 'dashboard')
		expect(dashboard.path).toBe('/dashboard')
		expect(dashboard.component).toBeDefined()
	})

	it('exposes the gated arcade surface on its own path (Q-077-05 A)', () => {
		const arcade = routes.find(route => route.name === 'arcade')
		expect(arcade.path).toBe('/arcade')
	})

	it('resolves the today route by name', () => {
		const today = routes.find(route => route.name === 'today')
		expect(today.path).toBe('/today')
	})

	it('keeps the router instance installable', () => {
		expect(typeof router.push).toBe('function')
	})
})
