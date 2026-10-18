/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'

/**
 * CS-PLAN-086 §3.1 (A-09): arcade effects reuse the celebration opt-in gate
 * sync — one shared helper, no copy — and fire nothing without an opt-in.
 */
describe('useGameEffects', () => {
	let mocks
	let preferences

	const load = () => {
		const { createPinia, setActivePinia } = require('pinia')
		setActivePinia(createPinia())
		preferences = require('@/store/preferences.js').usePreferencesStore()
		return require('@/composables/useGameEffects.js').useGameEffects()
	}

	beforeEach(() => {
		jest.resetModules()
		mocks = {
			playSound: jest.fn(),
			unlockAudio: jest.fn(),
			setSoundEnabled: jest.fn(),
			fireConfetti: jest.fn(),
			setAnimationsEnabled: jest.fn(),
			areCelebrationsEnabled: jest.fn(() => false),
			pulseCompletion: jest.fn(),
		}
		jest.doMock('@/utils/sound', () => ({ playSound: mocks.playSound, unlockAudio: mocks.unlockAudio, setSoundEnabled: mocks.setSoundEnabled }))
		jest.doMock('@/utils/confetti', () => ({
			fireConfetti: mocks.fireConfetti,
			fireMilestoneConfetti: jest.fn(),
			setAnimationsEnabled: mocks.setAnimationsEnabled,
			areCelebrationsEnabled: mocks.areCelebrationsEnabled,
		}))
		jest.doMock('@/utils/haptics', () => ({ pulseCompletion: mocks.pulseCompletion }))
		jest.doMock('@/utils/logger', () => ({ logger: { info: jest.fn(), error: jest.fn() } }))
	})

	it('syncs the opt-ins (off by default) before a pickup and never pulses without the motion opt-in', () => {
		const effects = load()

		effects.pickup()

		expect(mocks.setSoundEnabled).toHaveBeenCalledWith(false)
		expect(mocks.setAnimationsEnabled).toHaveBeenCalledWith(false)
		expect(mocks.playSound).toHaveBeenCalledWith('pop')
		expect(mocks.pulseCompletion).not.toHaveBeenCalled()
	})

	it('uses the swish for bonus items and pulses with the motion opt-in', () => {
		const effects = load()
		preferences.soundEnabled = true
		preferences.animationEnabled = true

		effects.pickup('bonus')

		expect(mocks.setSoundEnabled).toHaveBeenLastCalledWith(true)
		expect(mocks.setAnimationsEnabled).toHaveBeenLastCalledWith(true)
		expect(mocks.playSound).toHaveBeenCalledWith('swish')
		expect(mocks.pulseCompletion).toHaveBeenCalledTimes(1)
	})

	it('plays the sound of a game event and pulses only for a lost life or a penalty with the motion opt-in', () => {
		const effects = load()

		effects.event('bounce')
		expect(mocks.playSound).toHaveBeenLastCalledWith('bounce')
		effects.event('timeup')
		expect(mocks.playSound).toHaveBeenLastCalledWith('cleared')
		effects.event('miss')
		expect(mocks.playSound).toHaveBeenLastCalledWith('miss')
		expect(mocks.pulseCompletion).not.toHaveBeenCalled()

		preferences.animationEnabled = true
		effects.event('crash')
		effects.event('penalty')
		effects.event('flip')
		expect(mocks.pulseCompletion).toHaveBeenCalledTimes(2)
		expect(mocks.setSoundEnabled).toHaveBeenCalledTimes(6)
	})

	it('stays silent for events without a sound', () => {
		const effects = load()

		effects.event('clean')
		effects.event('unknown')

		expect(mocks.playSound).not.toHaveBeenCalled()
		expect(mocks.setSoundEnabled).not.toHaveBeenCalled()
	})

	it('unlocks audio at the round start only with the sound opt-in', () => {
		const effects = load()

		effects.event('start')
		expect(mocks.unlockAudio).not.toHaveBeenCalled()
		expect(mocks.playSound).toHaveBeenCalledWith('start')

		preferences.soundEnabled = true
		effects.event('start')
		expect(mocks.unlockAudio).toHaveBeenCalledTimes(1)
	})

	it('celebrates a new personal best through the gated confetti and sound modules', () => {
		const effects = load()

		effects.newPersonalBest()

		expect(mocks.setAnimationsEnabled).toHaveBeenCalledWith(false)
		expect(mocks.fireConfetti).toHaveBeenCalledTimes(1)
		expect(mocks.playSound).toHaveBeenCalledWith('milestone')
	})

	it('allows in-game sparkles only when the confetti gate (opt-in and no reduced motion) allows motion', () => {
		const effects = load()
		expect(effects.motionAllowed()).toBe(false)

		mocks.areCelebrationsEnabled.mockReturnValue(true)
		expect(effects.motionAllowed()).toBe(true)
		expect(mocks.setAnimationsEnabled).toHaveBeenCalledTimes(2)
	})

	it('shares the gate sync helper with useCelebration', () => {
		const syncEffectGates = jest.fn()
		jest.doMock('@/utils/effectGates', () => ({ syncEffectGates }))
		jest.doMock('@nextcloud/event-bus', () => {
			const handlers = {}
			return {
				subscribe: (event, handler) => { handlers[event] = handler },
				unsubscribe: jest.fn(),
				emit: (event) => handlers[event](),
			}
		})
		const effects = load()
		const { useCelebration } = require('@/composables/useCelebration.js')
		useCelebration()

		effects.pickup()
		require('@nextcloud/event-bus').emit('chorestrike:medal-awarded')

		expect(syncEffectGates).toHaveBeenCalledTimes(2)
		expect(syncEffectGates).toHaveBeenNthCalledWith(1, preferences)
		expect(syncEffectGates).toHaveBeenNthCalledWith(2, preferences)
	})
})
