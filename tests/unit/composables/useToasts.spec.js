describe('useToasts composable', () => {
	let mockSubscribe
	let mockUnsubscribe
	let mockShowSuccess
	let mockShowWarning
	let mockShowInfo
	let mockShowError
	let handlers

	beforeEach(() => {
		jest.resetModules()
		handlers = {}

		mockSubscribe = jest.fn((event, handler) => {
			handlers[event] = handler
		})
		mockUnsubscribe = jest.fn()
		mockShowSuccess = jest.fn()
		mockShowWarning = jest.fn()
		mockShowInfo = jest.fn()
		mockShowError = jest.fn()

		jest.doMock('@nextcloud/event-bus', () => ({
			subscribe: mockSubscribe,
			unsubscribe: mockUnsubscribe,
		}))
		jest.doMock('@nextcloud/dialogs', () => ({
			showSuccess: mockShowSuccess,
			showWarning: mockShowWarning,
			showInfo: mockShowInfo,
			showError: mockShowError,
		}))
		jest.doMock('@nextcloud/l10n', () => ({
			translate: jest.fn((app, text, vars) => {
				if (!vars) return text
				return Object.keys(vars).reduce(
					(out, k) => out.replace(`{${k}}`, String(vars[k])),
					text,
				)
			}),
			translatePlural: jest.fn(),
		}))
		jest.doMock('@/utils/logger', () => ({
			logger: { info: jest.fn(), error: jest.fn() },
		}))
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	it('subscribes to toast-relevant events', () => {
		const { useToasts } = require('@/composables/useToasts')
		useToasts()

		expect(mockSubscribe).toHaveBeenCalledWith('chorestrike:weekly-goal-reached', expect.any(Function))
		expect(mockSubscribe).toHaveBeenCalledWith('chorestrike:medal-awarded', expect.any(Function))
		// WP4 retirement pins: the freeze/combo event surfaces are gone.
		expect(mockSubscribe).not.toHaveBeenCalledWith('chorestrike:streak-freeze-used', expect.any(Function))
		expect(mockSubscribe).not.toHaveBeenCalledWith('chorestrike:task-completed', expect.any(Function))
	})

	it('shows a success toast on weekly-goal-reached with percent and target', () => {
		const { useToasts } = require('@/composables/useToasts')
		useToasts()

		handlers['chorestrike:weekly-goal-reached']({ percent: 95, targetPercent: 80 })

		expect(mockShowSuccess).toHaveBeenCalledTimes(1)
		expect(mockShowSuccess).toHaveBeenCalledWith(expect.stringContaining('Weekly goal reached'))
		expect(mockShowSuccess).toHaveBeenCalledWith(expect.stringContaining('95%'))
		expect(mockShowSuccess).toHaveBeenCalledWith(expect.stringContaining('80%'))
	})

	it('shows a success toast on medal awarded', () => {
		const { useToasts } = require('@/composables/useToasts')
		useToasts()

		handlers['chorestrike:medal-awarded']()

		expect(mockShowSuccess).toHaveBeenCalledWith(expect.stringContaining('medal'))
	})

	it('never toasts for freeze/combo payloads (retired mechanics)', () => {
		const { useToasts } = require('@/composables/useToasts')
		useToasts()

		expect(handlers['chorestrike:streak-freeze-used']).toBeUndefined()
		expect(handlers['chorestrike:task-completed']).toBeUndefined()
		expect(mockShowSuccess).not.toHaveBeenCalled()
		expect(mockShowWarning).not.toHaveBeenCalled()
	})

	it('returns a cleanup function that unsubscribes all handlers', () => {
		const { useToasts } = require('@/composables/useToasts')
		const cleanup = useToasts()

		cleanup()

		// 2 subscriptions registered (weekly goal + medal).
		expect(mockUnsubscribe).toHaveBeenCalledTimes(2)
	})
})
