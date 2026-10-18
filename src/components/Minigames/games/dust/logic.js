/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { COLS, DIRECTIONS, ROWS, cellIndex, fillDistances, isFloor, roomAt } from './rooms.js'
import { RELEASE_GAP_MS, RELEASE_MS, RESPAWN_MS, bunnyInterval, moveBunny, nestedBunny, sendHome } from './bunnies.js'

/**
 * Dust Runner rules (CS-PLAN-086 §3.1): a robot vacuum clears the dust of
 * a room's floor while dust bunnies roam; meeting one costs a life. A stop
 * at the charging dock makes the bunnies catchable for a few seconds. A
 * clean floor leads into the next room (hallway, kitchen, living room,
 * then the living room again with faster bunnies). Pure and deterministic
 * — no DOM, no timers; the random source is a parameter (seeded only in
 * Jest) and only steers the bunnies. `step` advances the state object it
 * owns; bunny movement lives in `bunnies.js`, the floor plans in `rooms.js`.
 *
 * Inputs: a direction (up, down, left, right). The robot keeps going until
 * furniture stops it; a direction waits until that way is open.
 */

export const START_LIVES = 3
export const DUST_POINTS = 1
export const BUNNY_POINTS = 10
/** One robot move per interval, in every room. */
export const ROBOT_MS = 150
export const CHARGE_MS = 6000
export const DOCK_RECHARGE_MS = 20000

/**
 * New round in the hallway. The robot starts heading right along a row of
 * dust, and every bunny waits in its nest at first, so the opening scores
 * without input and without randomness.
 *
 * @param {function(): number} rng Random source returning [0, 1)
 * @return {object} Game state
 */
export function createState(rng) {
	const state = {
		rng,
		roomIndex: 0,
		room: null,
		dust: new Uint8Array(COLS * ROWS),
		dustLeft: 0,
		robot: { x: 0, y: 0, direction: 'right', wanted: null, elapsed: 0 },
		bunnies: [],
		chargeMs: 0,
		dockMs: 0,
		// Floor distances from the robot, refreshed on every robot move.
		distances: new Int16Array(COLS * ROWS),
		queue: new Int16Array(COLS * ROWS),
		lives: START_LIVES,
		score: 0,
		over: false,
		// Drained by the shell after every step: pickup | miss | cleared | crash.
		events: [],
	}
	enterRoom(state, 0)
	return state
}

/**
 * Advance the round by `dt` milliseconds, applying one input first.
 *
 * @param {object} state Game state from createState
 * @param {string|null} input A direction or null
 * @param {number} dt Elapsed game time in milliseconds
 * @return {object} The same state object
 */
export function step(state, input, dt) {
	if (state.over) return state
	if (typeof input === 'string' && DIRECTIONS[input]) state.robot.wanted = input
	tickTimers(state, dt)
	const robot = state.robot
	robot.elapsed += dt
	while (robot.elapsed >= ROBOT_MS) {
		robot.elapsed -= ROBOT_MS
		moveRobot(state)
		if (state.dustLeft === 0) {
			nextRoom(state)
			return state
		}
		if (meet(state)) return state
	}
	moveBunnies(state, dt)
	return state
}

function tickTimers(state, dt) {
	if (state.dockMs > 0) state.dockMs = Math.max(0, state.dockMs - dt)
	if (state.chargeMs === 0) return
	state.chargeMs = Math.max(0, state.chargeMs - dt)
	if (state.chargeMs > 0) return
	for (const bunny of state.bunnies) bunny.catchable = false
}

/**
 * A waiting direction turns the robot as soon as that way is open; the
 * robot then keeps going until furniture stops it.
 *
 * @param {object} state Game state
 */
function moveRobot(state) {
	const robot = state.robot
	if (robot.wanted && opens(state, robot.wanted)) {
		robot.direction = robot.wanted
		robot.wanted = null
	}
	if (!opens(state, robot.direction)) return
	robot.x += DIRECTIONS[robot.direction].x
	robot.y += DIRECTIONS[robot.direction].y
	const index = cellIndex(robot.x, robot.y)
	if (state.dust[index] === 1) {
		state.dust[index] = 0
		state.dustLeft--
		state.score += DUST_POINTS
	}
	const dock = state.room.dock
	if (robot.x === dock.x && robot.y === dock.y && state.dockMs === 0) charge(state)
	fillDistances(state.room, robot, state.distances, state.queue)
}

