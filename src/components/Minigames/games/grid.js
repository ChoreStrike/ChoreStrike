/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/**
 * Grid helpers the game rules share (CS-PLAN-086, R086H-04): the four
 * directions as cell steps, their opposites, a tapped cell and a cursor
 * move clamped to the board. Pure like every logic module.
 */

/** One cell step per direction, in the order up, down, left, right. */
export const DIRECTIONS = Object.freeze({
	up: Object.freeze({ x: 0, y: -1 }),
	down: Object.freeze({ x: 0, y: 1 }),
	left: Object.freeze({ x: -1, y: 0 }),
	right: Object.freeze({ x: 1, y: 0 }),
})

export const OPPOSITE = Object.freeze({ up: 'down', down: 'up', left: 'right', right: 'left' })

/**
 * The cell a tap names, or -1 for any other input.
 *
 * @param {*} input A game input
 * @param {number} count Cells on the board
 * @return {number}
 */
export function tappedCell(input, count) {
	if (typeof input !== 'object' || input === null || !Number.isInteger(input.cell)) return -1
	return input.cell >= 0 && input.cell < count ? input.cell : -1
}

/**
 * Move a board cursor one cell, clamped to the board; a move bumps the
 * revision the DOM boards re-render on.
 *
 * @param {{cursor: number, revision: number}} state Game state
 * @param {number} cols Board columns
 * @param {number} rows Board rows
 * @param {string} direction up, down, left or right
 */
export function moveCursor(state, cols, rows, direction) {
	const move = DIRECTIONS[direction]
	const x = Math.min(cols - 1, Math.max(0, (state.cursor % cols) + move.x))
	const y = Math.min(rows - 1, Math.max(0, Math.floor(state.cursor / cols) + move.y))
	const cursor = y * cols + x
	if (cursor !== state.cursor) {
		state.cursor = cursor
		state.revision++
	}
}
