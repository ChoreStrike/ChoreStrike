/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { BUBBLE_R, COLS, DROP_HALF, SQUEEGEE_H, SQUEEGEE_Y, TILE_H, TILE_W, WALL_COLS, WALL_TOP } from './constants.js'

/** Gap between two tiles, in cells. */
const GROUT = 0.08
/** Speckles (x, y pairs as tile shares) that mark a tile with two layers of grime (never color alone). */
const SPECKLES = [0.3, 0.35, 0.55, 0.65, 0.75, 0.3]

/**
 * Draw a Tile Breaker frame: the tiled wall (clean tiles light, grimy ones
 * dark, double grime speckled), falling drops, the bubbles and the
 * squeegee. Colors come from the shell's theme palette, so the board
 * follows the light and dark Nextcloud themes; every drop has its own
 * shape.
 *
 * @param {CanvasRenderingContext2D} ctx Context already scaled to CSS pixels
 * @param {object} state Game state
 * @param {{width: number, height: number, palette: object, sparkles: Array}} view Frame inputs
 */
export function render(ctx, state, view) {
	const cell = view.width / COLS
	const { palette } = view
	ctx.fillStyle = palette.floor
	ctx.fillRect(0, 0, view.width, view.height)
	drawWall(ctx, state.tiles, cell, palette)
	for (const drop of state.drops) drawDrop(ctx, drop, cell, palette)
	drawSqueegee(ctx, state.squeegee, cell, palette)
	for (const bubble of state.bubbles) drawBubble(ctx, bubble, cell, palette)
	drawSparkles(ctx, view.sparkles, cell, palette)
}

function drawWall(ctx, tiles, cell, palette) {
	for (let index = 0; index < tiles.length; index++) {
		const hits = tiles[index]
		const x = (index % WALL_COLS) * TILE_W
		const y = WALL_TOP + Math.floor(index / WALL_COLS) * TILE_H
		// Grime: maxcontrast grey (one layer) or main text (two layers).
		ctx.fillStyle = hits === 0 ? palette.tile : (hits === 1 ? palette.furniture : palette.nozzle)
		roundedRect(ctx, (x + GROUT) * cell, (y + GROUT) * cell, (TILE_W - 2 * GROUT) * cell, (TILE_H - 2 * GROUT) * cell, cell * 0.12)
		if (hits < 2) continue
		ctx.fillStyle = palette.furniture
		for (let i = 0; i < SPECKLES.length; i += 2) {
			ctx.beginPath()
			ctx.arc((x + SPECKLES[i] * TILE_W) * cell, (y + SPECKLES[i + 1] * TILE_H) * cell, cell * 0.08, 0, Math.PI * 2)
			ctx.fill()
		}
	}
}

/**
 * A rubber blade on a handle bar in the primary color.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {{x: number, width: number}} squeegee The squeegee
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 */
function drawSqueegee(ctx, squeegee, cell, palette) {
	const left = (squeegee.x - squeegee.width / 2) * cell
	const width = squeegee.width * cell
	const blade = SQUEEGEE_H * 0.35
	ctx.fillStyle = palette.nozzle
	roundedRect(ctx, left, SQUEEGEE_Y * cell, width, blade * cell, cell * 0.08)
	ctx.fillStyle = palette.hose
	roundedRect(ctx, left + cell * 0.2, (SQUEEGEE_Y + blade) * cell, width - cell * 0.4, (SQUEEGEE_H - blade) * cell, cell * 0.12)
}

/**
 * A soap bubble: a ring with a small highlight.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {{x: number, y: number}} bubble The bubble
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 */
function drawBubble(ctx, bubble, cell, palette) {
	const cx = bubble.x * cell
	const cy = bubble.y * cell
	const radius = BUBBLE_R * cell
	ctx.beginPath()
	ctx.arc(cx, cy, radius, 0, Math.PI * 2)
	ctx.fillStyle = palette.floor
	ctx.fill()
	ctx.lineWidth = Math.max(1.5, cell * 0.1)
	ctx.strokeStyle = palette.hose
	ctx.stroke()
	ctx.beginPath()
	ctx.arc(cx, cy, radius * 0.55, Math.PI * 1.1, Math.PI * 1.45)
	ctx.lineWidth = Math.max(1, cell * 0.06)
	ctx.stroke()
}

