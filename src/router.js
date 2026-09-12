/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { createRouter, createWebHashHistory } from 'vue-router'
import { generateUrl } from '@nextcloud/router'

// Lazy-loaded routes with named chunks for better caching
const routes = [
	{
		path: '/',
		name: 'dashboard',
		component: () => import(/* webpackChunkName: "dashboard" */ './views/Dashboard.vue'),
	},
	{
		path: '/today',
		name: 'today',
		component: () => import(/* webpackChunkName: "today" */ './views/TodayView.vue'),
	},
	{
		path: '/tasks',
		name: 'tasks',
		component: () => import(/* webpackChunkName: "tasks" */ './views/TaskManager.vue'),
	},
	{
		path: '/room/:id',
		name: 'room',
		component: () => import(/* webpackChunkName: "room" */ './views/RoomDetail.vue'),
		props: true,
	},
	{
		path: '/leaderboard',
		name: 'leaderboard',
		component: () => import(/* webpackChunkName: "leaderboard" */ './views/Leaderboard.vue'),
	},
	{
		path: '/insights',
		name: 'insights',
		component: () => import(/* webpackChunkName: "insights" */ './views/InsightsView.vue'),
	},
	{
		path: '/scheduling',
		name: 'scheduling',
		component: () => import(/* webpackChunkName: "scheduling" */ './views/SchedulingView.vue'),
	},
	{
		path: '/calendar',
		name: 'calendar',
		component: () => import(/* webpackChunkName: "calendar" */ './views/CalendarView.vue'),
	},
	{
		path: '/settings',
		name: 'settings',
		component: () => import(/* webpackChunkName: "settings" */ './views/Settings.vue'),
	},
]

const router = createRouter({
	history: createWebHashHistory(generateUrl('/apps/chorestrike')),
	routes,
})

/**
 * Resolve the role-dependent landing redirect for the CURRENT navigation
 * (CS-PLAN-066 D4): only an initial, UNQUALIFIED root visit by a child
 * redirects to the shared My Day surface. Explicit deep links (any other
 * path, or the root carrying a query) stay exactly where the caller pointed
 * them, and owner/member keeps the Dashboard default — return null for
 * "no redirect".
 *
 * @param {?string} role The resolved house role of the current user.
 * @param {object|null} currentRoute The current vue-router route.
 * @return {object|null} A route location to replace with, or null.
 */
export function landingRouteForRole(role, currentRoute) {
	if (role !== 'child') {
		return null
	}

	const path = currentRoute?.path ?? '/'
	const hasQuery = Object.keys(currentRoute?.query ?? {}).length > 0
	const isBareRoot = path === '/' && !hasQuery

	return isBareRoot ? { name: 'today' } : null
}

export default router
