/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { COLS, DIRECTIONS, FLOOR, NEST, ROWS, cellIndex } from './rooms.js'

/** Gap around a piece of furniture, in cells. */
const GAP = 0.08
/** Scattered specks of a dust patch (x, y, radius as cell shares). */
const SPECKS = [0.3, 0.34, 0.1, 0.66, 0.28, 0.08, 0.44, 0.62, 0.11, 0.74, 0.7, 0.07]
/** The dock's lightning bolt (x, y pairs as cell shares). */
const BOLT = [0.56, 0.18, 0.32, 0.54, 0.48, 0.54, 0.42, 0.82, 0.68, 0.44, 0.52, 0.44]

/**
 * Draw a Dust Runner frame: the tiled floor, furniture in merged blocks,
 * dust patches, the dock, the dust bunnies and the robot vacuum. Colors
 * come from the shell's theme palette, so the board follows the light and
 * dark Nextcloud themes. Nothing relies on color alone: dust is a scatter
 * of small specks, furniture is solid, a bunny has ears (drooping while
 * catchable), the dock's bolt is filled
 * only while ready, and a charged robot wears a ring.
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
	drawRoom(ctx, state, cell, palette)
	drawDock(ctx, state, cell, palette)
	for (const bunny of state.bunnies) drawBunny(ctx, bunny, cell, palette)
	drawRobot(ctx, state, cell, palette)
	drawSparkles(ctx, view.sparkles, cell, palette)
}

function drawRoom(ctx, state, cell, palette) {
	const kinds = state.room.kinds
	for (let y = 0; y < ROWS; y++) {
		for (let x = 0; x < COLS; x++) {
			const kind = kinds[cellIndex(x, y)]
			if (kind === FLOOR) {
				if ((x + y) % 2 === 1) {
					ctx.fillStyle = palette.tile
					ctx.fillRect(x * cell, y * cell, cell, cell)
				}
				if (state.dust[cellIndex(x, y)] === 1) drawDust(ctx, x, y, cell, palette)
			} else {
				drawFurniture(ctx, kinds, x, y, cell, palette)
				if (kind === NEST) drawNest(ctx, x, y, cell, palette)
			}
		}
	}
}

/**
 * One furniture cell; it reaches into blocked neighbours, so a piece of
 * furniture reads as one block with a gap around it.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {Uint8Array} kinds Room cell kinds
 * @param {number} x Column
 * @param {number} y Row
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 */
function drawFurniture(ctx, kinds, x, y, cell, palette) {
	const left = isBlocked(kinds, x - 1, y) ? 0 : GAP
	const right = isBlocked(kinds, x + 1, y) ? 0 : GAP
	const top = isBlocked(kinds, x, y - 1) ? 0 : GAP
	const bottom = isBlocked(kinds, x, y + 1) ? 0 : GAP
	ctx.fillStyle = palette.furniture
	ctx.fillRect((x + left) * cell, (y + top) * cell, (1 - left - right) * cell, (1 - top - bottom) * cell)
}

function isBlocked(kinds, x, y) {
	return x < 0 || x >= COLS || y < 0 || y >= ROWS || kinds[cellIndex(x, y)] !== FLOOR
}

/**
 * The dark gap under a piece of furniture where a bunny lives.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {number} x Column
 * @param {number} y Row
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 */
function drawNest(ctx, x, y, cell, palette) {
	ctx.fillStyle = palette.nozzle
	ctx.beginPath()
	ctx.arc((x + 0.5) * cell, (y + 0.62) * cell, cell * 0.3, Math.PI, 0)
	ctx.fill()
}

/**
 * A dust patch as one path with one fill (about 120 patches in a frame).
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {number} x Column
 * @param {number} y Row
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 */
function drawDust(ctx, x, y, cell, palette) {
	ctx.fillStyle = palette.furniture
	ctx.beginPath()
	for (let i = 0; i < SPECKS.length; i += 3) {
		const cx = (x + SPECKS[i]) * cell
		const cy = (y + SPECKS[i + 1]) * cell
		ctx.moveTo(cx + SPECKS[i + 2] * cell, cy)
		ctx.arc(cx, cy, SPECKS[i + 2] * cell, 0, Math.PI * 2)
	}
	ctx.fill()
}

