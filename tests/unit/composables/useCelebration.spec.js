/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach, afterEach } from '@jest/globals'

describe('useCelebration composable', () => {
	let mockSubscribe
	let mockUnsubscribe
	let mockFireConfetti
	let mockFireMilestoneConfetti
	let mockPlaySound
	let mockSetSoundEnabled
	let mockSetAnimationsEnabled
	let handlers

	beforeEach(() => {
		jest.resetModules()
		// Require AFTER resetModules so the fresh preferences module and
		// this spec share the same pinia registry instance.
		const { createPinia, setActivePinia } = require('pinia')
		setActivePinia(createPinia())
		handlers = {}

		mockSubscribe = jest.fn((event, handler) => {
			handlers[event] = handler
		})
		mockUnsubscribe = jest.fn()
		mockFireConfetti = jest.fn()
		mockFireMilestoneConfetti = jest.fn()
		mockPlaySound = jest.fn()
		mockSetSoundEnabled = jest.fn()
		mockSetAnimationsEnabled = jest.fn()

		jest.doMock('@nextcloud/event-bus', () => ({
			subscribe: mockSubscribe,
			unsubscribe: mockUnsubscribe,
		}))
		jest.doMock('@/utils/confetti', () => ({
			fireConfetti: mockFireConfetti,
			fireMilestoneConfetti: mockFireMilestoneConfetti,
			setAnimationsEnabled: mockSetAnimationsEnabled,
		}))
		jest.doMock('@/utils/sound', () => ({
			playSound: mockPlaySound,
			setSoundEnabled: mockSetSoundEnabled,
		}))
		jest.doMock('@/utils/logger', () => ({
			logger: { info: jest.fn(), error: jest.fn() },
		}))
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	/** Set the personal opt-ins on the preference store the composable reads. */
	const setPreferences = ({ sound = false, animation = false }) => {
		const { usePreferencesStore } = require('@/store/preferences')
		const store = usePreferencesStore()
		store.soundEnabled = sound
		store.animationEnabled = animation
		return store
	}

	it('subscribes to the retained celebration events', () => {
		setPreferences({})
		const { useCelebration } = require('@/composables/useCelebration')
		useCelebration()

		expect(mockSubscribe).toHaveBeenCalledWith('chorestrike:task-completed', expect.any(Function))
		expect(mockSubscribe).toHaveBeenCalledWith('chorestrike:medal-awarded', expect.any(Function))
		expect(mockSubscribe).toHaveBeenCalledWith('chorestrike:weekly-goal-reached', expect.any(Function))
		expect(mockSubscribe).toHaveBeenCalledWith('chorestrike:title-unlocked', expect.any(Function))
		// WP4 retirement pins: no freeze/combo celebration surfaces remain.
		expect(mockSubscribe).not.toHaveBeenCalledWith('chorestrike:streak-freeze-used', expect.any(Function))
	})

	it('syncs the OFF gates per event and stays silent at the neutral defaults (C5g)', () => {
		setPreferences({})
		const { useCelebration } = require('@/composables/useCelebration')
		useCelebration()

		handlers['chorestrike:task-completed']({ currentStreak: 3 })

		expect(mockSetSoundEnabled).toHaveBeenLastCalledWith(false)
		expect(mockSetAnimationsEnabled).toHaveBeenLastCalledWith(false)
		// The composable fires through the module gates — the modules decide.
		expect(mockFireConfetti).toHaveBeenCalledTimes(1)
		expect(mockPlaySound).toHaveBeenCalledWith('complete')
	})

	it('syncs the ON gates per event after an explicit opt-in', () => {
		setPreferences({ sound: true, animation: true })
		const { useCelebration } = require('@/composables/useCelebration')
		useCelebration()

		handlers['chorestrike:task-completed']({ currentStreak: 3 })

		expect(mockSetSoundEnabled).toHaveBeenLastCalledWith(true)
		expect(mockSetAnimationsEnabled).toHaveBeenLastCalledWith(true)
		expect(mockPlaySound).toHaveBeenCalledWith('complete')
	})

	it('re-reads a mid-session preference change on the NEXT event', () => {
		const store = setPreferences({})
		const { useCelebration } = require('@/composables/useCelebration')
		useCelebration()

		handlers['chorestrike:medal-awarded']({})
		expect(mockSetSoundEnabled).toHaveBeenLastCalledWith(false)

		store.soundEnabled = true
		store.animationEnabled = true
		handlers['chorestrike:medal-awarded']({})
		expect(mockSetSoundEnabled).toHaveBeenLastCalledWith(true)
		expect(mockSetAnimationsEnabled).toHaveBeenLastCalledWith(true)
	})

	it('never plays the retired combo sound (WP4 pin)', () => {
		setPreferences({})
		const { useCelebration } = require('@/composables/useCelebration')
		useCelebration()

		handlers['chorestrike:task-completed']({ isCombo: true, comboBonusFreeze: true })

		expect(mockPlaySound).not.toHaveBeenCalledWith('combo')
	})

	it('plays the milestone profile on streak milestones', () => {
		setPreferences({})
		const { useCelebration } = require('@/composables/useCelebration')
		useCelebration()

		handlers['chorestrike:task-completed']({ currentStreak: 7 })

		expect(mockFireMilestoneConfetti).toHaveBeenCalled()
		expect(mockPlaySound).toHaveBeenCalledWith('milestone')
	})

	it('fires milestone effects on medal awarded', () => {
		setPreferences({})
		const { useCelebration } = require('@/composables/useCelebration')
		useCelebration()

		handlers['chorestrike:medal-awarded']({})

		expect(mockFireMilestoneConfetti).toHaveBeenCalled()
		expect(mockPlaySound).toHaveBeenCalledWith('medal')
	})

	it('fires milestone effects on title unlocked', () => {
		setPreferences({})
		const { useCelebration } = require('@/composables/useCelebration')
		useCelebration()

		handlers['chorestrike:title-unlocked']({})

		expect(mockFireMilestoneConfetti).toHaveBeenCalled()
		expect(mockPlaySound).toHaveBeenCalledWith('milestone')
	})

	it('returns a cleanup function that unsubscribes all handlers', () => {
		setPreferences({})
		const { useCelebration } = require('@/composables/useCelebration')
		const cleanup = useCelebration()

		cleanup()

		expect(mockUnsubscribe).toHaveBeenCalledTimes(4)
	})
})
