/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import {
	BUBBLE_R, COLS, DROP_HALF, SQUEEGEE_H, SQUEEGEE_W, SQUEEGEE_Y, TILE_H, TILE_W, WALL_COLS, WALL_TOP,
} from './constants.js'
import { disc, roundedRect } from '../../draw.js'

/** Gap between two tiles, in cells. */
const GROUT = 0.08
/** Speckles (x, y pairs as tile shares) that mark a tile with two layers of grime. */
const SPECKLES = [0.3, 0.35, 0.55, 0.65, 0.75, 0.3]
/** Streaks (x1, y1, x2, y2 as tile shares) that mark one layer of grime. */
const STREAKS = [0.2, 0.75, 0.42, 0.25, 0.55, 0.8, 0.82, 0.3]

/**
 * Draw a Tile Breaker frame: the tiled wall (clean tiles shine, one layer
 * of grime streaks, two layers speckle — never color alone), the falling
 * drops, the squeegee with its wooden bar and the soap bubbles with their
 * sheen. Colors come from the shell's arcade palette, so the board
 * follows the light and dark Nextcloud themes.
 *
 * @param {CanvasRenderingContext2D} ctx Context already scaled to CSS pixels
 * @param {object} state Game state
 * @param {{width: number, height: number, palette: object}} view Frame inputs
 */
export function render(ctx, state, view) {
	const cell = view.width / COLS
	const { palette } = view
	ctx.fillStyle = palette.floor
	ctx.fillRect(0, 0, view.width, view.height)
	drawWall(ctx, state.tiles, cell, palette)
	for (const drop of state.drops) drawDrop(ctx, drop, cell, palette)
	drawSqueegee(ctx, state, cell, palette)
	for (const bubble of state.bubbles) drawBubble(ctx, bubble, cell, palette, state.waxMs > 0)
}

function drawWall(ctx, tiles, cell, palette) {
	ctx.lineCap = 'round'
	for (let index = 0; index < tiles.length; index++) {
		const hits = tiles[index]
		const x = (index % WALL_COLS) * TILE_W
		const y = WALL_TOP + Math.floor(index / WALL_COLS) * TILE_H
		ctx.fillStyle = hits === 0 ? palette.tealLight : (hits === 1 ? palette.tan : palette.cocoa)
		roundedRect(ctx, (x + GROUT) * cell, (y + GROUT) * cell, (TILE_W - 2 * GROUT) * cell, (TILE_H - 2 * GROUT) * cell, cell * 0.12)
		if (hits === 0) drawShine(ctx, x, y, cell, palette)
		else if (hits === 1) drawStreaks(ctx, x, y, cell, palette)
		else drawSpeckles(ctx, x, y, cell, palette)
	}
}

function drawShine(ctx, x, y, cell, palette) {
	ctx.strokeStyle = palette.paper
	ctx.lineWidth = Math.max(1, cell * 0.05)
	ctx.beginPath()
	ctx.moveTo((x + 0.15 * TILE_W) * cell, (y + 0.65 * TILE_H) * cell)
	ctx.lineTo((x + 0.32 * TILE_W) * cell, (y + 0.25 * TILE_H) * cell)
	ctx.stroke()
}

function drawStreaks(ctx, x, y, cell, palette) {
	ctx.strokeStyle = palette.cocoa
	ctx.lineWidth = Math.max(1, cell * 0.05)
	ctx.beginPath()
	for (let i = 0; i < STREAKS.length; i += 4) {
		ctx.moveTo((x + STREAKS[i] * TILE_W) * cell, (y + STREAKS[i + 1] * TILE_H) * cell)
		ctx.lineTo((x + STREAKS[i + 2] * TILE_W) * cell, (y + STREAKS[i + 3] * TILE_H) * cell)
	}
	ctx.stroke()
}

function drawSpeckles(ctx, x, y, cell, palette) {
	ctx.fillStyle = palette.tan
	for (let i = 0; i < SPECKLES.length; i += 2) {
		disc(ctx, (x + SPECKLES[i] * TILE_W) * cell, (y + SPECKLES[i + 1] * TILE_H) * cell, cell * 0.08)
	}
}

