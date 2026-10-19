/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { COLS, DIRECTIONS, ROWS, createState, isSafeFromHead, step } from '@/components/Minigames/games/snake/logic.js'
import { seededRandom } from '../helpers/gameTestHelpers.js'

const TICK = 150

/** The server's Crumb Snake rules (lib/Service/ArcadeCatalog.php). */
const SERVER = { maxScore: 20000, pointsPerSecond: 20, burstPoints: 100 }

/** The cells the next move cannot enter: furniture and the hose except its leaving tail. */
const blockedCells = (state) => {
	const cells = new Set(state.furniture.map((piece) => piece.y * COLS + piece.x))
	state.snake.slice(0, -1).forEach((part) => cells.add(part.y * COLS + part.x))
	return cells
}

/** Breadth-first flood from the nozzle; each reached cell keeps its first move. */
const flood = (state, blocked) => {
	const firstMove = new Map()
	const queue = []
	const visit = (x, y, move) => {
		const cell = y * COLS + x
		if (x < 0 || x >= COLS || y < 0 || y >= ROWS || blocked.has(cell) || firstMove.has(cell)) return
		firstMove.set(cell, move)
		queue.push({ x, y })
	}
	Object.entries(DIRECTIONS).forEach(([move, delta]) => visit(state.snake[0].x + delta.x, state.snake[0].y + delta.y, move))
	for (let i = 0; i < queue.length; i++) {
		const move = firstMove.get(queue[i].y * COLS + queue[i].x)
		Object.values(DIRECTIONS).forEach((delta) => visit(queue[i].x + delta.x, queue[i].y + delta.y, move))
	}
	return firstMove
}

/**
 * A greedy player that is never slow: every move follows a shortest path to
 * the crumb, else heads into the largest open area. It upper-bounds the
 * points per second of real play (one move per tick, no detours).
 */
const autopilot = (state) => {
	const blocked = blockedCells(state)
	const reach = flood(state, blocked)
	const toCrumb = reach.get(state.crumb.y * COLS + state.crumb.x)
	if (toCrumb) return toCrumb
	const area = {}
	reach.forEach((move) => { area[move] = (area[move] ?? 0) + 1 })
	return Object.keys(area).sort((a, b) => area[b] - area[a])[0] ?? null
}

/** Advance exactly `count` moves at the current speed. */
const moves = (state, count, input = null) => {
	for (let i = 0; i < count; i++) {
		step(state, i === 0 ? input : null, state.tickMs)
	}
	return state
}

const head = (state) => state.snake[0]

