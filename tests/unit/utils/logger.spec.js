describe('frontend logger', () => {
	const originalNodeEnv = process.env.NODE_ENV

	beforeEach(() => {
		jest.resetModules()
		jest.spyOn(console, 'debug').mockImplementation(() => {})
		jest.spyOn(console, 'info').mockImplementation(() => {})
		jest.spyOn(console, 'warn').mockImplementation(() => {})
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		process.env.NODE_ENV = originalNodeEnv
		jest.restoreAllMocks()
	})

	it('suppresses debug logging in production', () => {
		process.env.NODE_ENV = 'production'
		const { logger } = require('@/utils/logger')

		logger.debug('Should stay silent', { store: 'tasks' })

		expect(console.debug).not.toHaveBeenCalled()
	})

	it('writes error logs with error and context payloads', () => {
		process.env.NODE_ENV = 'test'
		const { logger } = require('@/utils/logger')
		const error = new Error('Request failed')
		const context = { store: 'tasks', action: 'fetchTasks' }

		logger.error('Task loading failed', error, context)

		expect(console.error).toHaveBeenCalledWith(
			'[ChoreStrike] [error] Task loading failed',
			error,
			context,
		)
	})

	it('redacts ApiError objects to {name, status, code, message} without response bodies (CS-PLAN-061 §3.6)', () => {
		process.env.NODE_ENV = 'test'
		const { logger } = require('@/utils/logger')
		const apiError = new Error('Invite failed')
		apiError.name = 'ApiError'
		apiError.status = 403
		apiError.code = 'FORBIDDEN'
		apiError.data = {
			success: false,
			error: { code: 'FORBIDDEN', message: 'Invite failed' },
			displayName: 'Jane Doe',
			email: 'jane@example.com',
		}

		logger.error('Failed to invite user', apiError, { store: 'user' })

		expect(console.error).toHaveBeenCalledWith(
			'[ChoreStrike] [error] Failed to invite user',
			{ name: 'ApiError', status: 403, code: 'FORBIDDEN', message: 'Invite failed' },
			{ store: 'user' },
		)
	})

	it('redacts ApiError objects on warn logs too', () => {
		process.env.NODE_ENV = 'test'
		const { logger } = require('@/utils/logger')
		const apiError = new Error('Request failed')
		apiError.name = 'ApiError'
		apiError.status = 404
		apiError.code = 'RESOURCE_NOT_FOUND'
		apiError.data = { memberNames: ['alice', 'bob'] }

		logger.warn('Fetch failed', apiError)

		expect(console.warn).toHaveBeenCalledWith(
			'[ChoreStrike] [warn] Fetch failed',
			{ name: 'ApiError', status: 404, code: 'RESOURCE_NOT_FOUND', message: 'Request failed' },
		)
	})
})
