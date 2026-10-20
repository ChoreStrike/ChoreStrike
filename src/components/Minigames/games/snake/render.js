/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { COLS, DIRECTIONS, ROWS, nextDirection } from './logic.js'
import { disc, drawEyes, lerp, pulse, roundedRect, star } from '../../draw.js'

/** How far the nozzle sits in the obstacle after a crash (share of a cell). */
const BUMP = 0.35
const HOSE_WIDTH = 0.62
const STRIPE_WIDTH = 0.2

/**
 * Draw a Crumb Snake frame: a cream checkerboard floor, furniture (a
 * chair, a plant or a crate per cell), the crumb and the vacuum hose as
 * one smooth stroke with its nozzle head. Segments are drawn on their way
 * to the next cell (`state.elapsed` of the current tick), so the hose
 * glides instead of jumping. Colors come from the shell's arcade palette,
 * so the board follows the light and dark Nextcloud themes; the golden
 * crumb pulses only while `view.motion` allows it.
 *
 * @param {CanvasRenderingContext2D} ctx Context already scaled to CSS pixels
 * @param {object} state Game state
 * @param {{width: number, height: number, palette: object, time: number, motion: boolean}} view Frame inputs
 */
export function render(ctx, state, view) {
	const cell = view.width / COLS
	const { palette } = view
	drawFloor(ctx, cell, palette)
	for (const piece of state.furniture) {
		drawFurniture(ctx, piece, cell, palette)
	}
	drawCrumb(ctx, state.crumb, cell, palette, view)
	drawHose(ctx, state, cell, palette)
}

function drawFloor(ctx, cell, palette) {
	ctx.fillStyle = palette.floor
	ctx.fillRect(0, 0, COLS * cell, ROWS * cell)
	ctx.fillStyle = palette.floorAlt
	for (let y = 0; y < ROWS; y++) {
		for (let x = (y % 2); x < COLS; x += 2) {
			ctx.fillRect(x * cell, y * cell, cell, cell)
		}
	}
}

/**
 * A chair, a potted plant or a crate, chosen by the cell so a piece keeps
 * its look; wood tones with a darker side.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {{x: number, y: number}} piece Furniture cell
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 */
function drawFurniture(ctx, piece, cell, palette) {
	const x = piece.x * cell
	const y = piece.y * cell
	const kind = (piece.x * 7 + piece.y * 13) % 3
	if (kind === 0) {
		drawChair(ctx, x, y, cell, palette)
	} else if (kind === 1) {
		drawPlant(ctx, x, y, cell, palette)
	} else {
		drawCrate(ctx, x, y, cell, palette)
	}
}

function drawChair(ctx, x, y, cell, palette) {
	ctx.fillStyle = palette.woodDark
	roundedRect(ctx, x + cell * 0.2, y + cell * 0.08, cell * 0.6, cell * 0.4, cell * 0.1)
	ctx.fillStyle = palette.wood
	roundedRect(ctx, x + cell * 0.12, y + cell * 0.45, cell * 0.76, cell * 0.3, cell * 0.08)
	ctx.fillStyle = palette.woodDark
	ctx.fillRect(x + cell * 0.18, y + cell * 0.72, cell * 0.12, cell * 0.22)
	ctx.fillRect(x + cell * 0.7, y + cell * 0.72, cell * 0.12, cell * 0.22)
}

function drawPlant(ctx, x, y, cell, palette) {
	ctx.fillStyle = palette.bronze
	roundedRect(ctx, x + cell * 0.28, y + cell * 0.55, cell * 0.44, cell * 0.38, cell * 0.08)
	ctx.fillStyle = palette.green
	disc(ctx, x + cell * 0.5, y + cell * 0.38, cell * 0.22)
	disc(ctx, x + cell * 0.3, y + cell * 0.5, cell * 0.16)
	disc(ctx, x + cell * 0.7, y + cell * 0.5, cell * 0.16)
}

function drawCrate(ctx, x, y, cell, palette) {
	ctx.fillStyle = palette.wood
	roundedRect(ctx, x + cell * 0.1, y + cell * 0.15, cell * 0.8, cell * 0.75, cell * 0.08)
	ctx.fillStyle = palette.woodLight
	ctx.fillRect(x + cell * 0.1, y + cell * 0.15, cell * 0.8, cell * 0.12)
	ctx.fillStyle = palette.woodDark
	ctx.fillRect(x + cell * 0.1, y + cell * 0.5, cell * 0.8, cell * 0.08)
}

