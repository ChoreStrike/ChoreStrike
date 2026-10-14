/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

// One short, subtle pulse (CS-PLAN-084 D-084-03).
const COMPLETION_PULSE_MS = 20

/**
 * Whether the browser offers web vibration (Android browsers; iOS Safari
 * has none).
 * @return {boolean}
 */
export function hapticsSupported() {
	return typeof navigator !== 'undefined' && typeof navigator.vibrate === 'function'
}

/**
 * Short haptic acknowledgement of a completed action. Feature-detected and
 * a silent no-op elsewhere; never throws.
 * @return {boolean} Whether a vibration was started.
 */
export function pulseCompletion() {
	if (!hapticsSupported()) return false
	try {
		return navigator.vibrate(COMPLETION_PULSE_MS) === true
	} catch {
		// A blocked or policy-restricted vibration is never an error.
		return false
	}
}
