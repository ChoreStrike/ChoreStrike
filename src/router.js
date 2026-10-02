/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { createRouter, createWebHashHistory } from 'vue-router'
import { generateUrl } from '@nextcloud/router'

// Lazy-loaded routes with named chunks for better caching
export const routes = [
	// CS-PLAN-070 WP6 (C5f, Q-077-01 Option A): EVERY role lands on the
	// shared My Day surface after setup — a route-level redirect replaces
	// the retired child-only special case. The Dashboard stays reachable
	// as the secondary household overview on its explicit path.
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
		name: 'dashboard',
		component: () => import(/* webpackChunkName: "dashboard" */ './views/Dashboard.vue'),
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
