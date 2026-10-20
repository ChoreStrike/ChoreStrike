/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { usePreferencesStore } from '../store/preferences.js'
import { areCelebrationsEnabled, fireConfetti } from '../utils/confetti.js'
import { syncEffectGates } from '../utils/effectGates.js'
import { pulseCompletion } from '../utils/haptics.js'
import { playSound, unlockAudio } from '../utils/sound.js'

/** The sound of each game event; events without one stay silent. */
const EVENT_SOUNDS = Object.freeze({
	start: 'start',
	bounce: 'bounce',
	miss: 'miss',
	crash: 'crash',
	cleared: 'cleared',
	timeup: 'cleared',
	penalty: 'penalty',
	flip: 'flip',
	wrong: 'wrong',
})

/** Events that also pulse the vibration (with the Effects opt-in). */
const PULSED = new Set(['miss', 'crash', 'penalty'])

/**
 * Arcade effects (CS-PLAN-086 §3.1) through the existing effect modules and
 * behind the same personal opt-ins as the celebration effects: sound
 * (`soundEnabled`) and motion (`animationEnabled`). `prefers-reduced-motion`
 * always wins over the motion opt-in for everything that moves on screen
 * (`motionAllowed()`). The short vibration follows the motion opt-in alone,
 * like the app's completion vibration: reduced motion governs visual motion
 * (D-086-12). Every game stays fully playable and legible with all effects
 * off.
 *
 * @return {{pickup: function(string=): void, event: function(string): void, newPersonalBest: function(): void, motionAllowed: function(): boolean}}
 */
export function useGameEffects() {
	const preferences = usePreferencesStore()

	/**
	 * Whether in-game motion effects may run right now.
	 * @return {boolean}
	 */
	function motionAllowed() {
		syncEffectGates(preferences)
		return areCelebrationsEnabled()
	}

	/**
	 * A collected item: a pop (a swish for a bonus item) and a short pulse.
	 * @param {string} [kind] 'bonus' for a worth-more item
	 */
	function pickup(kind = 'normal') {
		syncEffectGates(preferences)
		playSound(kind === 'bonus' ? 'swish' : 'pop')
		if (preferences.animationEnabled === true) {
			pulseCompletion()
		}
	}

	/**
	 * Any other game event: its sound, and a pulse for a lost life or a
	 * penalty. The round start (a user gesture) also unlocks audio for the
	 * mobile autoplay policy.
	 * @param {string} type Event type (start, bounce, miss, crash, cleared, timeup, penalty, flip, wrong)
	 */
	function event(type) {
		const sound = EVENT_SOUNDS[type]
		if (!sound) {
			return
		}
		syncEffectGates(preferences)
		if (type === 'start' && preferences.soundEnabled === true) {
			unlockAudio()
		}
		playSound(sound)
		if (PULSED.has(type) && preferences.animationEnabled === true) {
			pulseCompletion()
		}
	}

	/** Confetti only for a new personal best. */
	function newPersonalBest() {
		syncEffectGates(preferences)
		fireConfetti()
		playSound('milestone')
	}

	return { pickup, event, newPersonalBest, motionAllowed }
}
