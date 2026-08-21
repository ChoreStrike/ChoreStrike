/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

jest.mock('@nextcloud/router', () => ({
	generateUrl: (url) => url,
}))

jest.mock('vue-router', () => ({
	createRouter: jest.fn((config) => ({
		resolve: (path) => {
			if (path === '/today') {
				return {
					name: 'today',
					params: {},
					matched: [{ name: 'today' }],
				}
			}
			const match = path.match(/\/room\/(\d+)/)
			if (match) {
				return {
					name: 'room',
					params: { id: match[1] },
					matched: [{ name: 'room' }],
				}
			}
			// Check for legacy plural path
			const legacyMatch = path.match(/\/rooms\/(\d+)/)
			if (legacyMatch) {
				return {
					name: undefined,
					params: {},
					matched: [],
				}
			}
			return { name: undefined, params: {}, matched: [] }
		},
	})),
	createWebHashHistory: jest.fn(() => ({})),
}))

jest.mock('./views/Dashboard.vue', () => ({
	default: {},
}))

global.location = {
	host: 'localhost',
	pathname: '/',
	search: '',
	hash: '',
}

const router = require('./router.js').default

describe('Router room detail route', () => {
	it('should resolve room detail route for singular room path', () => {
		const resolved = router.resolve('/room/42')
		expect(resolved.name).toBe('room')
		expect(resolved.params.id).toBe('42')
	})

	it('should not resolve detail route for legacy plural room path', () => {
		const resolved = router.resolve('/rooms/42')
		expect(resolved.name).toBeUndefined()
		expect(resolved.matched.length).toBe(0)
	})
})

describe('Router today route', () => {
	it('should resolve the today view route', () => {
		const resolved = router.resolve('/today')
		expect(resolved.name).toBe('today')
	})
})
