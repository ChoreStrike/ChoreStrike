/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import confetti from 'canvas-confetti'
import { logger } from './logger.js'

/**
 * Re-read the reduced-motion preference on EVERY call, so a live OS-level
 * change applies to the next effect without a reload. Also names the floor
 * on the arcade overlay (CS-PLAN-086 R086H-06).
 * @return {boolean}
 */
export function prefersReducedMotion() {
	if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
		return false
	}
	return window.matchMedia('(prefers-reduced-motion: reduce)').matches === true
}

// CS-PLAN-070 WP6 (C5g): animations are a PERSONAL OPT-IN (default off).
// `prefers-reduced-motion` ALWAYS overrides even an explicit opt-in —
// the module-level checks below are the hard floor.
let animationsEnabled = false

/**
 * Read the celebration palette from the design-token CSS variables.
 * Returns the brand fallback when the tokens have not been loaded yet
 * (e.g. during unit tests that mount components in isolation).
 *
 * @return {string[]} hex palette forwarded to canvas-confetti
 */
export function getCelebrationPalette() {
	if (typeof window === 'undefined' || typeof document === 'undefined') {
		return ['#0984E3', '#FF7675', '#FDCB6E', '#00CEC9', '#FAB1A0']
	}
	const style = getComputedStyle(document.documentElement)
	const pick = (name, fallback) => {
		const value = style.getPropertyValue(name).trim()
		return value || fallback
	}
	return [
		pick('--cs-celebrate-primary', '#0984E3'),
		pick('--cs-celebrate-accent', '#FAB1A0'),
		pick('--cs-celebrate-gold', '#FDCB6E'),
		pick('--cs-celebrate-success', '#00B894'),
		pick('--cs-celebrate-bronze', '#CD7F32'),
	]
}

/**
 * Fire a burst of confetti from the center of the viewport.
 * No-op unless the personal animation opt-in is set; reduced motion
 * always wins, even over an explicit opt-in.
 *
 * @param {object} [options] Optional overrides forwarded to canvas-confetti
 */
export function fireConfetti(options = {}) {
	if (!animationsEnabled || prefersReducedMotion()) {
		return
	}

	try {
		confetti({
			particleCount: 100,
			spread: 70,
			origin: { y: 0.6 },
			disableForReducedMotion: true,
			colors: getCelebrationPalette(),
			...options,
		})
	} catch (error) {
		logger.error('Confetti failed', { error: error.message })
	}
}

/**
 * Fire confetti from both sides of the viewport (milestone celebration).
 * Same opt-in/reduced-motion rules as the single burst variant.
 *
 * @param {object} [options] Optional overrides forwarded to canvas-confetti
 */
export function fireMilestoneConfetti(options = {}) {
	if (!animationsEnabled || prefersReducedMotion()) {
		return
	}

	const defaults = {
		particleCount: 60,
		angle: 60,
		spread: 55,
		origin: { x: 0 },
		disableForReducedMotion: true,
		colors: getCelebrationPalette(),
	}
	const leftOpts = { ...defaults, ...options }
	const rightOpts = { ...defaults, ...options, angle: 120, origin: { x: 1 } }

	try {
		confetti(leftOpts)
		confetti(rightOpts)
	} catch (error) {
		logger.error('Milestone confetti failed', { error: error.message })
	}
}

/**
 * Sync the personal animation opt-in (C5g, default off). Called through
 * `syncEffectGates()` on every celebration and arcade effect; the
 * reduced-motion check stays the hard floor regardless of this flag.
 *
 * @param {boolean} value True when the user opted into animations
 */
export function setAnimationsEnabled(value) {
	animationsEnabled = value === true
}

/**
 * Check whether celebration effects are enabled (opt-in AND no
 * reduced-motion preference).
 *
 * @return {boolean}
 */
export function areCelebrationsEnabled() {
	return animationsEnabled && !prefersReducedMotion()
}
