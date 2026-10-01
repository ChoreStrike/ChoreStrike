/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { usePreferencesStore } from '../store/preferences.js'
import { fireConfetti, fireMilestoneConfetti, setAnimationsEnabled } from '../utils/confetti.js'
import { playSound, setSoundEnabled } from '../utils/sound.js'
import { logger } from '../utils/logger.js'

const STREAK_MILESTONES = [7, 30, 100]

/**
 * Initialize celebration effects (C5g): subscribe to ChoreStrike event-bus
 * events and fire confetti + sound ONLY behind the personal opt-ins
 * (`soundEnabled` / `animationEnabled`, both default OFF). The opt-in
 * state is pushed into the effect modules' gates on EVERY event, so a
 * mid-session preference change (or a late /preferences/me fetch)
 * applies to the next effect. `prefers-reduced-motion` stays the hard
 * floor inside the confetti module and always overrides an explicit
 * opt-in.
 *
 * The DEFAULT completion feedback is the accessible confirmation with
 * undo (UndoSnackbar) — no sound, no confetti, no modal; the quiet
 * textual award toasts (useToasts) stay in-app.
 *
 * Returns a cleanup function that unsubscribes all handlers.
 *
 * @return {function(): void} Cleanup function
 */
export function useCelebration() {
	const preferences = usePreferencesStore()
	const handlers = []

	/**
	 * Re-read the personal opt-ins before every effect so the gating
	 * never depends on initialization order.
	 */
	function syncPreferenceGates() {
		setSoundEnabled(preferences.soundEnabled === true)
		setAnimationsEnabled(preferences.animationEnabled === true)
	}

	function on(event, handler) {
		subscribe(event, handler)
		handlers.push({ event, handler })
	}

	on('chorestrike:task-completed', (data) => {
		syncPreferenceGates()
		fireConfetti()

		const streak = data?.currentStreak
		if (streak && STREAK_MILESTONES.includes(streak)) {
			fireMilestoneConfetti()
			playSound('milestone')
		} else {
			playSound('complete')
		}
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
