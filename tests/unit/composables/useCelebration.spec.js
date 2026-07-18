describe('useCelebration composable', () => {
	let mockSubscribe
	let mockUnsubscribe
	let mockFireConfetti
	let mockFireMilestoneConfetti
	let mockPlaySound
	let handlers

	beforeEach(() => {
		jest.resetModules()
		handlers = {}

		mockSubscribe = jest.fn((event, handler) => {
			handlers[event] = handler
		})
		mockUnsubscribe = jest.fn()
		mockFireConfetti = jest.fn()
		mockFireMilestoneConfetti = jest.fn()
		mockPlaySound = jest.fn()

		jest.doMock('@nextcloud/event-bus', () => ({
			subscribe: mockSubscribe,
			unsubscribe: mockUnsubscribe,
		}))
		jest.doMock('@/utils/confetti', () => ({
			fireConfetti: mockFireConfetti,
			fireMilestoneConfetti: mockFireMilestoneConfetti,
		}))
		jest.doMock('@/utils/sound', () => ({
			playSound: mockPlaySound,
		}))
		jest.doMock('@/utils/logger', () => ({
			logger: { info: jest.fn(), error: jest.fn() },
		}))
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	it('subscribes to all celebration events', () => {
		const { useCelebration } = require('@/composables/useCelebration')
		useCelebration()

		expect(mockSubscribe).toHaveBeenCalledWith('chorestrike:task-completed', expect.any(Function))
		expect(mockSubscribe).toHaveBeenCalledWith('chorestrike:medal-awarded', expect.any(Function))
		expect(mockSubscribe).toHaveBeenCalledWith('chorestrike:weekly-goal-reached', expect.any(Function))
		expect(mockSubscribe).toHaveBeenCalledWith('chorestrike:streak-freeze-used', expect.any(Function))
	})

	it('fires confetti and plays complete sound on task completion', () => {
		const { useCelebration } = require('@/composables/useCelebration')
		useCelebration()

		handlers['chorestrike:task-completed']({ currentStreak: 3 })

		expect(mockFireConfetti).toHaveBeenCalledTimes(1)
		expect(mockPlaySound).toHaveBeenCalledWith('complete')
	})

	it('plays combo sound when task is combo', () => {
		const { useCelebration } = require('@/composables/useCelebration')
		useCelebration()

		handlers['chorestrike:task-completed']({ isCombo: true })

		expect(mockPlaySound).toHaveBeenCalledWith('combo')
	})

	it('fires milestone confetti on streak milestone', () => {
		const { useCelebration } = require('@/composables/useCelebration')
		useCelebration()

		handlers['chorestrike:task-completed']({ currentStreak: 7 })

		expect(mockFireMilestoneConfetti).toHaveBeenCalled()
		expect(mockPlaySound).toHaveBeenCalledWith('milestone')
	})

	it('fires milestone confetti on medal awarded', () => {
		const { useCelebration } = require('@/composables/useCelebration')
		useCelebration()

		handlers['chorestrike:medal-awarded']({})

		expect(mockFireMilestoneConfetti).toHaveBeenCalled()
		expect(mockPlaySound).toHaveBeenCalledWith('medal')
	})

	it('returns a cleanup function that unsubscribes all handlers', () => {
		const { useCelebration } = require('@/composables/useCelebration')
		const cleanup = useCelebration()

		cleanup()

		expect(mockUnsubscribe).toHaveBeenCalledTimes(4)
	})
})
