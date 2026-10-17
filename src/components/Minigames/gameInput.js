/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

const DIRECTION_KEYS = Object.freeze({
	ArrowUp: 'up',
	KeyW: 'up',
	ArrowDown: 'down',
	KeyS: 'down',
	ArrowLeft: 'left',
	KeyA: 'left',
	ArrowRight: 'right',
	KeyD: 'right',
})

const TOGGLE_KEYS = new Set(['Space', 'KeyP'])

/** Shortest finger travel in CSS pixels that counts as a swipe. */
export const SWIPE_MIN_PX = 24

/**
 * Map a keyboard code to a shell action. GameShell listens on its own
 * element only (fixes Snake defects S1/S2): nothing is captured page-wide.
 *
 * @param {string} code KeyboardEvent.code
 * @return {{type: 'direction', direction: string}|{type: 'toggle'}|null}
 */
export function keyAction(code) {
	if (DIRECTION_KEYS[code]) return { type: 'direction', direction: DIRECTION_KEYS[code] }
	if (TOGGLE_KEYS.has(code)) return { type: 'toggle' }
	return null
}

/**
 * @param {number} dx Horizontal travel in CSS pixels
 * @param {number} dy Vertical travel in CSS pixels
 * @return {string|null} The dominant direction, or null for a tap
 */
export function swipeDirection(dx, dy) {
	if (Math.max(Math.abs(dx), Math.abs(dy)) < SWIPE_MIN_PX) return null
	if (Math.abs(dx) > Math.abs(dy)) return dx > 0 ? 'right' : 'left'
	return dy > 0 ? 'down' : 'up'
}
