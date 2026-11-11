/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * CS-PLAN-070 WP6 (C5f, Q-077-01 Option A): the all-age landing. A
 * route-level redirect sends EVERY unqualified root visit to Today. The
 * Dashboard merged into Today (CS-PLAN-090 WP-B); its address redirects,
 * so bookmarks keep working. This spec pins the route table.
 */

jest.mock('@nextcloud/router', () => ({
	generateUrl: (url) => url,
}))

jest.mock('../../src/views/TodayView.vue', () => ({ default: {} }))
jest.mock('../../src/views/TaskManager.vue', () => ({ default: {} }))
jest.mock('../../src/views/RoomDetail.vue', () => ({ default: {} }))
jest.mock('../../src/views/Leaderboard.vue', () => ({ default: {} }))
jest.mock('../../src/views/InsightsView.vue', () => ({ default: {} }))
jest.mock('../../src/views/ArcadeView.vue', () => ({ default: {} }))
jest.mock('../../src/views/AbsenceView.vue', () => ({ default: {} }))
jest.mock('../../src/views/SchedulingView.vue', () => ({ default: {} }))
jest.mock('../../src/views/CalendarView.vue', () => ({ default: {} }))
jest.mock('../../src/views/Settings.vue', () => ({ default: {} }))
jest.mock('../../src/views/Rewards.vue', () => ({ default: {} }))
jest.mock('../../src/components/Rewards/RewardRequestList.vue', () => ({ default: {} }))
jest.mock('../../src/components/Rewards/RewardRequestDetails.vue', () => ({ default: {} }))

const { routes, default: router } = require('../../src/router.js')
describe('router routes (CS-PLAN-070 WP6)', () => {
	it('redirects the bare root to Today for every role (C5f)', () => {
		const root = routes.find(route => route.path === '/')
		expect(root.redirect).toEqual({ name: 'today' })
	})

	it('redirects the old Dashboard address to Today, without a view of its own (WP-B)', () => {
		const dashboard = routes.find(route => route.path === '/dashboard')
		expect(dashboard.redirect).toEqual({ name: 'today' })
		expect(dashboard.component).toBeUndefined()
		expect(routes.some(route => route.name === 'dashboard')).toBe(false)
	})

	it('exposes the gated arcade surface on its own path (Q-077-05 A)', () => {
		const arcade = routes.find(route => route.name === 'arcade')
		expect(arcade.path).toBe('/arcade')
	})

	it('exposes the absence surface on its own path (ADR-020)', () => {
		const absence = routes.find(route => route.name === 'absence')
		expect(absence.path).toBe('/absence')
		expect(absence.component).toBeDefined()
	})

	// CS-PLAN-070 WP8 §3.1: one catalog surface with explicit modes and a
	// persistent, gate-independent request surface (list + detail).
	it('exposes the reward catalog and the persistent request routes', () => {
		const rewards = routes.find(route => route.name === 'rewards')
		expect(rewards.path).toBe('/rewards')

		const requests = routes.find(route => route.name === 'reward-requests')
		expect(requests.path).toBe('/reward-requests')

		const detail = routes.find(route => route.name === 'reward-request-detail')
		expect(detail.path).toBe('/reward-requests/:requestId')
		expect(detail.props).toBe(true)
	})

	it('resolves the today route by name', () => {
		const today = routes.find(route => route.name === 'today')
		expect(today.path).toBe('/today')
	})

	it('keeps the router instance installable', () => {
		expect(typeof router.push).toBe('function')
	})
})