/**
 * Drops differ by shape: a sponge with holes, a spray bottle, a wax drop
 * and a mud splash.
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
	ctx.strokeStyle = palette.outline
	if (drop.kind === 'sponge') {
		ctx.fillStyle = palette.golden
		roundedRect(ctx, cx - half, cy - half * 0.65, half * 2, half * 1.3, half * 0.3)
		ctx.stroke()
		ctx.fillStyle = palette.floor
		for (let hole = -1; hole <= 1; hole++) {
			ctx.beginPath()
			ctx.arc(cx + hole * half * 0.55, cy + (hole === 0 ? -half * 0.2 : half * 0.15), half * 0.16, 0, Math.PI * 2)
			ctx.fill()
		}
	} else if (drop.kind === 'spray') {
		ctx.fillStyle = palette.hose
		roundedRect(ctx, cx - half * 0.5, cy - half * 0.3, half, half * 1.3, half * 0.2)
		ctx.stroke()
		ctx.fillRect(cx - half * 0.2, cy - half * 0.6, half * 0.4, half * 0.3)
		ctx.fillRect(cx - half * 0.7, cy - half, half * 1.1, half * 0.4)
	} else if (drop.kind === 'wax') {
		ctx.beginPath()
		ctx.moveTo(cx, cy - half)
		ctx.lineTo(cx + half * 0.6, cy + half * 0.1)
		ctx.arc(cx, cy + half * 0.35, half * 0.6, -0.4, Math.PI + 0.4)
		ctx.closePath()
		ctx.fillStyle = palette.crumb
		ctx.fill()
		ctx.stroke()
	} else {
		ctx.fillStyle = palette.nozzle
		ctx.beginPath()
		ctx.arc(cx, cy, half * 0.55, 0, Math.PI * 2)
		ctx.fill()
		for (let blob = 0; blob < 4; blob++) {
			const angle = (Math.PI / 2) * blob + Math.PI / 4
			ctx.beginPath()
			ctx.arc(cx + Math.cos(angle) * half * 0.75, cy + Math.sin(angle) * half * 0.75, half * 0.2, 0, Math.PI * 2)
			ctx.fill()
		}
	}
}

/**
 * Three small rising rings where a tile was hit (only when motion is
 * allowed; the shell then fills `sparkles`).
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {Array<{x: number, y: number, progress: number}>} sparkles Active sparkles at tile centers
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 */
function drawSparkles(ctx, sparkles, cell, palette) {
	if (!sparkles || sparkles.length === 0) return
	ctx.strokeStyle = palette.sparkle
	ctx.lineWidth = Math.max(1, cell * 0.06)
	for (const sparkle of sparkles) {
		ctx.globalAlpha = 1 - sparkle.progress
		for (let ring = -1; ring <= 1; ring++) {
			ctx.beginPath()
			ctx.arc(
				(sparkle.x + ring * 0.5) * cell,
				(sparkle.y - sparkle.progress * (1 + Math.abs(ring) * 0.4)) * cell,
				cell * (0.15 + (ring === 0 ? 0.08 : 0)),
				0,
				Math.PI * 2,
			)
			ctx.stroke()
		}
	}
	ctx.globalAlpha = 1
}

/**
 * Fill a rounded rectangle (a plain one without `roundRect` support); the
 * shape stays the current path, so a caller may stroke it.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {number} x Left edge in CSS pixels
 * @param {number} y Top edge in CSS pixels
 * @param {number} width Width in CSS pixels
 * @param {number} height Height in CSS pixels
 * @param {number} radius Corner radius in CSS pixels
 */
function roundedRect(ctx, x, y, width, height, radius) {
	ctx.beginPath()
	if (typeof ctx.roundRect === 'function') {
		ctx.roundRect(x, y, width, height, radius)
	} else {
		ctx.rect(x, y, width, height)
	}
	ctx.fill()
}
