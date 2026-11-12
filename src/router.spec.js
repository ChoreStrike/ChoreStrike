/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

jest.mock('@nextcloud/router', () => ({
	generateUrl: (url) => url,
}))

jest.mock('vue-router', () => ({
	createRouter: jest.fn(() => ({
		resolve: (path) => {
			if (path === '/today') {
				return {
					name: 'today',
					params: {},
					matched: [{ name: 'today' }],
				}
			}
			return { name: undefined, params: {}, matched: [] }
		},
	})),
	createWebHashHistory: jest.fn(() => ({})),
}))

global.location = {
	host: 'localhost',
	pathname: '/',
	search: '',
	hash: '',
}

const { routes, default: router } = require('./router.js')

describe('Router room address', () => {
	it('redirects a room address to that room in the task list grouped by room', () => {
		const room = routes.find((route) => route.path === '/room/:id')
		expect(room.component).toBeUndefined()
		expect(room.redirect({ params: { id: '42' } })).toEqual({ name: 'tasks', query: { group: 'room', room: '42' } })
		expect(routes.some((route) => route.name === 'room')).toBe(false)
	})

	it('has no route for the legacy plural room path', () => {
		expect(routes.some((route) => route.path.startsWith('/rooms'))).toBe(false)
	})
})

describe('Router today route', () => {
	it('should resolve the today view route', () => {
		const resolved = router.resolve('/today')
		expect(resolved.name).toBe('today')
	})
})
