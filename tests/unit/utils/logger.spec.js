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
})