/**
 * A rubber blade on a wooden bar; a widened squeegee (sponge) glows, a
 * narrowed one (mud) is coated.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {object} state Game state
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 */
function drawSqueegee(ctx, state, cell, palette) {
	const { x, width } = state.squeegee
	const left = (x - width / 2) * cell
	const w = width * cell
	const top = SQUEEGEE_Y * cell
	const blade = SQUEEGEE_H * 0.4 * cell
	if (state.widthMs > 0) {
		ctx.fillStyle = width > SQUEEGEE_W ? palette.yellow : palette.cocoa
		roundedRect(ctx, left - cell * 0.12, top - cell * 0.12, w + cell * 0.24, SQUEEGEE_H * cell + cell * 0.24, cell * 0.2)
	}
	ctx.fillStyle = palette.ink
	roundedRect(ctx, left, top, w, blade, cell * 0.08)
	ctx.fillStyle = palette.wood
	roundedRect(ctx, left + cell * 0.15, top + blade, w - cell * 0.3, SQUEEGEE_H * cell - blade, cell * 0.1)
	ctx.fillStyle = palette.woodLight
	ctx.fillRect(left + cell * 0.3, top + blade + cell * 0.03, w - cell * 0.6, cell * 0.05)
}

/**
 * A soap bubble: a ring (blue while waxed, teal otherwise) with an
 * iridescent sheen of two short arcs.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {{x: number, y: number}} bubble The bubble
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 * @param {boolean} waxed Whether floor wax slows the bubbles
 */
function drawBubble(ctx, bubble, cell, palette, waxed) {
	const cx = bubble.x * cell
	const cy = bubble.y * cell
	const radius = BUBBLE_R * cell
	ctx.fillStyle = palette.paper
	disc(ctx, cx, cy, radius)
	ctx.lineWidth = Math.max(1.5, cell * 0.1)
	ctx.strokeStyle = waxed ? palette.blue : palette.teal
	ctx.stroke()
	ctx.lineWidth = Math.max(1, cell * 0.06)
	ctx.strokeStyle = palette.coralLight
	ctx.beginPath()
	ctx.arc(cx, cy, radius * 0.6, Math.PI * 1.1, Math.PI * 1.5)
	ctx.stroke()
	ctx.strokeStyle = palette.yellow
	ctx.beginPath()
	ctx.arc(cx, cy, radius * 0.6, Math.PI * 0.2, Math.PI * 0.5)
	ctx.stroke()
}

/**
 * Drops differ by shape and color: a yellow sponge with holes, a blue
 * spray bottle, a coral wax drop and a dark mud splash.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {{x: number, y: number, kind: string}} drop The falling drop
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 */
function drawDrop(ctx, drop, cell, palette) {
	const cx = drop.x * cell
	const cy = drop.y * cell
	const half = DROP_HALF * cell
	ctx.lineWidth = Math.max(1, cell * 0.06)
	ctx.strokeStyle = palette.ink
	if (drop.kind === 'sponge') {
		ctx.fillStyle = palette.yellow
		roundedRect(ctx, cx - half, cy - half * 0.65, half * 2, half * 1.3, half * 0.3)
		ctx.stroke()
		ctx.fillStyle = palette.paper
		for (let hole = -1; hole <= 1; hole++) {
			disc(ctx, cx + hole * half * 0.55, cy + (hole === 0 ? -half * 0.2 : half * 0.15), half * 0.16)
		}
	} else if (drop.kind === 'spray') {
		ctx.fillStyle = palette.blue
		roundedRect(ctx, cx - half * 0.5, cy - half * 0.3, half, half * 1.3, half * 0.2)
		ctx.stroke()
		ctx.fillStyle = palette.teal
		ctx.fillRect(cx - half * 0.2, cy - half * 0.6, half * 0.4, half * 0.3)
		ctx.fillRect(cx - half * 0.7, cy - half, half * 1.1, half * 0.4)
	} else if (drop.kind === 'wax') {
		ctx.beginPath()
		ctx.moveTo(cx, cy - half)
		ctx.lineTo(cx + half * 0.6, cy + half * 0.1)
		ctx.arc(cx, cy + half * 0.35, half * 0.6, -0.4, Math.PI + 0.4)
		ctx.closePath()
		ctx.fillStyle = palette.coral
		ctx.fill()
		ctx.stroke()
		ctx.fillStyle = palette.paper
		disc(ctx, cx - half * 0.2, cy + half * 0.2, half * 0.12)
	} else {
		ctx.fillStyle = palette.cocoa
		disc(ctx, cx, cy, half * 0.55)
		for (let blob = 0; blob < 4; blob++) {
			const angle = (Math.PI / 2) * blob + Math.PI / 4
			disc(ctx, cx + Math.cos(angle) * half * 0.75, cy + Math.sin(angle) * half * 0.75, half * 0.2)
		}
	}
}
