/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/**
 * Sock Match rules (CS-PLAN-086 §3.1): memory pairs with patterned socks
 * from a laundry basket. Untimed and without a fail state — the round ends
 * when every pair is found, and the score rewards few wrong guesses. Pure
 * and deterministic — no DOM, no timers; the random source is a parameter
 * (seeded only in Jest). `step` advances the state object it owns.
 *
 * Inputs: a direction moves the cursor, `'act'` turns the cursor's sock and
 * `{cell}` turns that sock (a tap). Two socks that are no pair stay visible
 * until the next turn, which turns them back first — no time pressure.
 */

export const COLS = 4
export const ROWS = 4

/** One pattern per pair; the board tells them apart by shape. */
export const PATTERNS = Object.freeze(['stripes', 'dots', 'star', 'heart', 'diamond', 'triangle', 'checks', 'plus'])

/** The deterministic opening: the cursor's sock and its right neighbour are a pair. */
export const OPENING_CELLS = Object.freeze([0, 1])

export const PAIR_POINTS = 100
export const MISS_COST = 5
export const MIN_PAIR_POINTS = 20

const MOVES = {
	up: { x: 0, y: -1 },
	down: { x: 0, y: 1 },
	left: { x: -1, y: 0 },
	right: { x: 1, y: 0 },
}

/**
 * New round: every pattern twice, shuffled, then the opening pair placed
 * under the cursor and right of it, so Enter, Right, Enter scores without
 * randomness.
 *
 * @param {function(): number} rng Random source returning [0, 1)
 * @return {object} Game state
 */
export function createState(rng) {
	const deck = PATTERNS.flatMap((pattern) => [pattern, pattern])
	shuffle(deck, rng)
	const [first, second] = OPENING_CELLS
	swap(deck, second, deck.indexOf(deck[first], first + 1))
	return {
		cards: deck.map((pattern) => ({ pattern, face: 'down' })),
		cursor: first,
		// The turned sock of the current turn, or null.
		first: null,
		// Two socks shown as no pair until the next turn, or null.
		shown: null,
		misses: 0,
		pairsLeft: PATTERNS.length,
		score: 0,
		over: false,
		// Bumped on every visible change; the shell re-reads the view then.
		revision: 0,
		// Drained by the shell after every step: flip | wrong | pickup.
		events: [],
	}
}

function shuffle(items, rng) {
	for (let index = items.length - 1; index > 0; index--) {
		swap(items, index, Math.floor(rng() * (index + 1)))
	}
}

function swap(items, a, b) {
	const item = items[a]
	items[a] = items[b]
	items[b] = item
}

/**
 * Apply one input. The game is untimed, so the elapsed time the shell
 * passes as a third argument changes nothing.
 *
 * @param {object} state Game state from createState
 * @param {string|{cell: number}|null} input Direction, 'act', a tapped sock or null
 * @return {object} The same state object
 */
export function step(state, input) {
	if (state.over || !input) return state
	if (typeof input === 'object' && Number.isInteger(input.cell) && input.cell >= 0 && input.cell < state.cards.length) {
		if (state.cursor !== input.cell) {
			state.cursor = input.cell
			state.revision++
		}
		turn(state, input.cell)
	} else if (input === 'act') {
		turn(state, state.cursor)
	} else if (MOVES[input]) {
		moveCursor(state, MOVES[input])
	}
	return state
}

function moveCursor(state, move) {
	const x = Math.min(COLS - 1, Math.max(0, (state.cursor % COLS) + move.x))
	const y = Math.min(ROWS - 1, Math.max(0, Math.floor(state.cursor / COLS) + move.y))
	const cursor = y * COLS + x
	if (cursor !== state.cursor) {
		state.cursor = cursor
		state.revision++
	}
}

/**
 * Turn one sock. A shown non-pair turns back first; a sock that is already
 * up or matched changes nothing more. The second sock of a turn either
 * completes a pair (both stay, points scored) or counts as a wrong guess.
 *
 * @param {object} state Game state
 * @param {number} index Card index
 */
function turn(state, index) {
	if (state.shown) {
		for (const shown of state.shown) state.cards[shown].face = 'down'
		state.shown = null
		state.revision++
	}
	const card = state.cards[index]
	if (card.face !== 'down') return
	card.face = 'up'
	state.revision++
	state.events.push({ type: 'flip' })
	if (state.first === null) {
		state.first = index
		return
	}
	const first = state.cards[state.first]
	if (first.pattern === card.pattern) {
		first.face = 'matched'
		card.face = 'matched'
		state.score += pairPoints(state.misses)
		state.pairsLeft--
		state.events.push({ type: 'pickup', kind: 'normal', x: index % COLS, y: Math.floor(index / COLS) })
		state.over = state.pairsLeft === 0
	} else {
		state.misses++
		state.shown = [state.first, index]
		state.events.push({ type: 'wrong' })
	}
	state.first = null
}

/**
 * Points for the next pair: fewer wrong guesses so far score more, and
 * every pair scores at least MIN_PAIR_POINTS, so the score only grows.
 *
 * @param {number} misses Wrong guesses so far
 * @return {number} Points
 */
export function pairPoints(misses) {
	return Math.max(MIN_PAIR_POINTS, PAIR_POINTS - MISS_COST * misses)
}

/**
 * What the board shows, rebuilt only when `state.revision` changed. A sock
 * that is down carries no pattern, so the board never reveals it early.
 *
 * @param {object} state Game state
 * @return {{cards: Array<{face: string, pattern: string|null}>, cursor: number}}
 *   Faces: down | up | miss | matched
 */
export function view(state) {
	return {
		cards: state.cards.map((card, index) => ({
			face: state.shown?.includes(index) ? 'miss' : card.face,
			pattern: card.face === 'down' ? null : card.pattern,
		})),
		cursor: state.cursor,
	}
}
