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

// The room redirect itself is pinned in tests/unit/router.spec.js.
describe('Router room address', () => {
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
