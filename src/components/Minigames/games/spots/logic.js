/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { DIRECTIONS, moveCursor, tappedCell } from '../grid.js'

/**
 * Spot Sprint rules (CS-PLAN-086 §3.1): stains pop up on a tiled floor and
 * have to be wiped before they dry. A golden sponge doubles the points for
 * a few seconds; wiping a dropped sock costs points. Rounds last 60 seconds.
 * Pure and deterministic — no DOM, no timers; the random source is a
 * parameter (seeded only in Jest). `step` advances the state object it owns.
 *
 * Inputs: a direction moves the cursor, `'act'` wipes the cursor tile and
 * `{cell}` wipes that tile (a tap).
 */

export const COLS = 4
export const ROWS = 4
export const ROUND_MS = 60000

/** The deterministic opening: the first stain lies under the cursor. */
export const FIRST_CELL = 5

export const STAIN_POINTS = 10
export const SOCK_PENALTY = 20
const START_SPAWN_MS = 900
const MIN_SPAWN_MS = 450
const SPAWN_SPEEDUP_MS = 15
const START_DRY_MS = 2600
const MIN_DRY_MS = 1400
const DRY_SPEEDUP_MS = 30
/** The last part of a stain's life shows it drying. */
const DRYING_SHARE = 0.4
const SOCK_LIFE_MS = 2000
/** No socks in the first seconds of a round. */
const SOCK_FROM_MS = 10000
const SOCK_CHANCE = 0.12
const GOLDEN_CHANCE = 0.08
const DOUBLE_MS = 5000
/** A wiped tile stays clean (and sparkles) briefly before a new spawn. */
const WIPE_FLASH_MS = 250

/**
 * New round: the first stain lies under the cursor, so a tap on it or one
 * press of Enter scores without randomness.
 *
 * @param {function(): number} rng Random source returning [0, 1)
 * @return {object} Game state
 */
export function createState(rng) {
	const tiles = new Array(COLS * ROWS).fill(null)
	tiles[FIRST_CELL] = { kind: 'stain', age: 0, life: START_DRY_MS }
	return {
		rng,
		tiles,
		flashes: new Array(COLS * ROWS).fill(0),
		cursor: FIRST_CELL,
		timeLeftMs: ROUND_MS,
		sinceSpawn: 0,
		spawnMs: START_SPAWN_MS,
		dryMs: START_DRY_MS,
		doubleMs: 0,
		score: 0,
		over: false,
		// Bumped on every visible change; the shell re-reads the view then.
		revision: 0,
		// Drained by the shell after every step: pickup | penalty | timeup.
		events: [],
	}
}

/**
 * Advance the round by `dt` milliseconds, applying one input first.
 *
 * @param {object} state Game state from createState
 * @param {string|{cell: number}|null} input Direction, 'act', a tapped tile or null
 * @param {number} dt Elapsed game time in milliseconds
 * @return {object} The same state object
 */
export function step(state, input, dt) {
	if (state.over) {
		return state
	}
	if (input) {
		applyInput(state, input)
	}
	state.timeLeftMs -= dt
	if (state.doubleMs > 0) {
		state.doubleMs = Math.max(0, state.doubleMs - dt)
		if (state.doubleMs === 0) {
			state.revision++
		}
	}
	age(state, dt)
	state.sinceSpawn += dt
	while (state.sinceSpawn >= state.spawnMs) {
		state.sinceSpawn -= state.spawnMs
		spawn(state)
	}
	if (state.timeLeftMs <= 0) {
		state.timeLeftMs = 0
		state.over = true
		state.events.push({ type: 'timeup' })
		state.revision++
	}
	return state
}

function applyInput(state, input) {
	const tapped = tappedCell(input, state.tiles.length)
	if (tapped >= 0) {
		state.cursor = tapped
		state.revision++
		wipe(state, tapped)
	} else if (input === 'act') {
		wipe(state, state.cursor)
	} else if (DIRECTIONS[input]) {
		moveCursor(state, COLS, ROWS, input)
	}
}

/**
 * Wipe one tile: a stain scores (twice while the golden sponge works), the
 * golden sponge scores and starts the double points, a sock costs points
 * (never below zero). An empty tile changes nothing.
 *
 * @param {object} state Game state
 * @param {number} index Tile index
 */
function wipe(state, index) {
	const item = state.tiles[index]
	if (!item) {
		return
	}
	state.tiles[index] = null
	state.revision++
	const x = index % COLS
	const y = Math.floor(index / COLS)
	if (item.kind === 'sock') {
		state.score = Math.max(0, state.score - SOCK_PENALTY)
		state.events.push({ type: 'penalty', x, y })
		return
	}
	state.score += STAIN_POINTS * (state.doubleMs > 0 ? 2 : 1)
	state.flashes[index] = WIPE_FLASH_MS
	if (item.kind === 'golden') {
		state.doubleMs = DOUBLE_MS
	}
	state.events.push({ type: 'pickup', kind: item.kind === 'golden' ? 'bonus' : 'normal', x, y })
}

function age(state, dt) {
	for (let index = 0; index < state.tiles.length; index++) {
		if (state.flashes[index] > 0) {
			state.flashes[index] = Math.max(0, state.flashes[index] - dt)
			if (state.flashes[index] === 0) {
				state.revision++
			}
		}
		const item = state.tiles[index]
		if (!item) {
			continue
		}
		const wasDrying = isDrying(item)
		item.age += dt
		if (item.age >= item.life) {
			// Dried stains and socks simply vanish: a missed chance, no penalty.
			state.tiles[index] = null
			state.revision++
		} else if (isDrying(item) !== wasDrying) {
			state.revision++
		}
	}
}

function isDrying(item) {
	return item.kind === 'stain' && item.age >= item.life * (1 - DRYING_SHARE)
}

function spawn(state) {
	let free = 0
	for (let index = 0; index < state.tiles.length; index++) {
		if (isFree(state, index)) {
			free++
		}
	}
	const kind = pickKind(state)
	state.spawnMs = Math.max(MIN_SPAWN_MS, state.spawnMs - SPAWN_SPEEDUP_MS)
	state.dryMs = Math.max(MIN_DRY_MS, state.dryMs - DRY_SPEEDUP_MS)
	if (free === 0) {
		return
	}
	let target = Math.floor(state.rng() * free)
	for (let index = 0; index < state.tiles.length; index++) {
		if (!isFree(state, index)) {
			continue
		}
		if (target-- === 0) {
			state.tiles[index] = { kind, age: 0, life: kind === 'sock' ? SOCK_LIFE_MS : state.dryMs }
			state.revision++
			return
		}
	}
}

function isFree(state, index) {
	return state.tiles[index] === null && state.flashes[index] === 0
}

function pickKind(state) {
	const roll = state.rng()
	if (ROUND_MS - state.timeLeftMs >= SOCK_FROM_MS && roll < SOCK_CHANCE) {
		return 'sock'
	}
	if (state.doubleMs === 0 && roll >= 1 - GOLDEN_CHANCE) {
		return 'golden'
	}
	return 'stain'
}

/**
 * What the board shows, rebuilt only when `state.revision` changed.
 *
 * @param {object} state Game state
 * @return {{tiles: string[], cursor: number, double: boolean}} Tile kinds:
 *   empty | stain | drying | golden | sock | wiped
 */
export function view(state) {
	return {
		tiles: state.tiles.map((item, index) => {
			if (!item) {
				return state.flashes[index] > 0 ? 'wiped' : 'empty'
			}
			return isDrying(item) ? 'drying' : item.kind
		}),
		cursor: state.cursor,
		double: state.doubleMs > 0,
	}
}
