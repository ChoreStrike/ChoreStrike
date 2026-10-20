/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { DIRECTIONS, OPPOSITE } from '../grid.js'

/**
 * Crumb Snake rules (CS-PLAN-086 §3.1): a vacuum hose collects crumbs on a
 * tiled floor while furniture blocks the way. Pure and deterministic — no
 * DOM, no timers; the random source is a parameter (seeded only in Jest).
 * `step` advances the state object it owns and returns it.
 *
 * Fixes Snake defects S6 (furniture never re-randomizes and never lands in
 * front of the hose) and S7 (no dead state).
 */

export const COLS = 20
export const ROWS = 20

const START_TICK_MS = 150
const MIN_TICK_MS = 60
const SPEEDUP_MS = 2
const CRUMB_POINTS = 10
const GOLDEN_POINTS = 30
const GOLDEN_CHANCE = 0.15
const POINTS_PER_FURNITURE = 50
const MAX_FURNITURE = 10
/** Cells straight ahead of the nozzle that never receive furniture. */
const SAFE_AHEAD = 4
/** Chebyshev radius around the nozzle that never receives furniture. */
const SAFE_RADIUS = 2
const MAX_QUEUED_TURNS = 2

export { DIRECTIONS }

/**
 * New round with a deterministic opening: the hose starts on the left
 * heading right, and the first crumb lies four cells straight ahead, so a
 * round scores without any input or randomness.
 *
 * @param {function(): number} rng Random source returning [0, 1)
 * @return {object} Game state
 */
export function createState(rng) {
	return {
		rng,
		snake: [{ x: 5, y: 10 }, { x: 4, y: 10 }, { x: 3, y: 10 }],
		direction: 'right',
		turns: [],
		crumb: { x: 9, y: 10, golden: false },
		furniture: [],
		tickMs: START_TICK_MS,
		elapsed: 0,
		score: 0,
		over: false,
		// Drained by the shell after every step: pickup | crash | cleared.
		events: [],
	}
}

/**
 * Advance the round by `dt` milliseconds, applying one input first.
 *
 * @param {object} state Game state from createState
 * @param {string|null} input A direction (up, down, left, right) or null
 * @param {number} dt Elapsed game time in milliseconds
 * @return {object} The same state object
 */
export function step(state, input, dt) {
	if (state.over) {
		return state
	}
	if (input) {
		queueTurn(state, input)
	}
	state.elapsed += dt
	while (state.elapsed >= state.tickMs && !state.over) {
		state.elapsed -= state.tickMs
		advance(state)
	}
	return state
}

/**
 * Buffer up to two turns so a quick "up, right" between two moves keeps
 * both; a turn back into the hose (relative to the last queued direction)
 * or a repeat is ignored.
 *
 * @param {object} state Game state
 * @param {string} direction Requested direction
 */
function queueTurn(state, direction) {
	if (!DIRECTIONS[direction] || state.turns.length >= MAX_QUEUED_TURNS) {
		return
	}
	const last = state.turns.length > 0 ? state.turns[state.turns.length - 1] : state.direction
	if (direction === last || direction === OPPOSITE[last]) {
		return
	}
	state.turns.push(direction)
}

/**
 * The direction of the next move: the first queued turn, else the current
 * way. The renderer draws the nozzle on its way there.
 *
 * @param {object} state Game state
 * @return {string} Direction
 */
export function nextDirection(state) {
	return state.turns.length > 0 ? state.turns[0] : state.direction
}

function advance(state) {
	if (state.turns.length > 0) {
		state.direction = state.turns.shift()
	}
	const delta = DIRECTIONS[state.direction]
	const head = { x: state.snake[0].x + delta.x, y: state.snake[0].y + delta.y }
	const eats = head.x === state.crumb.x && head.y === state.crumb.y
	if (!inBounds(head) || hitsHose(state, head, eats) || isFurniture(state, head)) {
		state.over = true
		state.events.push({ type: 'crash' })
		return
	}
	state.snake.unshift(head)
	if (eats) {
		eat(state, head)
	} else {
		state.snake.pop()
	}
}

function inBounds(cell) {
	return cell.x >= 0 && cell.x < COLS && cell.y >= 0 && cell.y < ROWS
}

/**
 * Whether a cell is hose; the tail cell frees up during a move unless the
 * hose grows.
 *
 * @param {object} state Game state
 * @param {{x: number, y: number}} cell Cell to test
 * @param {boolean} grows Whether the hose grows in this move
 * @return {boolean}
 */
function hitsHose(state, cell, grows) {
	const length = grows ? state.snake.length : state.snake.length - 1
	for (let i = 0; i < length; i++) {
		if (state.snake[i].x === cell.x && state.snake[i].y === cell.y) {
			return true
		}
	}
	return false
}

function isFurniture(state, cell) {
	return state.furniture.some((piece) => piece.x === cell.x && piece.y === cell.y)
}

function eat(state, head) {
	const golden = state.crumb.golden
	state.score += golden ? GOLDEN_POINTS : CRUMB_POINTS
	state.events.push({ type: 'pickup', kind: golden ? 'bonus' : 'normal', x: head.x, y: head.y })
	state.tickMs = Math.max(MIN_TICK_MS, state.tickMs - SPEEDUP_MS)
	addFurniture(state)
	placeCrumb(state)
}

/**
 * One more piece of furniture per 50 points; existing pieces stay put.
 *
 * @param {object} state Game state
 */
function addFurniture(state) {
	const target = Math.min(MAX_FURNITURE, Math.floor(state.score / POINTS_PER_FURNITURE))
	while (state.furniture.length < target) {
		const cell = pickCell(state, (candidate) => isFree(state, candidate) && isSafeFromHead(state, candidate))
		if (!cell) {
			return
		}
		state.furniture.push(cell)
	}
}

function placeCrumb(state) {
	const cell = pickCell(state, (candidate) => isFree(state, candidate))
	if (!cell) {
		// Every cell is hose or furniture: the floor is clean.
		state.over = true
		state.events.push({ type: 'cleared' })
		return
	}
	state.crumb = { ...cell, golden: state.rng() < GOLDEN_CHANCE }
}

function isFree(state, cell) {
	return !hitsHose(state, cell, true) && !isFurniture(state, cell)
}

/**
 * S6: never within two cells of the nozzle and never in the next four
 * cells of its current direction or any queued turn.
 *
 * @param {object} state Game state
 * @param {{x: number, y: number}} cell Candidate cell
 * @return {boolean}
 */
export function isSafeFromHead(state, cell) {
	const head = state.snake[0]
	if (Math.max(Math.abs(cell.x - head.x), Math.abs(cell.y - head.y)) <= SAFE_RADIUS) {
		return false
	}
	for (const direction of [state.direction, ...state.turns]) {
		const delta = DIRECTIONS[direction]
		for (let distance = 1; distance <= SAFE_AHEAD; distance++) {
			if (cell.x === head.x + delta.x * distance && cell.y === head.y + delta.y * distance) {
				return false
			}
		}
	}
	return true
}

function pickCell(state, accept) {
	const candidates = []
	for (let y = 0; y < ROWS; y++) {
		for (let x = 0; x < COLS; x++) {
			const cell = { x, y }
			if (accept(cell)) {
				candidates.push(cell)
			}
		}
	}
	if (candidates.length === 0) {
		return null
	}
	return candidates[Math.floor(state.rng() * candidates.length)]
}
