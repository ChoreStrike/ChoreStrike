/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * CS-PLAN-066 (D4): the role-dependent initial landing redirect. Only an
 * initial, unqualified root visit by a child redirects to the shared My Day
 * surface; explicit deep links and owner/member defaults stay untouched.
 */

import { landingRouteForRole } from '../../src/router.js'

describe('landingRouteForRole (CS-PLAN-066 D4)', () => {
	it('redirects the bare root visit of a child to My Day', () => {
		expect(landingRouteForRole('child', { path: '/', name: 'dashboard', query: {} }))
			.toEqual({ name: 'today' })
	})

	it('keeps owner and member defaults on the Dashboard (null = no redirect)', () => {
		expect(landingRouteForRole('owner', { path: '/', name: 'dashboard', query: {} })).toBeNull()
		expect(landingRouteForRole('member', { path: '/', name: 'dashboard', query: {} })).toBeNull()
	})

	it('never redirects an unknown role (loading gate territory, not landing)', () => {
		expect(landingRouteForRole(null, { path: '/', name: 'dashboard', query: {} })).toBeNull()
		expect(landingRouteForRole(undefined, { path: '/', name: 'dashboard', query: {} })).toBeNull()
	})

	it('keeps explicit deep links usable for children', () => {
		expect(landingRouteForRole('child', { path: '/tasks', name: 'tasks', query: {} })).toBeNull()
		expect(landingRouteForRole('child', { path: '/room/7', name: 'room', query: {} })).toBeNull()
		expect(landingRouteForRole('child', { path: '/calendar', name: 'calendar', query: {} })).toBeNull()
	})

	it('a root visit carrying a query is a qualified link, not the bare root', () => {
		expect(landingRouteForRole('child', { path: '/', name: 'dashboard', query: { scope: 'all' } })).toBeNull()
	})
})
