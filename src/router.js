/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { createRouter, createWebHashHistory } from 'vue-router'
import { generateUrl } from '@nextcloud/router'

import Dashboard from './views/Dashboard.vue'

const routes = [
	{
		path: '/',
		name: 'dashboard',
		component: Dashboard,
	},
	{
		path: '/tasks',
		name: 'tasks',
		component: () => import('./views/TaskManager.vue'),
	},
	{
		path: '/room/:id',
		name: 'room',
		component: () => import('./views/RoomDetail.vue'),
		props: true,
	},
	{
		path: '/leaderboard',
		name: 'leaderboard',
		component: () => import('./views/Leaderboard.vue'),
	},
	{
		path: '/settings',
		name: 'settings',
		component: () => import('./views/Settings.vue'),
	},
]

const router = createRouter({
	history: createWebHashHistory(generateUrl('/apps/chorestrike')),
	routes,
})

export default router