describe('Crumb Snake logic', () => {
	it('opens deterministically: the first crumb scores without input or randomness', () => {
		const state = createState(() => {
			throw new Error('the opening must not draw random numbers before the first pickup')
		})
		expect(state.crumb).toEqual({ x: 9, y: 10, golden: false })
		// S7: one round, one hose — no dead `lives` state.
		expect(state).not.toHaveProperty('lives')

		step(state, null, TICK * 3)
		expect(state.score).toBe(0)
		state.rng = seededRandom(1)
		step(state, null, TICK)

		expect(state.score).toBe(10)
		expect(state.snake).toHaveLength(4)
		expect(state.events).toEqual([{ type: 'pickup', kind: 'normal', x: 9, y: 10 }])
	})

	it('moves one cell per tick and keeps sub-tick time', () => {
		const state = createState(seededRandom(2))
		step(state, null, TICK - 1)
		expect(head(state)).toEqual({ x: 5, y: 10 })
		step(state, null, 1)
		expect(head(state)).toEqual({ x: 6, y: 10 })
	})

	it('ignores a reversal into the hose and a repeated direction', () => {
		const state = createState(seededRandom(3))
		moves(state, 1, 'left')
		expect(state.direction).toBe('right')
		expect(head(state)).toEqual({ x: 6, y: 10 })
		step(state, 'right', 0)
		expect(state.turns).toEqual([])
	})

	it('buffers two quick turns so both apply on consecutive moves', () => {
		const state = createState(seededRandom(4))
		step(state, 'up', 0)
		step(state, 'left', 0)
		step(state, 'down', 0)
		expect(state.turns).toEqual(['up', 'left'])

		moves(state, 2)
		expect(head(state)).toEqual({ x: 4, y: 9 })
		expect(state.over).toBe(false)
	})

	it('ends the round at the wall with a crash event', () => {
		const state = createState(seededRandom(5))
		moves(state, 4, 'up')
		moves(state, 10)

		expect(state.over).toBe(true)
		expect(state.events.at(-1)).toEqual({ type: 'crash' })
		const before = JSON.stringify(state.snake)
		step(state, 'left', 10000)
		expect(JSON.stringify(state.snake)).toBe(before)
	})

	it('ends the round when the hose hits itself', () => {
		const state = createState(seededRandom(6))
		state.snake = [{ x: 5, y: 5 }, { x: 4, y: 5 }, { x: 4, y: 6 }, { x: 5, y: 6 }, { x: 6, y: 6 }, { x: 6, y: 5 }]
		state.crumb = { x: 0, y: 0, golden: false }
		step(state, 'down', TICK)
		expect(state.over).toBe(true)
	})

	it('may move into the cell the tail leaves in the same tick', () => {
		const state = createState(seededRandom(7))
		state.snake = [{ x: 5, y: 5 }, { x: 5, y: 6 }, { x: 4, y: 6 }, { x: 4, y: 5 }]
		state.direction = 'up'
		state.crumb = { x: 0, y: 0, golden: false }
		step(state, 'left', TICK)
		expect(state.over).toBe(false)
		expect(head(state)).toEqual({ x: 4, y: 5 })
	})

	it('scores a golden crumb higher and speeds up after every pickup', () => {
		const state = createState(seededRandom(8))
		state.crumb.golden = true
		moves(state, 4)

		expect(state.score).toBe(30)
		expect(state.events.at(-1)).toMatchObject({ type: 'pickup', kind: 'bonus' })
		expect(state.tickMs).toBe(TICK - 2)
	})

	it('never goes below the minimum tick', () => {
		const state = createState(seededRandom(9))
		state.tickMs = 61
		moves(state, 4)
		expect(state.tickMs).toBe(60)
	})

	it('S6: furniture is added per 50 points, stays put and never lands in front of the nozzle', () => {
		for (let seed = 1; seed <= 60; seed++) {
			const state = createState(seededRandom(seed))
			state.score = 40
			moves(state, 4)
			expect(state.score).toBe(50)
			expect(state.furniture).toHaveLength(1)
			const [piece] = state.furniture
			const nozzle = head(state)
			expect(Math.max(Math.abs(piece.x - nozzle.x), Math.abs(piece.y - nozzle.y))).toBeGreaterThan(2)
			for (let distance = 1; distance <= 4; distance++) {
				expect(piece).not.toEqual({ x: nozzle.x + distance, y: nozzle.y })
			}

			// A further pickup below the next threshold keeps the piece.
			state.crumb = { x: nozzle.x + 1, y: nozzle.y, golden: false }
			if (!state.furniture.some((cell) => cell.x === nozzle.x + 1 && cell.y === nozzle.y)) {
				moves(state, 1)
				expect(state.furniture).toEqual([piece])
			}
		}
	})

	it('treats queued turns as part of the protected path', () => {
		const state = createState(seededRandom(10))
		state.turns = ['up']
		expect(isSafeFromHead(state, { x: 5, y: 6 })).toBe(false)
		expect(isSafeFromHead(state, { x: 9, y: 10 })).toBe(false)
		expect(isSafeFromHead(state, { x: 15, y: 15 })).toBe(true)
	})

	it('ends the round as cleared when no free cell is left for a crumb', () => {
		const state = createState(seededRandom(11))
		state.crumb = { x: 6, y: 10, golden: false }
		const kept = new Set([...state.snake, state.crumb].map((cell) => `${cell.x},${cell.y}`))
		for (let y = 0; y < ROWS; y++) {
			for (let x = 0; x < COLS; x++) {
				if (!kept.has(`${x},${y}`)) state.furniture.push({ x, y })
			}
		}

		moves(state, 1)

		expect(state.score).toBe(10)
		expect(state.over).toBe(true)
		expect(state.events.at(-1)).toEqual({ type: 'cleared' })
	})

	it('replays identically from the same seed', () => {
		const crumbAfterOpening = (seed) => moves(createState(seededRandom(seed)), 4).crumb
		expect(crumbAfterOpening(42)).toEqual(crumbAfterOpening(42))
		// The random source places the next crumb: another seed, another cell.
		expect(crumbAfterOpening(42)).not.toEqual(crumbAfterOpening(7))
	})

	it('a shortest-path autopilot stays inside the server plausibility rules', () => {
		// The seeds that came closest to the rule in a sweep over seeds 1-1000
		// with this autopilot; 433 already breaks the burst plus 12 points per
		// second. Re-derive them after a rule or random-draw change (WP-H).
		for (const seed of [6, 77, 367, 433, 555, 793]) {
			const state = createState(seededRandom(seed))
			let elapsed = 0
			let worst = Number.NEGATIVE_INFINITY
			while (!state.over && elapsed < 300000) {
				// One decision per move, taken exactly when the move is due.
				const dt = state.tickMs - state.elapsed
				step(state, autopilot(state), dt)
				elapsed += dt
				state.events.length = 0
				worst = Math.max(worst, state.score - (SERVER.burstPoints + Math.floor(SERVER.pointsPerSecond * elapsed / 1000)))
			}

			expect(state.score).toBeGreaterThanOrEqual(300)
			expect(worst).toBeLessThanOrEqual(0)
			expect(state.score).toBeLessThan(SERVER.maxScore)
		}
	})
})