function opens(state, direction) {
	const robot = state.robot
	return isFloor(state.room, robot.x + DIRECTIONS[direction].x, robot.y + DIRECTIONS[direction].y)
}

/**
 * The dock charges the robot: every bunny out of its nest turns catchable,
 * and the dock needs DOCK_RECHARGE_MS before it charges again.
 *
 * @param {object} state Game state
 */
function charge(state) {
	state.chargeMs = CHARGE_MS
	state.dockMs = DOCK_RECHARGE_MS
	for (const bunny of state.bunnies) {
		if (bunny.waitMs === 0) bunny.catchable = true
	}
	state.events.push({ type: 'pickup', kind: 'bonus', x: state.robot.x, y: state.robot.y })
}

/**
 * Released bunnies move on their own clock (catchable ones at half speed);
 * a waiting bunny's clock stands still while the robot is charged.
 *
 * @param {object} state Game state
 * @param {number} dt Elapsed game time in milliseconds
 */
function moveBunnies(state, dt) {
	const interval = bunnyInterval(state.roomIndex)
	for (const bunny of state.bunnies) {
		if (bunny.waitMs > 0) {
			if (state.chargeMs === 0) bunny.waitMs = Math.max(0, bunny.waitMs - dt)
			continue
		}
		bunny.elapsed += dt
		const own = bunny.catchable ? interval * 2 : interval
		while (bunny.waitMs === 0 && bunny.elapsed >= own) {
			bunny.elapsed -= own
			moveBunny(state, bunny)
			if (meet(state)) return
		}
	}
}

/**
 * The robot meets every bunny on its cell: a catchable one is caught, any
 * other costs a life.
 *
 * @param {object} state Game state
 * @return {boolean} Whether a life was lost (positions reset or round over)
 */
function meet(state) {
	const robot = state.robot
	for (const bunny of state.bunnies) {
		if (bunny.x !== robot.x || bunny.y !== robot.y) continue
		if (!bunny.catchable) {
			loseLife(state)
			return true
		}
		state.score += BUNNY_POINTS
		state.events.push({ type: 'pickup', kind: 'normal', x: robot.x, y: robot.y })
		sendHome(bunny, RESPAWN_MS)
	}
	return false
}

function loseLife(state) {
	state.lives--
	if (state.lives > 0) {
		state.events.push({ type: 'miss' })
		placeActors(state)
		return
	}
	state.over = true
	state.events.push({ type: 'crash' })
}

function nextRoom(state) {
	state.events.push({ type: 'cleared' })
	enterRoom(state, state.roomIndex + 1)
}

/**
 * A fresh floor with all its dust, a ready dock and one bunny per nest.
 *
 * @param {object} state Game state
 * @param {number} roomIndex Rooms cleared so far
 */
function enterRoom(state, roomIndex) {
	state.roomIndex = roomIndex
	state.room = roomAt(roomIndex)
	state.dust.set(state.room.dust)
	state.dustLeft = state.room.dustCount
	state.dockMs = 0
	state.bunnies = state.room.nests.map((nest) => nestedBunny(nest, 0))
	placeActors(state)
}

/**
 * The robot back on its start heading right, the charge over, every bunny
 * in its nest, leaving one after another. Collected dust stays clean.
 *
 * @param {object} state Game state
 */
function placeActors(state) {
	const robot = state.robot
	robot.x = state.room.start.x
	robot.y = state.room.start.y
	robot.direction = 'right'
	robot.wanted = null
	robot.elapsed = 0
	state.chargeMs = 0
	state.bunnies.forEach((bunny, index) => sendHome(bunny, RELEASE_MS + RELEASE_GAP_MS * index))
	fillDistances(state.room, robot, state.distances, state.queue)
}