/**
 * A cookie crumb with chocolate chips; the golden crumb is a coin with a
 * star that pulses while motion is allowed.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {{x: number, y: number, golden: boolean}} crumb The crumb
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 * @param {{time: number, motion: boolean}} view Frame inputs
 */
function drawCrumb(ctx, crumb, cell, palette, view) {
	const cx = (crumb.x + 0.5) * cell
	const cy = (crumb.y + 0.5) * cell
	ctx.lineWidth = Math.max(1, cell * 0.06)
	ctx.strokeStyle = palette.ink
	if (crumb.golden) {
		const radius = cell * (0.34 + (view.motion ? pulse(view.time, 900) * 0.06 : 0))
		ctx.fillStyle = palette.yellow
		disc(ctx, cx, cy, radius)
		ctx.stroke()
		ctx.fillStyle = palette.paper
		star(ctx, cx, cy, radius * 0.55)
		return
	}
	ctx.fillStyle = palette.bronze
	disc(ctx, cx, cy, cell * 0.3)
	ctx.stroke()
	ctx.fillStyle = palette.cocoa
	disc(ctx, cx - cell * 0.1, cy - cell * 0.08, cell * 0.06)
	disc(ctx, cx + cell * 0.1, cy + cell * 0.02, cell * 0.06)
	disc(ctx, cx - cell * 0.02, cy + cell * 0.13, cell * 0.05)
}

/**
 * The hose as one smooth stroke through its segments with a lighter
 * stripe, and the nozzle head with eyes looking ahead. After a crash the
 * nozzle sits bumped into the obstacle with closed eyes.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {object} state Game state
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 */
function drawHose(ctx, state, cell, palette) {
	const t = state.over ? BUMP : Math.min(1, state.elapsed / state.tickMs)
	const ahead = DIRECTIONS[nextDirection(state)]
	const head = state.snake[0]
	const grows = head.x + ahead.x === state.crumb.x && head.y + ahead.y === state.crumb.y
	ctx.lineCap = 'round'
	ctx.lineJoin = 'round'
	traceHose(ctx, state.snake, ahead, t, grows, cell)
	ctx.lineWidth = cell * HOSE_WIDTH
	ctx.strokeStyle = palette.blue
	ctx.stroke()
	ctx.lineWidth = cell * STRIPE_WIDTH
	ctx.strokeStyle = palette.blueLight
	ctx.stroke()
	drawNozzle(ctx, (head.x + ahead.x * t + 0.5) * cell, (head.y + ahead.y * t + 0.5) * cell, ahead, cell, palette, state.over)
}

/**
 * The path from the tail end to the nozzle: every segment moves toward the
 * one ahead of it; the tail end stays put while the hose grows this move.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {Array<{x: number, y: number}>} snake Segments, nozzle first
 * @param {{x: number, y: number}} ahead The next move
 * @param {number} t Share of the current tick (0..1)
 * @param {boolean} grows Whether the next move eats the crumb
 * @param {number} cell Cell edge in CSS pixels
 */
function traceHose(ctx, snake, ahead, t, grows, cell) {
	const last = snake.length - 1
	const tailT = grows ? 0 : t
	ctx.beginPath()
	ctx.moveTo((lerp(snake[last].x, snake[last - 1].x, tailT) + 0.5) * cell, (lerp(snake[last].y, snake[last - 1].y, tailT) + 0.5) * cell)
	for (let i = last - 1; i > 0; i--) {
		ctx.lineTo((lerp(snake[i].x, snake[i - 1].x, t) + 0.5) * cell, (lerp(snake[i].y, snake[i - 1].y, t) + 0.5) * cell)
	}
	ctx.lineTo((snake[0].x + ahead.x * t + 0.5) * cell, (snake[0].y + ahead.y * t + 0.5) * cell)
}

function drawNozzle(ctx, cx, cy, ahead, cell, palette, over) {
	ctx.fillStyle = palette.ink
	roundedRect(ctx, cx - cell * 0.45, cy - cell * 0.45, cell * 0.9, cell * 0.9, cell * 0.28)
	// The suction opening at the front edge, below the eyes when sideways.
	ctx.fillStyle = palette.blueLight
	disc(ctx, cx + ahead.x * cell * 0.42, cy + ahead.y * cell * 0.42 + (ahead.y === 0 ? cell * 0.18 : 0), cell * 0.1)
	drawEyes(ctx, cx, cy - cell * 0.1, cell * 0.3, ahead.x, ahead.y, palette, over)
}
