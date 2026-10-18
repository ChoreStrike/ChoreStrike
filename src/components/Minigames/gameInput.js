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

/** Keys that act on the current target (Spot Sprint wipes the cursor tile, Sock Match turns its sock). */
const ACT_KEYS = new Set(['Enter', 'NumpadEnter'])

/** Directions that steer a paddle; up and down do nothing there. */
const STEER_DIRECTIONS = new Set(['left', 'right'])

/** Shortest finger travel in CSS pixels that counts as a swipe. */
export const SWIPE_MIN_PX = 24

/**
 * Map a keyboard code to a shell action. GameShell listens on its own
 * element only (fixes Snake defects S1/S2): nothing is captured page-wide.
 *
 * @param {string} code KeyboardEvent.code
 * @return {{type: 'direction', direction: string}|{type: 'toggle'}|{type: 'act'}|null}
 */
export function keyAction(code) {
	if (DIRECTION_KEYS[code]) return { type: 'direction', direction: DIRECTION_KEYS[code] }
	if (TOGGLE_KEYS.has(code)) return { type: 'toggle' }
	if (ACT_KEYS.has(code)) return { type: 'act' }
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

/**
 * Held steering keys of the `paddle` input mode. A paddle moves while a key
 * is down, so the shell reports changes of the steer value (-1, 0 or 1),
 * never key repeats: `press` and `release` answer the new value, or null
 * when it did not change. Keys are held by code, so releasing one of two
 * keys for the same side (ArrowLeft and KeyA) keeps the other one steering.
 *
 * @return {{press: function(string, string): (number|null), release: function(string): (number|null), clear: function(): void}}
 */
export function createSteerKeys() {
	// KeyboardEvent.code → 'left' | 'right'
	const held = new Map()
	const value = () => {
		let left = 0
		let right = 0
		for (const direction of held.values()) {
			if (direction === 'left') left = 1
			else right = 1
		}
		return right - left
	}
	const change = (update) => {
		const before = value()
		update()
		const after = value()
		return after === before ? null : after
	}
	return {
		press(code, direction) {
			if (!STEER_DIRECTIONS.has(direction)) return null
			return change(() => held.set(code, direction))
		},
		release(code) {
			return change(() => held.delete(code))
		},
		clear() {
			held.clear()
		},
	}
}

/**
 * Whether an input sets a paddle's control state (`{steer}` or `{aim}`).
 * The latest control alone decides that state, so a newer one replaces a
 * queued one.
 *
 * @param {*} input A queued game input
 * @return {boolean}
 */
export function isPaddleControl(input) {
	return input !== null && typeof input === 'object' && ('steer' in input || 'aim' in input)
}