/**
 * The charging dock: a plate in the robot's color with a lightning bolt,
 * filled while the dock is ready and only outlined while it recharges.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {object} state Game state
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 */
function drawDock(ctx, state, cell, palette) {
	const { x, y } = state.room.dock
	ctx.fillStyle = palette.hose
	ctx.fillRect((x + 0.1) * cell, (y + 0.1) * cell, cell * 0.8, cell * 0.8)
	ctx.beginPath()
	ctx.moveTo((x + BOLT[0]) * cell, (y + BOLT[1]) * cell)
	for (let i = 2; i < BOLT.length; i += 2) ctx.lineTo((x + BOLT[i]) * cell, (y + BOLT[i + 1]) * cell)
	ctx.closePath()
	ctx.lineWidth = Math.max(1, cell * 0.06)
	ctx.strokeStyle = palette.golden
	if (state.dockMs === 0) {
		ctx.fillStyle = palette.golden
		ctx.fill()
	} else {
		ctx.stroke()
	}
}

/**
 * A dust bunny: a round body with two ears, standing up while it roams and
 * drooping to the sides while it can be caught. A waiting bunny peeks out
 * of its nest at a smaller size.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {object} bunny Bunny
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 */
function drawBunny(ctx, bunny, cell, palette) {
	const scale = bunny.waitMs > 0 ? 0.6 : 1
	const cx = (bunny.x + 0.5) * cell
	const cy = (bunny.y + 0.58) * cell
	const radius = cell * 0.3 * scale
	const earX = radius * (bunny.catchable ? 1.05 : 0.5)
	const earY = radius * (bunny.catchable ? 0.15 : 1.1)
	ctx.fillStyle = bunny.catchable ? palette.golden : palette.nozzle
	for (let side = -1; side <= 1; side += 2) {
		ctx.beginPath()
		ctx.arc(cx + side * earX, cy - earY, radius * 0.4, 0, Math.PI * 2)
		ctx.fill()
	}
	ctx.beginPath()
	ctx.arc(cx, cy, radius, 0, Math.PI * 2)
	ctx.fill()
	ctx.lineWidth = Math.max(1, cell * 0.05)
	ctx.strokeStyle = palette.outline
	ctx.stroke()
	ctx.fillStyle = palette.floor
	for (let side = -1; side <= 1; side += 2) {
		ctx.beginPath()
		ctx.arc(cx + side * radius * 0.38, cy - radius * 0.15, radius * 0.16, 0, Math.PI * 2)
		ctx.fill()
	}
}

/**
 * The robot vacuum: a disc with its sensor toward the way it heads, and a
 * ring while it is charged.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {object} state Game state
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 */
function drawRobot(ctx, state, cell, palette) {
	const { x, y, direction } = state.robot
	const cx = (x + 0.5) * cell
	const cy = (y + 0.5) * cell
	ctx.beginPath()
	ctx.arc(cx, cy, cell * 0.4, 0, Math.PI * 2)
	ctx.fillStyle = palette.hose
	ctx.fill()
	ctx.lineWidth = Math.max(1, cell * 0.06)
	ctx.strokeStyle = palette.outline
	ctx.stroke()
	if (state.chargeMs > 0) {
		ctx.beginPath()
		ctx.arc(cx, cy, cell * 0.48, 0, Math.PI * 2)
		ctx.lineWidth = Math.max(1.5, cell * 0.08)
		ctx.strokeStyle = palette.golden
		ctx.stroke()
	}
	const delta = DIRECTIONS[direction]
	ctx.beginPath()
	ctx.arc(cx + delta.x * cell * 0.22, cy + delta.y * cell * 0.22, cell * 0.1, 0, Math.PI * 2)
	ctx.fillStyle = palette.floor
	ctx.fill()
}

/**
 * Short-lived stars at a charge or a caught bunny (only when motion is
 * allowed; the shell then fills `sparkles`).
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {Array<{x: number, y: number, progress: number}>} sparkles Active sparkles
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 */
function drawSparkles(ctx, sparkles, cell, palette) {
	if (!sparkles || sparkles.length === 0) return
	ctx.strokeStyle = palette.sparkle
	ctx.lineWidth = Math.max(1, cell * 0.08)
	for (const sparkle of sparkles) {
		const radius = cell * (0.4 + sparkle.progress * 0.6)
		const cx = (sparkle.x + 0.5) * cell
		const cy = (sparkle.y + 0.5) * cell
		ctx.globalAlpha = 1 - sparkle.progress
		ctx.beginPath()
		for (let ray = 0; ray < 4; ray++) {
			const angle = (Math.PI / 2) * ray
			ctx.moveTo(cx + Math.cos(angle) * radius * 0.4, cy + Math.sin(angle) * radius * 0.4)
			ctx.lineTo(cx + Math.cos(angle) * radius, cy + Math.sin(angle) * radius)
		}
		ctx.stroke()
	}
	ctx.globalAlpha = 1
}
