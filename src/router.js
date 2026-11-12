/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { generateUrl } from '@nextcloud/router'
import { createRouter, createWebHashHistory } from 'vue-router'

// Lazy-loaded routes with named chunks for better caching
export const routes = [
	// Today is the one landing page for every role (CS-PLAN-070 WP6 C5f,
	// CS-PLAN-090 WP-B). The Dashboard merged into it; its address keeps
	// working for bookmarks.
	{
		path: '/',
		redirect: { name: 'today' },
	},
	{
		path: '/today',
		name: 'today',
		component: () => import(/* webpackChunkName: "today" */ './views/TodayView.vue'),
	},
	{
		path: '/dashboard',
		redirect: { name: 'today' },
	},
	{
		path: '/tasks',
		name: 'tasks',
		component: () => import(/* webpackChunkName: "tasks" */ './views/TaskManager.vue'),
	},
	// The room page went into the task list grouped by room (CS-PLAN-090
	// WP-C); its address opens that room there.
	{
		path: '/room/:id',
		redirect: (to) => ({ name: 'tasks', query: { group: 'room', room: to.params.id } }),
	},
	// The ranking is a section of Insights (CS-PLAN-090 WP-E); its address
	// keeps working.
	{
		path: '/leaderboard',
		redirect: { name: 'insights', hash: '#ranking' },
	},
	{
		path: '/insights',
		name: 'insights',
		component: () => import(/* webpackChunkName: "insights" */ './views/InsightsView.vue'),
	},
	{
		path: '/arcade',
		name: 'arcade',
		component: () => import(/* webpackChunkName: "arcade" */ './views/ArcadeView.vue'),
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
	// Absence (ADR-020): a period for everyone, no request and no reason.
	{
		path: '/absence',
		name: 'absence',
		component: () => import(/* webpackChunkName: "absence" */ './views/AbsenceView.vue'),
	},
	{
		path: '/settings',
		name: 'settings',
		component: () => import(/* webpackChunkName: "settings" */ './views/Settings.vue'),
	},
	// Reward catalog (CS-PLAN-070 WP8 §3.1): browsing is optional — the
	// navigation entry only renders while BOTH the household gate and the
	// current person's loaded catalogVisible preference are true. The
	// request surfaces below are independent of BOTH gates and stay
	// permanently reachable.
	{
		path: '/rewards',
		name: 'rewards',
		component: () => import(/* webpackChunkName: "rewards" */ './views/Rewards.vue'),
	},
	{
		path: '/reward-requests',
		name: 'reward-requests',
		component: () => import(/* webpackChunkName: "rewards" */ './components/Rewards/RewardRequestList.vue'),
	},
	{
		path: '/reward-requests/:requestId',
		name: 'reward-request-detail',
		component: () => import(/* webpackChunkName: "rewards" */ './components/Rewards/RewardRequestDetails.vue'),
		props: true,
	},
]

const router = createRouter({
	history: createWebHashHistory(generateUrl('/apps/chorestrike')),
	routes,
})

export default router
