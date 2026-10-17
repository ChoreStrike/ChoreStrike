/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { setAnimationsEnabled } from './confetti.js'
import { setSoundEnabled } from './sound.js'

/**
 * Push the personal effect opt-ins (C5g, both default off) into the gates
 * of the effect modules. Callers run it right before every effect, so a
 * mid-session preference change (or a late /preferences/me fetch) applies
 * to the next effect and the gating never depends on initialization order.
 * Shared by the celebration effects and the arcade (CS-PLAN-086 A-09).
 * `prefers-reduced-motion` stays the hard floor inside the confetti module.
 *
 * @param {{soundEnabled?: boolean, animationEnabled?: boolean}} preferences Personal preferences
 */
export function syncEffectGates(preferences) {
	setSoundEnabled(preferences.soundEnabled === true)
	setAnimationsEnabled(preferences.animationEnabled === true)
}
