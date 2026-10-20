/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import {
	BUBBLE_R,
	clamp,
	COLS,
	DROP_HALF,
	DROP_KINDS,
	MAX_BUBBLES,
	MAX_SPEED,
	ROWS,
	SQUEEGEE_H,
	SQUEEGEE_Y,
	TILE_H,
	TILE_POINTS,
	TILE_W,
	WALL_COLS,
	WALL_ROWS,
	WALL_TOP,
} from './constants.js'

/**
 * Tile Breaker movement and collisions (CS-PLAN-086 §3.1): bubbles bounce
 * off walls, tiles and the squeegee; cleaned tiles drop items that fall
 * onto the squeegee or out of the board. Pure — it advances the state that
 * `logic.js` owns; the random source only decides the drops.
 */

const WIDTHS = { sponge: 4.5, mud: 2 }
const BOUNCE_SPEEDUP = 0.08
const WAX_FACTOR = 0.7
/** Widest bounce off the squeegee's edge, from vertical (radians). */
const MAX_ANGLE = Math.PI / 3
/** Flattest bounce off its middle: never straight up. */
const MIN_ANGLE = 0.17
const SPRAY_ANGLE = Math.PI / 6
const DROP_CHANCE = 0.12
const DROP_SPEED = 5
const EFFECT_MS = { sponge: 10000, mud: 8000, wax: 8000 }
/** Longest move between two collision checks, below the bubble radius. */
const MAX_MOVE = 0.25

/**
 * Move every launched bubble in short steps; a bubble below the board is
 * lost.
 *
 * @param {object} state Game state
 * @param {number} dt Elapsed game time in milliseconds
 */
export function moveBubbles(state, dt) {
	const distance = state.speed * (state.waxMs > 0 ? WAX_FACTOR : 1) * dt / 1000
	const moves = Math.ceil(distance / MAX_MOVE)
	for (let i = state.bubbles.length - 1; i >= 0; i--) {
		const bubble = state.bubbles[i]
		if (bubble.held) {
			continue
		}
		for (let move = 0; move < moves; move++) {
			moveBubble(state, bubble, distance / moves)
		}
		if (bubble.y - BUBBLE_R > ROWS) {
			state.bubbles.splice(i, 1)
		}
	}
}

/**
 * One short move, one axis at a time: a wall, the ceiling or a tile turns
 * that axis around (a tile hit steps back first, so the bubble never sits
 * inside a tile); falling onto the squeegee bounces it up.
 *
 * @param {object} state Game state
 * @param {object} bubble The moving bubble
 * @param {number} distance Distance in cells
 */
function moveBubble(state, bubble, distance) {
	bubble.x += bubble.dx * distance
	if (bubble.x < BUBBLE_R || bubble.x > COLS - BUBBLE_R) {
		bubble.dx = bubble.x < BUBBLE_R ? Math.abs(bubble.dx) : -Math.abs(bubble.dx)
		bubble.x = clamp(bubble.x, BUBBLE_R, COLS - BUBBLE_R)
	} else if (hitTiles(state, bubble)) {
		bubble.x -= bubble.dx * distance
		bubble.dx = -bubble.dx
	}
	bubble.y += bubble.dy * distance
	if (bubble.y < BUBBLE_R) {
		bubble.y = BUBBLE_R
		bubble.dy = Math.abs(bubble.dy)
	} else if (hitTiles(state, bubble)) {
		bubble.y -= bubble.dy * distance
		bubble.dy = -bubble.dy
	} else if (bubble.dy > 0 && touchesSqueegee(state.squeegee, bubble.x, bubble.y, BUBBLE_R)) {
		bounce(state, bubble)
	}
}

/**
 * Hit every tile the bubble overlaps.
 *
 * @param {object} state Game state
 * @param {object} bubble The moving bubble
 * @return {boolean} Whether the bubble touched a tile
 */
function hitTiles(state, bubble) {
	const top = bubble.y - BUBBLE_R
	const bottom = bubble.y + BUBBLE_R
	if (bottom <= WALL_TOP || top >= WALL_TOP + WALL_ROWS * TILE_H) {
		return false
	}
	const firstCol = Math.max(0, Math.floor((bubble.x - BUBBLE_R) / TILE_W))
	const lastCol = Math.min(WALL_COLS - 1, Math.floor((bubble.x + BUBBLE_R) / TILE_W))
	const firstRow = Math.max(0, Math.floor((top - WALL_TOP) / TILE_H))
	const lastRow = Math.min(WALL_ROWS - 1, Math.floor((bottom - WALL_TOP) / TILE_H))
	let touched = false
	for (let row = firstRow; row <= lastRow; row++) {
		for (let col = firstCol; col <= lastCol; col++) {
			if (state.tiles[row * WALL_COLS + col] > 0) {
				hitTile(state, row, col)
				touched = true
			}
		}
	}
	return touched
}

