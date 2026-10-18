/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import {
	BUBBLE_R, COLS, MAX_BUBBLES, MAX_SPEED, SERVE_MS, SQUEEGEE_W, SQUEEGEE_Y, START_LIVES, TILE_H, TILE_POINTS,
	WALLS, WALL_COLS, WALL_ROWS, WALL_TOP,
} from '@/components/Minigames/games/tiles/constants.js'
import { createState, step } from '@/components/Minigames/games/tiles/logic.js'
import { seededRandom } from '../helpers/gameTestHelpers.js'

const STEP = 1000 / 60

/** A random source that never drops an item. */
const NO_DROPS = () => 0.99

/** A random source repeating `values` (drop chance, then drop kind). */
const cycle = (values) => {
	let index = 0
	return () => values[index++ % values.length]
}

/** Advance `ms` of game time in fixed steps, with an optional first input. */
const run = (state, ms, input = null) => {
	let first = input
	for (let elapsed = 0; elapsed < ms && !state.over; elapsed += STEP) {
		step(state, first, STEP)
		first = null
	}
	return state
}

/** Run exactly `count` fixed steps, with an optional first input. */
const steps = (state, count, input = null) => {
	for (let i = 0; i < count; i++) step(state, i === 0 ? input : null, STEP)
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

/** Replace the waiting bubble with one in flight (tests of single rules). */
const launched = (state, bubble) => {
	state.bubbles.length = 0
	state.bubbles.push({ held: false, ...bubble })
	state.serveMs = 0
}

/** A wall with one tile left at the bottom left (row 4, column 0). */
const lastTile = (state, hits = 1) => {
	state.tiles.fill(0)
	state.tiles[4 * WALL_COLS] = hits
	state.tilesLeft = 1
}

/** A drop falling onto the squeegee from just above it. */
const dropOnto = (state, kind, x = state.squeegee.x) => state.drops.push({ x, y: 17, kind })

/** The server's Tile Breaker rules (lib/Service/ArcadeCatalog.php). */
const SERVER = { maxScore: 50000, pointsPerSecond: 20, burstPoints: 300 }

const LAND_Y = SQUEEGEE_Y - BUBBLE_R

/** Where a falling bubble reaches the squeegee, folding in wall bounces. */
const landingX = (bubble) => {
	const x = bubble.x + bubble.dx * (LAND_Y - bubble.y) / bubble.dy
	const span = COLS - 2 * BUBBLE_R
	const folded = ((x - BUBBLE_R) % (2 * span) + 2 * span) % (2 * span)
	return BUBBLE_R + (folded <= span ? folded : 2 * span - folded)
}

/**
 * An autopilot that never misses: it launches at once, meets the next
 * falling bubble with a varying part of the squeegee (so the bounces
 * spread over the wall) and otherwise catches the helpful drops.
 */
const createAutopilot = (offsets) => {
	const plans = new WeakMap()
	return (state) => {
		if (state.bubbles.some((bubble) => bubble.held)) return 'act'
		let target = null
		for (const bubble of state.bubbles) {
			if (bubble.dy > 0 && (!target || (LAND_Y - bubble.y) / bubble.dy < (LAND_Y - target.y) / target.dy)) target = bubble
		}
		if (target) {
			let plan = plans.get(target)
			if (!plan || plan.dy !== target.dy) {
				plan = { dy: target.dy, offset: offsets() * 1.6 - 0.8 }
				plans.set(target, plan)
			}
			return { aim: (landingX(target) - plan.offset * state.squeegee.width / 2) / COLS }
		}
		const drop = state.drops.find((item) => item.kind !== 'mud')
		return drop ? { aim: drop.x / COLS } : null
	}
}

describe('Tile Breaker logic', () => {
	it('opens deterministically: a waiting bubble launches by itself and cleans the first tile', () => {
		const state = createState(NO_DROPS)

		expect(state.tiles).toHaveLength(WALL_COLS * WALL_ROWS)
		expect(state.tiles.every((hits) => hits === 1)).toBe(true)
		expect(state.lives).toBe(START_LIVES)
		expect(state.bubbles).toEqual([expect.objectContaining({ x: COLS / 2, held: true })])

		run(state, SERVE_MS - STEP)
		expect(state.bubbles[0].held).toBe(true)
		const events = runUntil(state, () => state.score > 0)

		expect(state.score).toBe(TILE_POINTS)
		expect(events).toHaveLength(1)
		expect(events[0]).toMatchObject({ type: 'pickup', kind: 'normal', x: 5 })
		expect(events[0].y).toBeCloseTo(WALL_TOP + 4.5 * TILE_H)
		expect(state.tiles[4 * WALL_COLS + 2]).toBe(0)
	})

	it('Enter launches the waiting bubble at once', () => {
		const state = createState(NO_DROPS)

		step(state, 'act', STEP)

		expect(state.bubbles[0].held).toBe(false)
		expect(state.bubbles[0].dy).toBeLessThan(0)
		expect(state.serveMs).toBe(0)
	})

	it('without input the opening bubble misses the squeegee: one life less, a new bubble waits', () => {
		const state = createState(NO_DROPS)

		const events = runUntil(state, () => state.lives < START_LIVES)

		expect(events.map((event) => event.type)).toEqual(['pickup', 'miss'])
		expect(state.score).toBe(TILE_POINTS)
		expect(state.bubbles).toEqual([expect.objectContaining({ held: true })])
		expect(state.serveMs).toBe(SERVE_MS)
	})

	it('a round without input ends after the third lost bubble, whatever drops fall', () => {
		const sources = [NO_DROPS, cycle([0, 0]), cycle([0, 0.3]), cycle([0, 0.6]), cycle([0, 0.9]), seededRandom(1), seededRandom(2)]
		for (const rng of sources) {
			const state = createState(rng)

			const events = runUntil(state, () => state.over, 60000)

			expect(state.lives).toBe(0)
			expect(state.score).toBe(60)
			expect(events.filter((event) => event.type === 'miss')).toHaveLength(START_LIVES - 1)
			expect(events.at(-1)).toEqual({ type: 'crash' })
			step(state, 'act', STEP)
			step(state, { steer: 1 }, STEP)
			expect(state.score).toBe(60)
			expect(state.squeegee.x).toBe(COLS / 2)
		}
	})

	it.each([
		['held keys', { steer: -1 }],
		['the pointer', { aim: 0 }],
	])('after the first tile, moving the squeegee to the left wall with %s catches the bubble', (_, control) => {
		const state = createState(NO_DROPS)
		runUntil(state, () => state.score > 0)

		step(state, control, STEP)
		runUntil(state, () => state.score > TILE_POINTS)

		expect(state.lives).toBe(START_LIVES)
		expect(state.squeegee.x).toBe(SQUEEGEE_W / 2)
	})

	it('held keys move the squeegee steadily up to the walls; a waiting bubble rides along', () => {
		const state = createState(NO_DROPS)
		state.serveMs = Number.POSITIVE_INFINITY

		// 18 cells per second: twelve steps (0.2 s) move it 3.6 cells.
		steps(state, 12, { steer: 1 })
		expect(state.squeegee.x).toBeCloseTo(COLS / 2 + 3.6)
		expect(state.bubbles[0].x).toBe(state.squeegee.x)

		run(state, 500)
		expect(state.squeegee.x).toBe(COLS - SQUEEGEE_W / 2)

		step(state, { steer: 0 }, STEP)
		run(state, 200)
		expect(state.squeegee.x).toBe(COLS - SQUEEGEE_W / 2)

		run(state, 100, { steer: -1 })
		expect(state.squeegee.x).toBeLessThan(COLS - SQUEEGEE_W / 2)
	})

	it('the pointer pulls the squeegee to its position, and the latest control wins', () => {
		const state = createState(NO_DROPS)
		state.serveMs = Number.POSITIVE_INFINITY

		run(state, 300, { aim: 0.25 })
		expect(state.squeegee.x).toBeCloseTo(COLS / 4)

		step(state, { steer: 1 }, STEP)
		expect(state.aim).toBeNull()
		run(state, 100)
		expect(state.squeegee.x).toBeGreaterThan(COLS / 4)

		step(state, { aim: -3 }, STEP)
		expect(state.steer).toBe(0)
		run(state, 500)
		expect(state.squeegee.x).toBe(SQUEEGEE_W / 2)
	})

	it('ignores inputs it does not know', () => {
		const state = createState(NO_DROPS)
		state.serveMs = Number.POSITIVE_INFINITY

		for (const input of [{ steer: 2 }, { aim: Number.NaN }, 'up', { cell: 3 }]) step(state, input, STEP)

		expect(state.squeegee.x).toBe(COLS / 2)
		expect(state.steer).toBe(0)
		expect(state.aim).toBeNull()
	})

	it('the bounce follows where the bubble lands: never straight up, up to 60 degrees at the edges', () => {
		const angleAfterLanding = (x) => {
			const state = createState(NO_DROPS)
			launched(state, { x, y: 17.9, dx: 0, dy: 1 })
			run(state, 50)
			return state.bubbles[0]
		}

		const middle = angleAfterLanding(COLS / 2)
		expect(middle.dy).toBeLessThan(0)
		expect(Math.abs(middle.dx)).toBeGreaterThan(0.15)
		expect(angleAfterLanding(COLS / 2 + SQUEEGEE_W / 2).dx).toBeCloseTo(Math.sin(Math.PI / 3))
		expect(angleAfterLanding(COLS / 2 - SQUEEGEE_W / 2).dx).toBeCloseTo(-Math.sin(Math.PI / 3))
	})

	it('a tile with two layers of grime needs two hits, and each hit scores', () => {
		const state = createState(NO_DROPS)
		lastTile(state, 2)

		launched(state, { x: 1, y: 8, dx: 0, dy: -1 })
		runUntil(state, () => state.score === TILE_POINTS)
		expect(state.tiles[4 * WALL_COLS]).toBe(1)
		expect(state.tilesLeft).toBe(1)

		launched(state, { x: 1, y: 8, dx: 0, dy: -1 })
		runUntil(state, () => state.score === 2 * TILE_POINTS)
		expect(state.wall).toBe(1)
	})

	it('a clean wall brings the next, faster one with a waiting bubble; the last layout repeats', () => {
		const state = createState(NO_DROPS)
		lastTile(state)
		launched(state, { x: 1, y: 8, dx: 0, dy: -1 })

		const events = runUntil(state, () => state.wall === 1)

		expect(events.map((event) => event.type)).toEqual(['pickup', 'cleared'])
		expect(state.tiles.join('')).toBe(WALLS[1].join(''))
		expect(state.tilesLeft).toBe(WALL_COLS * WALL_ROWS)
		expect(state.speed).toBe(10)
		expect(state.bubbles).toEqual([expect.objectContaining({ held: true })])
		expect(state.lives).toBe(START_LIVES)

		state.wall = 9
		lastTile(state)
		launched(state, { x: 1, y: 8, dx: 0, dy: -1 })
		runUntil(state, () => state.wall === 10)
		expect(state.tiles.join('')).toBe(WALLS.at(-1).join(''))
		expect(state.speed).toBe(MAX_SPEED)
	})

	it('a cleaned tile may drop an item that falls straight down; a missed one vanishes', () => {
		const state = createState(cycle([0, 0.3]))
		runUntil(state, () => state.score > 0)

		expect(state.drops).toEqual([expect.objectContaining({ x: 5, kind: 'spray' })])
		const y = state.drops[0].y
		run(state, 200)
		expect(state.drops[0].x).toBe(5)
		expect(state.drops[0].y).toBeGreaterThan(y)

		runUntil(state, () => state.drops.length === 0)
		expect(state.bubbles.length).toBeLessThanOrEqual(1)
	})

	it('a sponge widens and mud narrows the squeegee for a while; the later one replaces the other', () => {
		const state = createState(NO_DROPS)
		state.serveMs = Number.POSITIVE_INFINITY

		dropOnto(state, 'sponge')
		const caught = runUntil(state, () => state.drops.length === 0)
		expect(caught).toEqual([expect.objectContaining({ type: 'pickup', kind: 'bonus' })])
		expect(state.squeegee.width).toBe(4.5)

		dropOnto(state, 'mud')
		const splashed = runUntil(state, () => state.drops.length === 0)
		expect(splashed).toEqual([expect.objectContaining({ type: 'penalty' })])
		expect(state.squeegee.width).toBe(2)

		run(state, 8000)
		expect(state.squeegee.width).toBe(SQUEEGEE_W)
	})

	it('floor wax slows every bubble for eight seconds', () => {
		const distance = (wax) => {
			const state = createState(NO_DROPS)
			state.waxMs = wax
			launched(state, { x: 8, y: 12, dx: 0, dy: -1 })
			step(state, null, STEP)
			return 12 - state.bubbles[0].y
		}
		const state = createState(NO_DROPS)
		state.serveMs = Number.POSITIVE_INFINITY

		dropOnto(state, 'wax')
		runUntil(state, () => state.drops.length === 0)

		expect(state.waxMs).toBeGreaterThan(7500)
		expect(distance(1000)).toBeCloseTo(distance(0) * 0.7)
		run(state, 8000)
		expect(state.waxMs).toBe(0)
	})

	it('a spray bottle adds bubbles up to three in play', () => {
		const state = createState(NO_DROPS)
		state.serveMs = Number.POSITIVE_INFINITY

		dropOnto(state, 'spray')
		runUntil(state, () => state.drops.length === 0)
		expect(state.bubbles).toHaveLength(MAX_BUBBLES)
		expect(state.bubbles.filter((bubble) => !bubble.held && bubble.dy < 0)).toHaveLength(2)

		dropOnto(state, 'spray')
		runUntil(state, () => state.drops.length === 0)
		expect(state.bubbles).toHaveLength(MAX_BUBBLES)
	})

	it('losing the last bubble ends running effects and falling drops', () => {
		const state = createState(NO_DROPS)
		state.squeegee.width = 4.5
		state.widthMs = 5000
		state.waxMs = 5000
		state.drops.push({ x: 1, y: 5, kind: 'mud' })
		launched(state, { x: 1, y: 19, dx: 0, dy: 1 })

		runUntil(state, () => state.lives < START_LIVES)

		expect(state.squeegee.width).toBe(SQUEEGEE_W)
		expect(state.widthMs).toBe(0)
		expect(state.waxMs).toBe(0)
		expect(state.drops).toEqual([])
	})

	it('an autopilot that never misses stays inside the server plausibility rules', () => {
		for (const seed of [1, 2, 3]) {
			const state = createState(seededRandom(seed))
			const autopilot = createAutopilot(seededRandom(seed * 97))
			let worst = Number.NEGATIVE_INFINITY
			for (let elapsed = STEP; elapsed <= 300000; elapsed += STEP) {
				step(state, autopilot(state), STEP)
				state.events.length = 0
				worst = Math.max(worst, state.score - (SERVER.burstPoints + Math.floor(SERVER.pointsPerSecond * elapsed / 1000)))
			}

			expect(state.lives).toBe(START_LIVES)
			expect(state.score).toBeGreaterThan(900)
			expect(worst).toBeLessThanOrEqual(0)
			expect(state.score).toBeLessThan(SERVER.maxScore)
		}
	})
})
