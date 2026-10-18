/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest } from '@jest/globals'
import { COLS, DIRECTIONS, DIRECTION_ORDER, cellIndex, isFloor } from '@/components/Minigames/games/dust/rooms.js'
import {
	BUNNY_MIN_MS, BUNNY_START_MS, CHASE_MAX, RELEASE_GAP_MS, RELEASE_MS, RESPAWN_MS, bunnyInterval, chaseChance, sendHome,
} from '@/components/Minigames/games/dust/bunnies.js'
import {
	BUNNY_POINTS, CHARGE_MS, DOCK_RECHARGE_MS, ROBOT_MS, START_LIVES, createState, nextMove, step,
} from '@/components/Minigames/games/dust/logic.js'
import { seededRandom } from '../helpers/gameTestHelpers.js'

/** Whole milliseconds keep every move time exact (the shell steps 1000 / 60). */
const STEP = 10

/** Always drift (chase or flee); a random pick takes the first open way. */
const ALWAYS_DRIFT = () => 0

/** Advance `ms` of game time in fixed steps, with an optional first input. */
const run = (state, ms, input = null) => {
	let first = input
	for (let elapsed = 0; elapsed < ms && !state.over; elapsed += STEP) {
		step(state, first, STEP)
		first = null
	}
	return state
}

/** Step until `done(state)` holds and answer the events of the way there. */
const runUntil = (state, done, limitMs = 30000) => {
	const events = []
	for (let elapsed = 0; elapsed < limitMs && !done(state); elapsed += STEP) {
		step(state, null, STEP)
		events.push(...state.events)
		state.events.length = 0
	}
	expect(done(state)).toBe(true)
	return events
}

/** The hallway's opening: the robot drives its start row to the right wall and stops there. */
const parked = (rng) => run(createState(rng), 12 * ROBOT_MS)

const at = (actor) => ({ x: actor.x, y: actor.y })
const distanceOf = (state, actor) => state.distances[cellIndex(actor.x, actor.y)]

/** Put the robot on a floor cell heading `direction` (tests of single rules). */
const placeRobot = (state, x, y, direction) => {
	Object.assign(state.robot, { x, y, direction, wanted: null, elapsed: 0 })
}

/** Release a bunny onto a floor cell. */
const placeBunny = (bunny, x, y, catchable = false) => {
	Object.assign(bunny, { x, y, direction: null, waitMs: 0, elapsed: 0, catchable })
}

/** The server's Dust Runner rules (lib/Service/ArcadeCatalog.php). */
const SERVER = { maxScore: 36000, pointsPerSecond: 10, burstPoints: 100 }

/** The first move along a shortest floor path from the robot to `target`. */
const firstStep = (state, target) => {
	let { x, y } = target
	while (distanceOf(state, { x, y }) > 1) {
		const distance = distanceOf(state, { x, y })
		const way = DIRECTION_ORDER.find((direction) => isFloor(state.room, x + DIRECTIONS[direction].x, y + DIRECTIONS[direction].y)
			&& distanceOf(state, { x: x + DIRECTIONS[direction].x, y: y + DIRECTIONS[direction].y }) === distance - 1)
		x += DIRECTIONS[way].x
		y += DIRECTIONS[way].y
	}
	return DIRECTION_ORDER.find((direction) => state.robot.x + DIRECTIONS[direction].x === x && state.robot.y + DIRECTIONS[direction].y === y)
}

/**
 * An autopilot that never minds the bunnies: it docks whenever the dock is
 * ready, chases the nearest catchable bunny while charged and otherwise
 * heads for the nearest dust.
 */
const autopilot = (state) => {
	const nearest = (candidates) => {
		let best = null
		for (const candidate of candidates) {
			const distance = distanceOf(state, candidate)
			if (distance > 0 && (best === null || distance < distanceOf(state, best))) best = candidate
		}
		return best
	}
	let target = state.dockMs === 0 ? state.room.dock : null
	if (!target && state.chargeMs > 0) target = nearest(state.bunnies.filter((bunny) => bunny.catchable))
	if (!target) {
		const dust = []
		state.dust.forEach((value, index) => value === 1 && dust.push({ x: index % COLS, y: Math.floor(index / COLS) }))
		target = nearest(dust)
	}
	return target ? firstStep(state, target) : null
}