/**
 * Every hit scores and takes one layer of grime; a cleaned tile may drop
 * an item, which falls straight down from it.
 *
 * @param {object} state Game state
 * @param {number} row Wall row
 * @param {number} col Wall column
 */
function hitTile(state, row, col) {
	const index = row * WALL_COLS + col
	const x = (col + 0.5) * TILE_W
	const y = WALL_TOP + (row + 0.5) * TILE_H
	state.tiles[index]--
	state.score += TILE_POINTS
	state.events.push({ type: 'pickup', kind: 'normal', x, y })
	if (state.tiles[index] > 0) {
		return
	}
	state.tilesLeft--
	if (state.rng() < DROP_CHANCE) {
		state.drops.push({ x, y, kind: DROP_KINDS[Math.floor(state.rng() * DROP_KINDS.length)] })
	}
}

/**
 * The bounce angle follows where the bubble lands: the middle sends it up
 * almost straight, the edges send it off at up to 60 degrees.
 *
 * @param {object} state Game state
 * @param {object} bubble The bubble on the squeegee
 */
function bounce(state, bubble) {
	const { x, width } = state.squeegee
	const offset = clamp((bubble.x - x) / (width / 2), -1, 1)
	let angle = offset * MAX_ANGLE
	if (Math.abs(angle) < MIN_ANGLE) {
		const side = offset !== 0 ? Math.sign(offset) : Math.sign(bubble.dx) || 1
		angle = side * MIN_ANGLE
	}
	bubble.dx = Math.sin(angle)
	bubble.dy = -Math.cos(angle)
	bubble.y = SQUEEGEE_Y - BUBBLE_R
	state.speed = Math.min(MAX_SPEED, state.speed + BOUNCE_SPEEDUP)
	state.events.push({ type: 'bounce' })
}

/**
 * Let the drops fall; the squeegee catches what it touches, the rest
 * leaves the board.
 *
 * @param {object} state Game state
 * @param {number} dt Elapsed game time in milliseconds
 */
export function moveDrops(state, dt) {
	for (let i = state.drops.length - 1; i >= 0; i--) {
		const drop = state.drops[i]
		drop.y += DROP_SPEED * dt / 1000
		if (touchesSqueegee(state.squeegee, drop.x, drop.y, DROP_HALF)) {
			state.drops.splice(i, 1)
			catchDrop(state, drop)
		} else if (drop.y - DROP_HALF > ROWS) {
			state.drops.splice(i, 1)
		}
	}
}

/**
 * A caught drop: the sponge widens and the mud narrows the squeegee for a
 * while (the later one replaces the other), wax slows every bubble, the
 * spray bottle adds bubbles up to three in play.
 *
 * @param {object} state Game state
 * @param {{x: number, y: number, kind: string}} drop The caught drop
 */
function catchDrop(state, drop) {
	if (drop.kind === 'wax') {
		state.waxMs = EFFECT_MS.wax
	} else if (drop.kind === 'spray') {
		addBubble(state, -1)
		addBubble(state, 1)
	} else {
		state.squeegee.width = WIDTHS[drop.kind]
		state.widthMs = EFFECT_MS[drop.kind]
	}
	state.events.push(drop.kind === 'mud'
		? { type: 'penalty', x: drop.x, y: drop.y }
		: { type: 'pickup', kind: 'bonus', x: drop.x, y: drop.y })
}

function addBubble(state, side) {
	if (state.bubbles.length >= MAX_BUBBLES) {
		return
	}
	state.bubbles.push({
		x: state.squeegee.x,
		y: SQUEEGEE_Y - BUBBLE_R,
		dx: Math.sin(side * SPRAY_ANGLE),
		dy: -Math.cos(SPRAY_ANGLE),
		held: false,
	})
}

/**
 * Whether a box around (x, y) touches the squeegee.
 *
 * @param {{x: number, width: number}} squeegee The squeegee
 * @param {number} x Center x in cells
 * @param {number} y Center y in cells
 * @param {number} half Half the box edge in cells
 * @return {boolean}
 */
function touchesSqueegee(squeegee, x, y, half) {
	return y + half >= SQUEEGEE_Y && y - half <= SQUEEGEE_Y + SQUEEGEE_H
		&& x + half >= squeegee.x - squeegee.width / 2 && x - half <= squeegee.x + squeegee.width / 2
}
