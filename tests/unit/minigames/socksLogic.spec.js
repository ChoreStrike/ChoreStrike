/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { COLS, MIN_PAIR_POINTS, OPENING_CELLS, PAIR_POINTS, PATTERNS, ROWS, createState, pairPoints, step, view } from '@/components/Minigames/games/socks/logic.js'
import { seededRandom } from '../helpers/gameTestHelpers.js'

const STEP = 1000 / 60

/** The server's Sock Match rules (lib/Service/ArcadeCatalog.php). */
const SERVER = { maxScore: 800, pointsPerSecond: 1, burstPoints: 800 }

const faces = (state) => view(state).cards.map((card) => card.face)

/** The other sock of the pair `index` belongs to. */
const partnerOf = (state, index) => state.cards.findIndex((card, other) => other !== index && card.pattern === state.cards[index].pattern)

/** A sock that is no pair with `index` and still down. */
const strangerOf = (state, index) => state.cards.findIndex((card, other) => other !== index && card.face === 'down' && card.pattern !== state.cards[index].pattern)

const tap = (state, cell) => step(state, { cell }, STEP)

describe('Sock Match logic', () => {
	it('deals every pattern exactly twice on a 4x4 board, all socks down', () => {
		const state = createState(seededRandom(11))

		expect(state.cards).toHaveLength(COLS * ROWS)
		for (const pattern of PATTERNS) {
			expect(state.cards.filter((card) => card.pattern === pattern)).toHaveLength(2)
		}
		expect(new Set(faces(state))).toEqual(new Set(['down']))
		expect(state.pairsLeft).toBe(8)
	})

	it('opens deterministically: the cursor sock and its right neighbour are a pair', () => {
		for (const seed of [1, 2, 3, 4, 5, 6, 7, 8]) {
			const state = createState(seededRandom(seed))
			const [first, second] = OPENING_CELLS

			expect(state.cursor).toBe(first)
			expect(state.cards[second].pattern).toBe(state.cards[first].pattern)

			step(state, 'act', STEP)
			step(state, 'right', STEP)
			step(state, 'act', STEP)

			expect(state.score).toBe(PAIR_POINTS)
			expect(faces(state).slice(0, 2)).toEqual(['matched', 'matched'])
			expect(state.events).toEqual([{ type: 'pickup', kind: 'normal', x: 1, y: 0 }])
		}
	})

	it('shuffles the rest of the deck', () => {
		const deck = (seed) => createState(seededRandom(seed)).cards.map((card) => card.pattern).join()

		expect(new Set([1, 2, 3, 4, 5].map(deck)).size).toBeGreaterThan(1)
	})

	it('never shows the pattern of a sock that is down', () => {
		const state = createState(seededRandom(4))
		tap(state, 5)

		const cards = view(state).cards
		expect(cards[5]).toEqual({ face: 'up', pattern: state.cards[5].pattern })
		expect(cards.filter((card) => card.face === 'down').every((card) => card.pattern === null)).toBe(true)
	})

	it('keeps a wrong guess visible until the next turn, which turns it back first', () => {
		const state = createState(seededRandom(9))
		const stranger = strangerOf(state, 0)
		tap(state, 0)
		tap(state, stranger)

		expect(state.misses).toBe(1)
		expect(state.score).toBe(0)
		expect(view(state).cards[0].face).toBe('miss')
		expect(view(state).cards[stranger].face).toBe('miss')

		// No time pressure: neither game time nor cursor moves hide them.
		for (let i = 0; i < 600; i++) step(state, null, STEP)
		step(state, 'down', STEP)
		expect(view(state).cards[stranger].face).toBe('miss')

		const next = state.cards.findIndex((card) => card.face === 'down')
		tap(state, next)

		expect(view(state).cards[0].face).toBe('down')
		expect(view(state).cards[stranger].face).toBe('down')
		expect(view(state).cards[next].face).toBe('up')
		expect(state.first).toBe(next)
	})

	it('turns a shown wrong guess back and starts the new turn with the tapped sock', () => {
		const state = createState(seededRandom(9))
		const stranger = strangerOf(state, 0)
		tap(state, 0)
		tap(state, stranger)

		tap(state, stranger)

		expect(view(state).cards[0].face).toBe('down')
		expect(view(state).cards[stranger].face).toBe('up')
		expect(state.first).toBe(stranger)
	})

	it('ignores turning a sock that is already up or matched, and out-of-range taps', () => {
		const state = createState(seededRandom(2))
		tap(state, 0)
		tap(state, 0)
		expect(state.first).toBe(0)
		expect(state.misses).toBe(0)

		tap(state, 1)
		const revision = state.revision
		tap(state, 0)
		tap(state, 99)
		tap(state, -1)

		expect(state.score).toBe(PAIR_POINTS)
		expect(state.first).toBeNull()
		expect(state.revision).toBe(revision + 1)
	})

	it('moves the cursor with directions, stops at the walls and follows taps', () => {
		const state = createState(seededRandom(3))
		step(state, 'up', STEP)
		step(state, 'left', STEP)
		expect(state.cursor).toBe(0)
		step(state, 'right', STEP)
		step(state, 'down', STEP)
		expect(state.cursor).toBe(5)
		tap(state, 15)
		expect(state.cursor).toBe(15)
		step(state, 'right', STEP)
		step(state, 'down', STEP)
		expect(state.cursor).toBe(15)
	})

	it('scores fewer points per pair after wrong guesses, never below the floor', () => {
		expect(pairPoints(0)).toBe(100)
		expect(pairPoints(3)).toBe(85)
		expect(pairPoints(16)).toBe(MIN_PAIR_POINTS)
		expect(pairPoints(40)).toBe(MIN_PAIR_POINTS)

		const state = createState(seededRandom(6))
		tap(state, 2)
		tap(state, strangerOf(state, 2))
		tap(state, 2)
		tap(state, partnerOf(state, 2))

		expect(state.score).toBe(pairPoints(1))
	})

	it('is untimed: game time without input changes nothing', () => {
		const state = createState(seededRandom(5))
		tap(state, 3)
		const before = JSON.stringify(view(state))
		const revision = state.revision

		for (let i = 0; i < 3600; i++) step(state, null, STEP)

		expect(JSON.stringify(view(state))).toBe(before)
		expect(state.revision).toBe(revision)
		expect(state.over).toBe(false)
	})

	it('ends when every pair is found and ignores later input', () => {
		const state = createState(seededRandom(8))
		for (let index = 0; index < state.cards.length; index++) {
			if (state.cards[index].face !== 'down') continue
			tap(state, index)
			tap(state, partnerOf(state, index))
		}

		expect(state.over).toBe(true)
		expect(state.pairsLeft).toBe(0)
		expect(new Set(faces(state))).toEqual(new Set(['matched']))
		const score = state.score
		tap(state, 0)
		step(state, 'act', STEP)
		expect(state.score).toBe(score)
	})

	it('a perfect player reaches exactly the server ceiling and a careless one keeps the floor', () => {
		for (const seed of [1, 2, 3, 4, 5]) {
			const perfect = createState(seededRandom(seed))
			let elapsed = 0
			for (let index = 0; index < perfect.cards.length; index++) {
				if (perfect.cards[index].face !== 'down') continue
				tap(perfect, index)
				tap(perfect, partnerOf(perfect, index))
				elapsed += 2 * STEP
				expect(perfect.score).toBeLessThanOrEqual(SERVER.burstPoints + Math.floor(SERVER.pointsPerSecond * elapsed / 1000))
			}
			expect(perfect.score).toBe(SERVER.maxScore)

			// Two wrong guesses before every pair.
			const careless = createState(seededRandom(seed))
			while (!careless.over) {
				const index = careless.cards.findIndex((card) => card.face === 'down')
				for (let guess = 0; guess < 2 && strangerOf(careless, index) >= 0; guess++) {
					tap(careless, index)
					tap(careless, strangerOf(careless, index))
				}
				tap(careless, index)
				tap(careless, partnerOf(careless, index))
			}
			expect(careless.score).toBeGreaterThanOrEqual(PATTERNS.length * MIN_PAIR_POINTS)
			expect(careless.score).toBeLessThan(SERVER.maxScore)
		}
	})
})
