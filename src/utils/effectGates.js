/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { setAnimationsEnabled } from './confetti.js'
import { setSoundEnabled } from './sound.js'

/**
 * Push the personal effect switches into the gates of the effect modules:
 * sound is an opt-in, the animation is on once the preferences were read,
 * unless the member switched it off (CS-PLAN-090 D-090-05, RF-090-13).
 * Callers run it right before every effect, so a
 * mid-session preference change (or a late /preferences/me fetch) applies
 * to the next effect and the gating never depends on initialization order.
 * Shared by the celebration effects and the arcade (CS-PLAN-086 A-09).
 * `prefers-reduced-motion` stays the hard floor inside the confetti module.
 *
 * @param {{loaded?: boolean, soundEnabled?: boolean, animationEnabled?: boolean}} preferences Personal preferences
 */
export function syncEffectGates(preferences) {
	setSoundEnabled(preferences.soundEnabled === true)
	setAnimationsEnabled(animationAllowed(preferences))
}

/**
 * Whether the animation may run: only after an authoritative preference
 * read (`loaded`). The store's initial value is the default (on), so a
 * pending or failed `/preferences/me` read would otherwise override a
 * stored "off" (CS-PLAN-090 RF-090-13); unknown stays off, like the other
 * optional surfaces.
 *
 * @param {{loaded?: boolean, animationEnabled?: boolean}} preferences Personal preferences
 * @return {boolean}
 */
export function animationAllowed(preferences) {
	return preferences.loaded === true && preferences.animationEnabled === true
}