describe('Dust Runner logic', () => {
	it('opens deterministically: the robot clears its start row and stops at the wall before any bunny moves', () => {
		const rng = jest.fn(() => 0.5)
		const state = createState(rng)

		expect(at(state.robot)).toEqual({ x: 1, y: 1 })
		expect(state.robot.direction).toBe('right')
		expect(state.lives).toBe(START_LIVES)
		expect(state.bunnies.map((bunny) => bunny.waitMs)).toEqual([RELEASE_MS, RELEASE_MS + RELEASE_GAP_MS])

		run(state, ROBOT_MS)
		expect(state.score).toBe(1)
		run(state, 8 * ROBOT_MS)
		expect(state.score).toBe(9)
		expect(rng).not.toHaveBeenCalled()
		run(state, 3 * ROBOT_MS + 200)
		expect(state.score).toBe(12)
		expect(at(state.robot)).toEqual({ x: 13, y: 1 })
		// Collected dust is a visual event only (WP-G): no pickup, miss or crash.
		expect(state.events.map((event) => event.type)).toEqual(new Array(12).fill('clean'))
		expect(state.events[0]).toEqual({ type: 'clean', x: 2, y: 1 })
	})

	it('names the next move for the renderer: a waiting direction once open, the current way while open, nothing at furniture', () => {
		const state = createState(seededRandom(1))
		expect(nextMove(state)).toBe('right')

		// Up is the wall: the direction waits and the robot keeps right.
		step(state, 'up', STEP)
		expect(state.robot.wanted).toBe('up')
		expect(nextMove(state)).toBe('right')
		step(state, 'down', STEP)
		expect(nextMove(state)).toBe('down')

		placeRobot(state, 13, 1, 'right')
		expect(nextMove(state)).toBeNull()
	})

	it('remembers the cell a bunny comes from, so the renderer can draw its hop', () => {
		const state = parked(ALWAYS_DRIFT)
		const [bunny] = state.bunnies
		expect([bunny.fromX, bunny.fromY]).toEqual([bunny.nest.x, bunny.nest.y])

		runUntil(state, () => bunny.waitMs === 0 && bunny.direction !== null)
		expect([bunny.fromX, bunny.fromY]).toEqual([bunny.nest.x, bunny.nest.y])
		const before = at(bunny)
		runUntil(state, () => bunny.x !== before.x || bunny.y !== before.y)
		expect([bunny.fromX, bunny.fromY]).toEqual([before.x, before.y])

		sendHome(bunny, RESPAWN_MS)
		expect([bunny.fromX, bunny.fromY]).toEqual([bunny.nest.x, bunny.nest.y])
	})

	it('turns as soon as a waiting direction opens and keeps going until furniture stops it', () => {
		const state = parked(seededRandom(1))

		run(state, ROBOT_MS, 'down')
		expect(at(state.robot)).toEqual({ x: 13, y: 2 })
		expect(state.score).toBe(13)
		expect(state.robot.wanted).toBeNull()

		// Left is closed beside the plant: the direction waits, the robot goes on down.
		placeRobot(state, 13, 9, 'down')
		run(state, ROBOT_MS, 'left')
		expect(at(state.robot)).toEqual({ x: 13, y: 10 })
		expect(state.robot.wanted).toBe('left')
		run(state, ROBOT_MS)
		expect(at(state.robot)).toEqual({ x: 12, y: 10 })
		expect(state.robot.direction).toBe('left')
		expect(state.robot.wanted).toBeNull()
	})

	it('a newer direction replaces a waiting one, and the robot turns back at once', () => {
		const state = createState(seededRandom(1))
		run(state, 3 * ROBOT_MS)

		step(state, 'up', STEP)
		step(state, 'left', STEP)
		run(state, ROBOT_MS)

		expect(at(state.robot)).toEqual({ x: 3, y: 1 })
		expect(state.robot.direction).toBe('left')
		expect(state.robot.wanted).toBeNull()
	})

	it('ignores inputs it does not know', () => {
		const state = createState(seededRandom(1))

		for (const input of ['act', { cell: 3 }, { steer: 1 }, 'north']) step(state, input, STEP)

		expect(state.robot.wanted).toBeNull()
		expect(state.robot.direction).toBe('right')
	})

	it('bunnies leave their nests one after another and roam the floor without ever turning back', () => {
		for (const seed of [1, 2, 3]) {
			const state = createState(seededRandom(seed))
			const [first, second] = state.bunnies
			runUntil(state, () => first.waitMs === 0)
			expect(second.waitMs).toBe(RELEASE_GAP_MS)

			let previous = state.bunnies.map(at)
			const directions = state.bunnies.map(() => null)
			for (let elapsed = 0; elapsed < 30000; elapsed += STEP) {
				step(state, null, STEP)
				// A lost life sends every bunny home; the roaming ends there.
				if (state.lives < START_LIVES) break
				state.bunnies.forEach((bunny, index) => {
					const moved = Math.abs(bunny.x - previous[index].x) + Math.abs(bunny.y - previous[index].y)
					if (moved === 0) return
					expect(moved).toBe(1)
					expect(isFloor(state.room, bunny.x, bunny.y)).toBe(true)
					expect(bunny.direction).not.toBe({ up: 'down', down: 'up', left: 'right', right: 'left' }[directions[index]])
					directions[index] = bunny.direction
				})
				previous = state.bunnies.map(at)
			}
		}
	})

	it('a chasing bunny comes one cell closer with every move, and meeting it costs a life', () => {
		const state = parked(ALWAYS_DRIFT)
		const [first] = state.bunnies
		runUntil(state, () => first.waitMs === 0 && first.direction !== null)

		let distance = distanceOf(state, first)
		const events = []
		while (state.lives === START_LIVES) {
			step(state, null, STEP)
			events.push(...state.events)
			state.events.length = 0
			if (state.lives === START_LIVES && distanceOf(state, first) !== distance) {
				expect(distanceOf(state, first)).toBe(distance - 1)
				distance = distanceOf(state, first)
			}
		}

		expect(distance).toBe(1)
		expect(events.filter((event) => event.type !== 'clean')).toEqual([{ type: 'miss' }])
		expect(at(state.robot)).toEqual({ x: 1, y: 1 })
		expect(state.robot.direction).toBe('right')
		expect(state.bunnies.map((bunny) => bunny.waitMs)).toEqual([RELEASE_MS, RELEASE_MS + RELEASE_GAP_MS])
		expect(state.bunnies.map(at)).toEqual(state.bunnies.map((bunny) => bunny.nest))
		// Collected dust stays clean: the start row scores no second time.
		run(state, 12 * ROBOT_MS)
		expect(state.score).toBe(12)
	})

	it('a round without input ends after the third lost life at 12 points, whatever the bunnies do', () => {
		for (const rng of [ALWAYS_DRIFT, seededRandom(1), seededRandom(2), seededRandom(3), seededRandom(4)]) {
			const state = createState(rng)

			// As the shell steps the round.
			const events = []
			for (let elapsed = 0; elapsed < 180000 && !state.over; elapsed += 1000 / 60) {
				step(state, null, 1000 / 60)
				events.push(...state.events)
				state.events.length = 0
			}

			expect(state.lives).toBe(0)
			expect(state.score).toBe(12)
			expect(events.filter((event) => event.type === 'miss')).toHaveLength(START_LIVES - 1)
			expect(events.at(-1)).toEqual({ type: 'crash' })
			step(state, 'down', STEP)
			run(state, 1000)
			expect(state.score).toBe(12)
		}
	})

	it('a ready dock charges the robot: roaming bunnies turn catchable, and the dock recharges', () => {
		const state = createState(seededRandom(1))
		const [roaming, waiting] = state.bunnies
		placeBunny(roaming, 1, 10)
		placeRobot(state, 9, 12, 'right')

		const events = runUntil(state, () => state.chargeMs > 0, 1000)

		expect(events).toEqual([{ type: 'pickup', kind: 'bonus', x: 10, y: 12 }])
		expect(state.chargeMs).toBeGreaterThan(CHARGE_MS - 100)
		expect(state.dockMs).toBeGreaterThan(DOCK_RECHARGE_MS - 100)
		expect(roaming.catchable).toBe(true)
		expect(waiting.catchable).toBe(false)

		// Back on and off the dock while it recharges: no second charge.
		run(state, ROBOT_MS, 'left')
		run(state, ROBOT_MS, 'right')
		expect(at(state.robot)).toEqual({ x: 10, y: 12 })
		expect(state.chargeMs).toBeLessThan(CHARGE_MS - 100)

		run(state, CHARGE_MS)
		expect(state.chargeMs).toBe(0)
		expect(state.bunnies.every((bunny) => !bunny.catchable)).toBe(true)
	})

	it('a caught bunny scores and waits in its nest until the charge has run out', () => {
		const state = createState(seededRandom(1))
		const [bunny] = state.bunnies
		state.chargeMs = CHARGE_MS
		state.dockMs = DOCK_RECHARGE_MS
		// In the robot's way; the robot moves first, so it gets there first.
		placeBunny(bunny, 5, 1, true)

		const events = runUntil(state, () => state.score > 4, 1000)

		expect(events).toContainEqual({ type: 'pickup', kind: 'normal', x: 5, y: 1 })
		expect(state.score).toBe(4 + BUNNY_POINTS)
		expect(state.lives).toBe(START_LIVES)
		expect(at(bunny)).toEqual(bunny.nest)
		expect(bunny.waitMs).toBe(RESPAWN_MS)
		run(state, 2000)
		expect(bunny.waitMs).toBe(RESPAWN_MS)

		runUntil(state, () => state.chargeMs === 0, CHARGE_MS)
		runUntil(state, () => bunny.waitMs === 0, RESPAWN_MS + 100)
		expect(bunny.catchable).toBe(false)
	})

	it('catchable bunnies move at half speed and drift away from the robot', () => {
		const state = parked(ALWAYS_DRIFT)
		const [bunny] = state.bunnies
		state.chargeMs = 60000
		placeBunny(bunny, 9, 4, true)
		let distance = distanceOf(state, bunny)
		let moves = 0

		// Neighbouring floor cells always differ by one in distance, so every move shows.
		for (let elapsed = 0; elapsed < 6000; elapsed += STEP) {
			step(state, null, STEP)
			if (distanceOf(state, bunny) === distance) continue
			moves++
			distance = distanceOf(state, bunny)
		}

		expect(moves).toBe(Math.floor(6000 / (2 * BUNNY_START_MS)))
		expect(distance).toBeGreaterThan(distanceOf(state, { x: 9, y: 4 }))
	})

	it('a clean floor leads into the next room with all its dust, a ready dock and faster bunnies', () => {
		const state = createState(seededRandom(1))
		state.dust.fill(0)
		state.dust[cellIndex(2, 1)] = 1
		state.dustLeft = 1
		state.dockMs = 5000
		state.lives = 2

		const events = runUntil(state, () => state.roomIndex === 1, 1000)

		expect(events).toEqual([{ type: 'clean', x: 2, y: 1 }, { type: 'cleared' }])
		expect(state.score).toBe(1)
		expect(state.dustLeft).toBe(120)
		expect(state.dockMs).toBe(0)
		expect(state.lives).toBe(2)
		expect(state.bunnies).toHaveLength(3)
		expect(at(state.robot)).toEqual({ x: 1, y: 2 })
		expect(bunnyInterval(1)).toBe(BUNNY_START_MS - 20)
		expect(chaseChance(1)).toBeCloseTo(0.6)
	})

	it('repeats the living room once every room is clean, up to the fastest bunnies', () => {
		const state = createState(seededRandom(1))
		state.roomIndex = 4
		state.dust.fill(0)
		state.dust[cellIndex(2, 1)] = 1
		state.dustLeft = 1

		runUntil(state, () => state.roomIndex === 5, 1000)

		expect(state.dustLeft).toBe(119)
		expect(at(state.robot)).toEqual({ x: 1, y: 13 })
		expect(bunnyInterval(5)).toBe(200)
		expect(bunnyInterval(20)).toBe(BUNNY_MIN_MS)
		expect(chaseChance(20)).toBe(CHASE_MAX)
	})

	it('an autopilot that docks and catches at every chance stays inside the server plausibility rules', () => {
		for (const seed of [1, 2, 3]) {
			const state = createState(seededRandom(seed))
			// Bunnies end lives, not the measurement.
			state.lives = Number.POSITIVE_INFINITY
			let worst = Number.NEGATIVE_INFINITY
			let rooms = 0
			let catches = 0
			for (let elapsed = STEP; elapsed <= 300000; elapsed += STEP) {
				step(state, autopilot(state), STEP)
				for (const event of state.events) {
					if (event.type === 'cleared') rooms++
					if (event.type === 'pickup' && event.kind === 'normal') catches++
				}
				state.events.length = 0
				worst = Math.max(worst, state.score - (SERVER.burstPoints + Math.floor(SERVER.pointsPerSecond * elapsed / 1000)))
			}

			expect(rooms).toBeGreaterThan(3)
			expect(catches).toBeGreaterThan(5)
			expect(worst).toBeLessThanOrEqual(0)
			expect(state.score).toBeLessThan(SERVER.maxScore)
		}
	})
})
