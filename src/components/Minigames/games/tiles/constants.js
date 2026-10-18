/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/**
 * Tile Breaker's board geometry and shared tuning (CS-PLAN-086 §3.1), used
 * by the rules (`logic.js`, `physics.js`) and the renderer. Units are board
 * cells (COLS × ROWS); speeds are cells per second.
 */

export const COLS = 16
export const ROWS = 20
export const START_LIVES = 3
export const TILE_POINTS = 10

export const WALL_COLS = 8
export const WALL_ROWS = 5
export const TILE_W = COLS / WALL_COLS
export const TILE_H = 0.8
export const WALL_TOP = 3

export const BUBBLE_R = 0.35
export const SQUEEGEE_Y = 18.5
export const SQUEEGEE_H = 0.5
export const SQUEEGEE_W = 3
export const MAX_SPEED = 15
/** A waiting bubble launches by itself after this time. */
export const SERVE_MS = 1000
export const MAX_BUBBLES = 3
export const DROP_HALF = 0.45
export const DROP_KINDS = Object.freeze(['sponge', 'spray', 'wax', 'mud'])

/** Wall layouts, top row first: the hits each tile needs (the last one repeats). */
export const WALLS = Object.freeze([
	['11111111', '11111111', '11111111', '11111111', '11111111'],
	['22222222', '22222222', '11111111', '11111111', '11111111'],
	['22222222', '21111112', '21122112', '21111112', '11111111'],
])

/**
 * @param {number} value Value to bound
 * @param {number} min Lower bound
 * @param {number} max Upper bound
 * @return {number} The value within [min, max]
 */
export function clamp(value, min, max) {
	return Math.min(max, Math.max(min, value))
}
