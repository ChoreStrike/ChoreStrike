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

		expect(mockSubscribe).toHaveBeenCalledWith('chorestrike:streak-freeze-used', expect.any(Function))
		expect(mockSubscribe).toHaveBeenCalledWith('chorestrike:weekly-goal-reached', expect.any(Function))
		expect(mockSubscribe).toHaveBeenCalledWith('chorestrike:medal-awarded', expect.any(Function))
		expect(mockSubscribe).toHaveBeenCalledWith('chorestrike:task-completed', expect.any(Function))
	})

	it('shows a warning toast on streak-freeze-used with remaining count', () => {
		const { useToasts } = require('@/composables/useToasts')
		useToasts()

		handlers['chorestrike:streak-freeze-used']({ remaining: 1 })

		expect(mockShowWarning).toHaveBeenCalledTimes(1)
		// Pluralisation is reflected in the message text.
		expect(mockShowWarning).toHaveBeenCalledWith(expect.stringContaining('Streak protected by freeze'))
		expect(mockShowWarning).toHaveBeenCalledWith(expect.stringContaining('1 freeze remaining'))
	})

	it('uses plural form when more than one freeze remains', () => {
		const { useToasts } = require('@/composables/useToasts')
		useToasts()

		handlers['chorestrike:streak-freeze-used']({ remaining: 3 })

		expect(mockShowWarning).toHaveBeenCalledWith(expect.stringContaining('3 freezes remaining'))
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

	it('shows a bonus-freeze toast when task-completed carries comboBonusFreeze', () => {
		const { useToasts } = require('@/composables/useToasts')
		useToasts()

		handlers['chorestrike:task-completed']({ comboBonusFreeze: true })

		expect(mockShowSuccess).toHaveBeenCalledWith(expect.stringContaining('Bonus freeze'))
	})

	it('does not toast on a plain task completion without combo bonus', () => {
		const { useToasts } = require('@/composables/useToasts')
		useToasts()

		handlers['chorestrike:task-completed']({ comboBonusFreeze: false })

		expect(mockShowSuccess).not.toHaveBeenCalled()
	})

	it('returns a cleanup function that unsubscribes all handlers', () => {
		const { useToasts } = require('@/composables/useToasts')
		const cleanup = useToasts()

		cleanup()

		// 4 subscriptions registered.
		expect(mockUnsubscribe).toHaveBeenCalledTimes(4)
	})

	it('defaults missing freeze count to 0 without throwing', () => {
		const { useToasts } = require('@/composables/useToasts')
		useToasts()

		expect(() => handlers['chorestrike:streak-freeze-used']({})).not.toThrow()
		expect(mockShowWarning).toHaveBeenCalled()
	})
})
