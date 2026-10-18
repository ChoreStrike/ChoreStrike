/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { DIRECTIONS, DIRECTION_ORDER, OPPOSITE, cellIndex, isFloor } from './rooms.js'

/**
 * Dust bunny movement for Dust Runner (CS-PLAN-086 §3.1). Bunnies leave
 * their nests one after another, roam the floor one cell per move and
 * never turn back unless the way ahead is closed. At every move a bunny
 * drifts toward the robot with the room's chase chance, otherwise it takes
 * a random open way; while catchable it drifts away instead. Pure: the
 * random source is the state's.
 */

/** The first bunny leaves its nest after this time; each further one later by the gap. */
export const RELEASE_MS = 1500
export const RELEASE_GAP_MS = 2500
/** A caught bunny waits this long in its nest once the charge has run out. */
export const RESPAWN_MS = 1500

/** One move per interval: slower than the robot, a little faster in every room. */
export const BUNNY_START_MS = 300
export const BUNNY_SPEEDUP_MS = 20
export const BUNNY_MIN_MS = 180

export const CHASE_START = 0.5
export const CHASE_STEP = 0.1
export const CHASE_MAX = 0.8

/**
 * A bunny waiting in its nest; the nest is under furniture, out of the
 * robot's reach.
 *
 * @param {{x: number, y: number}} nest Nest cell
 * @param {number} waitMs Time until it leaves
 * @return {object} Bunny
 */
export function nestedBunny(nest, waitMs) {
	return { nest, x: nest.x, y: nest.y, direction: null, waitMs, elapsed: 0, catchable: false }
}

/**
 * Back into the nest (after a catch or a lost life).
 *
 * @param {object} bunny Bunny
 * @param {number} waitMs Time until it leaves again
 */
export function sendHome(bunny, waitMs) {
	bunny.x = bunny.nest.x
	bunny.y = bunny.nest.y
	bunny.direction = null
	bunny.waitMs = waitMs
	bunny.elapsed = 0
	bunny.catchable = false
}

/**
 * @param {number} roomIndex Rooms cleared so far
 * @return {number} Milliseconds per bunny move
 */
export function bunnyInterval(roomIndex) {
	return Math.max(BUNNY_MIN_MS, BUNNY_START_MS - BUNNY_SPEEDUP_MS * roomIndex)
}

/**
 * @param {number} roomIndex Rooms cleared so far
 * @return {number} Chance that a move follows the robot's distance field
 */
export function chaseChance(roomIndex) {
	return Math.min(CHASE_MAX, CHASE_START + CHASE_STEP * roomIndex)
}

/**
 * Move a released bunny by one cell (out of its nest, or along the floor).
 *
 * @param {object} state Game state (room, distances from the robot, rng)
 * @param {object} bunny Bunny with waitMs 0
 */
export function moveBunny(state, bunny) {
	const direction = chooseDirection(state, bunny)
	if (!direction) return
	bunny.direction = direction
	bunny.x += DIRECTIONS[direction].x
	bunny.y += DIRECTIONS[direction].y
}

function canEnter(state, bunny, direction) {
	return isFloor(state.room, bunny.x + DIRECTIONS[direction].x, bunny.y + DIRECTIONS[direction].y)
}

/**
 * @param {object} state Game state
 * @param {object} bunny Bunny
 * @return {string|null} The next direction, or null when boxed in
 */
function chooseDirection(state, bunny) {
	const back = bunny.direction ? OPPOSITE[bunny.direction] : null
	let open = 0
	for (const direction of DIRECTION_ORDER) {
		if (direction !== back && canEnter(state, bunny, direction)) open++
	}
	if (open === 0) return back && canEnter(state, bunny, back) ? back : null
	if (state.rng() < chaseChance(state.roomIndex)) return drift(state, bunny, back)
	let pick = Math.floor(state.rng() * open)
	for (const direction of DIRECTION_ORDER) {
		if (direction === back || !canEnter(state, bunny, direction)) continue
		if (pick === 0) return direction
		pick--
	}
	return null
}

/**
 * The open way closest to the robot, or the farthest while catchable.
 *
 * @param {object} state Game state
 * @param {object} bunny Bunny
 * @param {string|null} back The way it came from (never taken here)
 * @return {string} Direction
 */
function drift(state, bunny, back) {
	let best = null
	let bestDistance = 0
	for (const direction of DIRECTION_ORDER) {
		if (direction === back || !canEnter(state, bunny, direction)) continue
		const distance = state.distances[cellIndex(bunny.x + DIRECTIONS[direction].x, bunny.y + DIRECTIONS[direction].y)]
		if (best === null || (bunny.catchable ? distance > bestDistance : distance < bestDistance)) {
			best = direction
			bestDistance = distance
		}
	}
	return best
}
