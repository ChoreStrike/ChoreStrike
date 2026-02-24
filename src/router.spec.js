jest.mock('@nextcloud/router', () => ({
	generateUrl: (url) => url,
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
