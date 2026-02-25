import { createRouter, createWebHashHistory } from 'vue-router'
import { generateUrl } from '@nextcloud/router'

// Lazy load all views for better code splitting
const Dashboard = () => import(/* webpackChunkName: "dashboard" */ './views/Dashboard.vue')
const TaskManager = () => import(/* webpackChunkName: "tasks" */ './views/TaskManager.vue')
const RoomDetail = () => import(/* webpackChunkName: "room" */ './views/RoomDetail.vue')
const Leaderboard = () => import(/* webpackChunkName: "leaderboard" */ './views/Leaderboard.vue')
const Settings = () => import(/* webpackChunkName: "settings" */ './views/Settings.vue')

const routes = [
	{
		path: '/',
		name: 'dashboard',
		component: Dashboard,
		meta: {
			keepAlive: true,
			title: 'Dashboard',
		},
	},
	{
		path: '/tasks',
		name: 'tasks',
		component: TaskManager,
		meta: {
			keepAlive: true,
			title: 'Tasks',
		},
	},
	{
		path: '/room/:id',
		name: 'room',
		component: RoomDetail,
		props: true,
		meta: {
			keepAlive: false,
			title: 'Room Details',
		},
	},
	{
		path: '/leaderboard',
		name: 'leaderboard',
		component: Leaderboard,
		meta: {
			keepAlive: true,
			title: 'Leaderboard',
		},
	},
	{
		path: '/settings',
		name: 'settings',
		component: Settings,
		meta: {
			keepAlive: false,
			title: 'Settings',
		},
	},
]

const router = createRouter({
	history: createWebHashHistory(generateUrl('/apps/chorestrike')),
	routes,
	scrollBehavior(to, from, savedPosition) {
		if (savedPosition) {
			return savedPosition
		}
		if (to.hash) {
			return { el: to.hash }
		}
		return { top: 0 }
	},
})

// Prefetch route components after initial load
if ('requestIdleCallback' in window) {
	window.requestIdleCallback(() => {
		const prefetchRoutes = ['tasks', 'leaderboard', 'settings']
		prefetchRoutes.forEach(routeName => {
			const route = router.resolve({ name: routeName })
			if (route.route.component) {
				// Trigger component load
				route.route.component()
			}
		})
	})
}

// Update document title
router.afterEach((to) => {
	if (to.meta?.title) {
		document.title = `${to.meta.title} - ChoreStrike`
	}
})

export default router
