/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import {
	BUBBLE_R,
	clamp,
	COLS,
	MAX_SPEED,
	SERVE_MS,
	SQUEEGEE_W,
	SQUEEGEE_Y,
	START_LIVES,
	WALL_COLS,
	WALL_ROWS,
	WALLS,
} from './constants.js'
import { moveBubbles, moveDrops } from './physics.js'

/**
 * Tile Breaker rules (CS-PLAN-086 §3.1): a squeegee bounces a soap bubble
 * against a wall of grimy tiles, and every hit takes one layer of grime
 * off a tile. A cleaned tile may drop a sponge (wider squeegee), a spray
 * bottle (extra bubbles), floor wax (slower bubbles) or a mud splash
 * (narrower squeegee). A clean wall makes room for the next, faster one;
 * the round ends when the last bubble is lost. Pure and deterministic — no
 * DOM, no timers; the random source is a parameter (seeded only in Jest)
 * and only decides the drops. `step` advances the state object it owns;
 * movement and collisions live in `physics.js`.
 *
 * Inputs: `{steer: -1|0|1}` (held arrow keys) and `{aim: 0..1}` (pointer
 * position as a share of the board width) — the latest control wins — and
 * `'act'` (launch a waiting bubble now).
 */

const STEER_SPEED = 18
const AIM_SPEED = 40
const START_SPEED = 9
const WALL_SPEEDUP = 1
/** The deterministic opening: up and slightly to the left (radians from vertical). */
const LAUNCH_ANGLE = -0.29

/**
 * New round: the first wall and a bubble waiting on the middle of the
 * squeegee. It launches by itself after SERVE_MS on a fixed path, so the
 * first cleaned tile needs no input and no randomness.
 *
 * @param {function(): number} rng Random source returning [0, 1)
 * @return {object} Game state
 */
export function createState(rng) {
	const state = {
		rng,
		wall: 0,
		tiles: new Array(WALL_COLS * WALL_ROWS).fill(0),
		tilesLeft: 0,
		squeegee: { x: COLS / 2, width: SQUEEGEE_W },
		widthMs: 0,
		waxMs: 0,
		steer: 0,
		aim: null,
		bubbles: [],
		drops: [],
		serveMs: 0,
		speed: START_SPEED,
		lives: START_LIVES,
		score: 0,
		over: false,
		// Drained by the shell after every step: pickup | penalty | bounce | miss | cleared | crash.
		events: [],
	}
	buildWall(state)
	serve(state)
	return state
}

/**
 * Advance the round by `dt` milliseconds, applying one input first.
 *
 * @param {object} state Game state from createState
 * @param {{steer: number}|{aim: number}|string|null} input A control, 'act' or null
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
	tickEffects(state, dt)
	moveSqueegee(state, dt)
	if (state.serveMs > 0) {
		state.serveMs = Math.max(0, state.serveMs - dt)
		if (state.serveMs === 0) {
			launch(state)
		}
	}
	moveBubbles(state, dt)
	moveDrops(state, dt)
	if (state.bubbles.length === 0) {
		loseBubble(state)
	} else if (state.tilesLeft === 0) {
		nextWall(state)
	}
	return state
}

function applyInput(state, input) {
	if (input === 'act') {
		launch(state)
	} else if (input.steer === -1 || input.steer === 0 || input.steer === 1) {
		state.steer = input.steer
		state.aim = null
	} else if (Number.isFinite(input.aim)) {
		state.aim = clamp(input.aim, 0, 1) * COLS
		state.steer = 0
	}
}

function buildWall(state) {
	const layout = WALLS[Math.min(state.wall, WALLS.length - 1)]
	state.tilesLeft = 0
	for (let row = 0; row < WALL_ROWS; row++) {
		for (let col = 0; col < WALL_COLS; col++) {
			const hits = Number(layout[row][col])
			state.tiles[row * WALL_COLS + col] = hits
			if (hits > 0) {
				state.tilesLeft++
			}
		}
	}
}

/**
 * A fresh bubble waits on the squeegee at the wall's base speed; running
 * effects and falling drops end.
 *
 * @param {object} state Game state
 */
function serve(state) {
	state.bubbles.length = 0
	state.bubbles.push({ x: state.squeegee.x, y: SQUEEGEE_Y - BUBBLE_R, dx: 0, dy: 0, held: true })
	state.drops.length = 0
	state.serveMs = SERVE_MS
	state.speed = Math.min(MAX_SPEED, START_SPEED + WALL_SPEEDUP * state.wall)
	state.waxMs = 0
	state.widthMs = 0
	state.squeegee.width = SQUEEGEE_W
}

function launch(state) {
	for (const bubble of state.bubbles) {
		if (!bubble.held) {
			continue
		}
		bubble.held = false
		bubble.dx = Math.sin(LAUNCH_ANGLE)
		bubble.dy = -Math.cos(LAUNCH_ANGLE)
	}
	state.serveMs = 0
}

function tickEffects(state, dt) {
	if (state.widthMs > 0) {
		state.widthMs = Math.max(0, state.widthMs - dt)
		if (state.widthMs === 0) {
			state.squeegee.width = SQUEEGEE_W
		}
	}
	if (state.waxMs > 0) {
		state.waxMs = Math.max(0, state.waxMs - dt)
	}
}

/**
 * Held keys move the squeegee at a steady speed; a pointer pulls it toward
 * its position, a little faster. A waiting bubble rides along.
 *
 * @param {object} state Game state
 * @param {number} dt Elapsed game time in milliseconds
 */
function moveSqueegee(state, dt) {
	const squeegee = state.squeegee
	let x = squeegee.x
	if (state.steer !== 0) {
		x += state.steer * STEER_SPEED * dt / 1000
	} else if (state.aim !== null) {
		const reach = AIM_SPEED * dt / 1000
		x += clamp(state.aim - x, -reach, reach)
	}
	const half = squeegee.width / 2
	squeegee.x = clamp(x, half, COLS - half)
	for (const bubble of state.bubbles) {
		if (bubble.held) {
			bubble.x = squeegee.x
		}
	}
}

function loseBubble(state) {
	state.lives--
	if (state.lives > 0) {
		state.events.push({ type: 'miss' })
		serve(state)
		return
	}
	state.over = true
	state.events.push({ type: 'crash' })
}

function nextWall(state) {
	state.wall++
	buildWall(state)
	serve(state)
	state.events.push({ type: 'cleared' })
}
