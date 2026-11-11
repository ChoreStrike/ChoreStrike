/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { usePreferencesStore } from '../store/preferences.js'
import { fireConfetti, fireMilestoneConfetti } from '../utils/confetti.js'
import { syncEffectGates } from '../utils/effectGates.js'
import { pulseCompletion } from '../utils/haptics.js'
import { logger } from '../utils/logger.js'
import { playSound } from '../utils/sound.js'

/**
 * Initialize celebration effects (C5g): subscribe to ChoreStrike event-bus
 * events and fire confetti + sound ONLY behind the personal switches
 * (`animationEnabled`, on by default; `soundEnabled`, an opt-in). Their
 * state is pushed into the effect modules' gates on EVERY event, so a
 * mid-session preference change (or a late /preferences/me fetch)
 * applies to the next effect. `prefers-reduced-motion` stays the hard
 * floor inside the confetti module and always overrides an explicit
 * opt-in.
 *
 * Independent of both switches a completion is always acknowledged: the
 * confirmation with undo, a check mark and the coins earned
 * (UndoSnackbar), a brief highlight of the row (`feedback.css`) and one
 * short vibration where supported; the textual award toasts (useToasts)
 * stay in-app.
 *
 * Returns a cleanup function that unsubscribes all handlers.
 *
 * @return {function(): void} Cleanup function
 */
export function useCelebration() {
	const preferences = usePreferencesStore()
	const handlers = []

	/**
	 * Re-read the personal opt-ins before every effect (shared helper).
	 */
	function syncPreferenceGates() {
		syncEffectGates(preferences)
	}

	function on(event, handler) {
		subscribe(event, handler)
		handlers.push({ event, handler })
	}

	on('chorestrike:task-completed', () => {
		// Always-on subtle feedback (CS-PLAN-084 D-084-03): one short pulse
		// where the browser supports vibration; the visible check mark lives
		// in the UndoSnackbar. Confetti and sound follow the switches.
		pulseCompletion()
		syncPreferenceGates()
		fireConfetti()
		playSound('complete')
	})

	on('chorestrike:medal-awarded', () => {
		syncPreferenceGates()
		fireMilestoneConfetti()
		playSound('medal')
	})

	on('chorestrike:title-unlocked', () => {
		syncPreferenceGates()
		fireMilestoneConfetti()
		playSound('milestone')
	})

	on('chorestrike:weekly-goal-reached', () => {
		syncPreferenceGates()
		fireMilestoneConfetti()
		playSound('milestone')
	})

	logger.info('Celebration effects initialized')

	return function cleanup() {
		for (const { event, handler } of handlers) {
			unsubscribe(event, handler)
		}
		handlers.length = 0
	}
}
