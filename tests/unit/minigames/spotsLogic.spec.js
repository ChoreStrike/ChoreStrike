/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { COLS, FIRST_CELL, ROUND_MS, ROWS, SOCK_PENALTY, STAIN_POINTS, createState, step, view } from '@/components/Minigames/games/spots/logic.js'
import { seededRandom } from '../helpers/gameTestHelpers.js'

const STEP = 1000 / 60

/** A state whose random source must not be touched. */
const quietState = () => createState(() => {
	throw new Error('no random draw expected here')
})

/** Advance `ms` of game time in fixed steps, with an optional first input. */
const run = (state, ms, input = null) => {
	let first = input
	for (let elapsed = 0; elapsed < ms && !state.over; elapsed += STEP) {
		step(state, first, STEP)
		first = null
	}
	return state
}

/** Place an item directly (tests of single rules, independent of spawning). */
const place = (state, index, kind, life = 2600) => {
	state.tiles[index] = { kind, age: 0, life }
}

/** The server's Spot Sprint rules (lib/Service/ArcadeCatalog.php). */
const SERVER = { maxScore: 3000, pointsPerSecond: 50, burstPoints: 100 }

describe('Spot Sprint logic', () => {
	it('opens deterministically: the first stain lies under the cursor on a 4x4 floor', () => {
		const state = quietState()

		expect(state.tiles).toHaveLength(COLS * ROWS)
		expect(state.cursor).toBe(FIRST_CELL)
		expect(view(state).tiles[FIRST_CELL]).toBe('stain')
		expect(view(state).tiles.filter((kind) => kind !== 'empty')).toHaveLength(1)

		step(state, 'act', STEP)

		expect(state.score).toBe(STAIN_POINTS)
		expect(state.events).toEqual([{ type: 'pickup', kind: 'normal', x: 1, y: 1 }])
		expect(view(state).tiles[FIRST_CELL]).toBe('wiped')
	})

	it('wipes a tapped tile and moves the cursor there', () => {
		const state = quietState()
		place(state, 14, 'stain')

		step(state, { cell: 14 }, STEP)

		expect(state.cursor).toBe(14)
		expect(state.score).toBe(STAIN_POINTS)
		expect(state.tiles[14]).toBeNull()
	})

	it('ignores taps on empty tiles and out-of-range cells', () => {
		const state = quietState()
		step(state, { cell: 0 }, STEP)
		step(state, { cell: 99 }, STEP)
		step(state, { cell: -1 }, STEP)

		expect(state.score).toBe(0)
		expect(state.cursor).toBe(0)
	})

	it('moves the cursor with directions and stops at the walls', () => {
		const state = quietState()
		step(state, 'up', STEP)
		expect(state.cursor).toBe(1)
		const revision = state.revision
		step(state, 'up', STEP)
		expect(state.cursor).toBe(1)
		expect(state.revision).toBe(revision)
		step(state, 'left', STEP)
		step(state, 'left', STEP)
		expect(state.cursor).toBe(0)
		step(state, 'down', STEP)
		step(state, 'right', STEP)
		expect(state.cursor).toBe(5)
	})

	it('a stain dries: it shows as drying, then vanishes without points or penalty', () => {
		const state = quietState()
		state.spawnMs = Number.POSITIVE_INFINITY

		run(state, 1700)
		expect(view(state).tiles[FIRST_CELL]).toBe('drying')
		run(state, 1000)

		expect(state.tiles[FIRST_CELL]).toBeNull()
		expect(view(state).tiles[FIRST_CELL]).toBe('empty')
		expect(state.score).toBe(0)
	})

	it('the golden sponge doubles the points for five seconds', () => {
		const state = quietState()
		state.spawnMs = Number.POSITIVE_INFINITY
		place(state, 0, 'golden')
		place(state, 1, 'stain', 20000)
		place(state, 2, 'stain', 20000)

		step(state, { cell: 0 }, STEP)
		expect(state.score).toBe(STAIN_POINTS)
		expect(state.events.at(-1)).toMatchObject({ type: 'pickup', kind: 'bonus' })
		expect(view(state).double).toBe(true)

		step(state, { cell: 1 }, STEP)
		expect(state.score).toBe(3 * STAIN_POINTS)

		run(state, 5000)
		expect(view(state).double).toBe(false)
		step(state, { cell: 2 }, STEP)
		expect(state.score).toBe(4 * STAIN_POINTS)
	})

	it('a sock costs points, never below zero, and makes no pickup', () => {
		const state = quietState()
		place(state, 0, 'sock')
		place(state, 1, 'sock')
		state.score = 25

		step(state, { cell: 0 }, STEP)
		expect(state.score).toBe(25 - SOCK_PENALTY)
		step(state, { cell: 1 }, STEP)
		expect(state.score).toBe(0)
		expect(state.events.map((event) => event.type)).toEqual(['penalty', 'penalty'])
	})

	it('spawns faster over time and drops no sock in the first ten seconds', () => {
		const state = createState(seededRandom(7))
		const kinds = new Set()
		for (let elapsed = 0; elapsed < 10000; elapsed += STEP) {
			step(state, null, STEP)
			for (const item of state.tiles) if (item) kinds.add(item.kind)
		}

		expect(kinds.has('sock')).toBe(false)
		expect(state.spawnMs).toBeLessThan(900)
		expect(state.spawnMs).toBeGreaterThanOrEqual(450)
	})

	it('never spawns on a tile that still shows its wipe', () => {
		const state = quietState()
		state.rng = () => 0
		step(state, 'act', STEP)
		state.sinceSpawn = state.spawnMs

		step(state, null, STEP)

		expect(state.tiles[FIRST_CELL]).toBeNull()
		expect(state.tiles[0]).not.toBeNull()
	})

	it('ends after sixty seconds with a timeup event', () => {
		const state = createState(seededRandom(3))
		run(state, ROUND_MS - 100)
		expect(state.over).toBe(false)

		run(state, 200)

		expect(state.over).toBe(true)
		expect(state.timeLeftMs).toBe(0)
		expect(state.events.at(-1)).toEqual({ type: 'timeup' })
		const score = state.score
		step(state, 'act', STEP)
		expect(state.score).toBe(score)
	})

	it('bumps the revision only for visible changes', () => {
		const state = quietState()
		state.spawnMs = Number.POSITIVE_INFINITY
		const revision = state.revision

		step(state, null, STEP)
		expect(state.revision).toBe(revision)
		step(state, 'act', STEP)
		expect(state.revision).toBeGreaterThan(revision)
	})

	it('a perfect player stays inside the server plausibility rules', () => {
		for (const seed of [1, 2, 3, 4, 5]) {
			const state = createState(seededRandom(seed))
			let elapsed = 0
			while (!state.over) {
				const target = state.tiles.findIndex((item) => item && item.kind !== 'sock')
				step(state, target >= 0 ? { cell: target } : null, STEP)
				elapsed += STEP
				expect(state.score).toBeLessThanOrEqual(SERVER.burstPoints + Math.floor(SERVER.pointsPerSecond * elapsed / 1000))
			}
			expect(state.score).toBeGreaterThan(500)
			expect(state.score).toBeLessThanOrEqual(SERVER.maxScore)
		}
	})
})
