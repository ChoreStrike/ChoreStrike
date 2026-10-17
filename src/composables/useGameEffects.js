/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { usePreferencesStore } from '../store/preferences.js'
import { syncEffectGates } from '../utils/effectGates.js'
import { areCelebrationsEnabled, fireConfetti } from '../utils/confetti.js'
import { playSound } from '../utils/sound.js'
import { pulseCompletion } from '../utils/haptics.js'

/**
 * Arcade effects (CS-PLAN-086 §3.1) through the existing effect modules and
 * behind the same personal opt-ins as the celebration effects: sound
 * (`soundEnabled`) and motion (`animationEnabled`, which also covers the
 * pickup vibration). `prefers-reduced-motion` always wins over the motion
 * opt-in. Every game stays fully playable and legible with all effects off.
 *
 * @return {{pickup: function(string=): void, newPersonalBest: function(): void, motionAllowed: function(): boolean}}
 */
export function useGameEffects() {
	const preferences = usePreferencesStore()

	/**
	 * Whether in-game sparkles may be drawn right now.
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

	/** Confetti only for a new personal best. */
	function newPersonalBest() {
		syncEffectGates(preferences)
		fireConfetti()
		playSound('milestone')
	}

	return { pickup, newPersonalBest, motionAllowed }
}
