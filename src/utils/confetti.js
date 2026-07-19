/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import confetti from 'canvas-confetti'
import { logger } from './logger.js'

const reducedMotionQuery = typeof window !== 'undefined'
	? window.matchMedia('(prefers-reduced-motion: reduce)')
	: null

function prefersReducedMotion() {
	return reducedMotionQuery?.matches ?? false
}

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
 * No-op when the user prefers reduced motion.
 *
 * @param {object} [options] Optional overrides forwarded to canvas-confetti
 */
export function fireConfetti(options = {}) {
	if (prefersReducedMotion()) {
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
 *
 * @param {object} [options] Optional overrides forwarded to canvas-confetti
 */
export function fireMilestoneConfetti(options = {}) {
	if (prefersReducedMotion()) {
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
 * Check whether celebration effects are enabled (respects reduced motion).
 *
 * @return {boolean}
 */
export function areCelebrationsEnabled() {
	return !prefersReducedMotion()
}
