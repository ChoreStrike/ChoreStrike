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
		path: '/settings',
		name: 'settings',
		component: () => import(/* webpackChunkName: "settings" */ './views/Settings.vue'),
	},
]

const router = createRouter({
	history: createWebHashHistory(generateUrl('/apps/chorestrike')),
	routes,
})

export default router
