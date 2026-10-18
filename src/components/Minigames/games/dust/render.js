/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { COLS, FLOOR, NEST, ROWS, cellIndex, themeAt } from './rooms.js'
import { drawBunny, drawRobot } from './actors.js'
import { TAU, disc, drawEyes, pulse, roundedRect } from '../../draw.js'

/** Gap around a piece of furniture, in cells. */
const GAP = 0.08
/** Scattered specks of a dust patch (x, y, radius as cell shares). */
const SPECKS = [0.3, 0.34, 0.1, 0.66, 0.28, 0.08, 0.44, 0.62, 0.11, 0.74, 0.7, 0.07]
/** The dock's lightning bolt (x, y pairs as cell shares). */
const BOLT = [0.56, 0.18, 0.32, 0.54, 0.48, 0.54, 0.42, 0.82, 0.68, 0.44, 0.52, 0.44]
/** Furniture colors per floor theme: fill, top edge, bottom edge. */
const FURNITURE = Object.freeze({
	wood: ['wood', 'woodLight', 'woodDark'],
	tiles: ['blueLight', 'paper', 'blue'],
	carpet: ['coral', 'coralLight', 'bronze'],
})

/**
 * Draw a Dust Runner frame: the room's floor (planks in the hallway,
 * kitchen tiles, a carpet in the living room), furniture in merged blocks
 * with a lit top edge, dust patches, the nests with the eyes of waiting
 * bunnies, the dock, the dust bunnies and the robot vacuum (`actors.js`).
 * Colors come from the shell's arcade palette, so the board follows the
 * light and dark Nextcloud themes. Nothing relies on color alone: dust is
 * a scatter of specks, furniture is solid, a bunny's ears droop while it
 * can be caught, the dock's bolt is filled only while ready, and a charged
 * robot wears a ring. The ready dock glows only while `view.motion` allows.
 *
 * @param {CanvasRenderingContext2D} ctx Context already scaled to CSS pixels
 * @param {object} state Game state
 * @param {{width: number, height: number, palette: object, time: number, motion: boolean}} view Frame inputs
 */
export function render(ctx, state, view) {
	const cell = view.width / COLS
	const { palette } = view
	ctx.fillStyle = palette.floor
	ctx.fillRect(0, 0, view.width, view.height)
	drawRoom(ctx, state, themeAt(state.roomIndex), cell, palette)
	for (const bunny of state.bunnies) {
		if (bunny.waitMs > 0) drawPeek(ctx, state, bunny.nest, cell, palette)
	}
	drawDock(ctx, state, cell, palette, view)
	for (const bunny of state.bunnies) drawBunny(ctx, state, bunny, cell, palette)
	drawRobot(ctx, state, cell, palette, view)
}

function drawRoom(ctx, state, theme, cell, palette) {
	const kinds = state.room.kinds
	const colors = FURNITURE[theme]
	for (let y = 0; y < ROWS; y++) {
		for (let x = 0; x < COLS; x++) {
			const kind = kinds[cellIndex(x, y)]
			if (kind === FLOOR) {
				drawFloorCell(ctx, theme, x, y, cell, palette)
				if (state.dust[cellIndex(x, y)] === 1) drawDust(ctx, x, y, cell, palette)
			} else {
				drawFurniture(ctx, kinds, x, y, cell, palette, colors)
				if (kind === NEST) drawNest(ctx, x, y, cell, palette)
			}
		}
	}
}

/**
 * One floor cell by room: a plank row with seams, a checkerboard tile or
 * a dotted carpet.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {string} theme Floor theme (rooms.js THEMES)
 * @param {number} x Column
 * @param {number} y Row
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 */
function drawFloorCell(ctx, theme, x, y, cell, palette) {
	if (theme === 'wood') {
		if (y % 2 === 0) {
			ctx.fillStyle = palette.floorAlt
			ctx.fillRect(x * cell, y * cell, cell, cell)
		}
		ctx.fillStyle = palette.grout
		ctx.fillRect(x * cell, (y + 0.95) * cell, cell, cell * 0.05)
		if ((x + y) % 3 === 0) ctx.fillRect((x + 0.95) * cell, y * cell, cell * 0.05, cell)
	} else if (theme === 'tiles') {
		if ((x + y) % 2 === 1) {
			ctx.fillStyle = palette.tealLight
			ctx.fillRect(x * cell, y * cell, cell, cell)
		}
	} else {
		ctx.fillStyle = palette.floorAlt
		ctx.fillRect(x * cell, y * cell, cell, cell)
		ctx.fillStyle = palette.coralLight
		disc(ctx, (x + 0.5) * cell, (y + 0.5) * cell, cell * 0.06)
	}
}

/**
 * One furniture cell; it reaches into blocked neighbours, so a piece of
 * furniture reads as one block with a gap around it, a lit top edge and
 * a shaded bottom edge.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {Uint8Array} kinds Room cell kinds
 * @param {number} x Column
 * @param {number} y Row
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 * @param {string[]} colors Palette names of the fill, top and bottom edges
 */
function drawFurniture(ctx, kinds, x, y, cell, palette, colors) {
	const left = isBlocked(kinds, x - 1, y) ? 0 : GAP
	const right = isBlocked(kinds, x + 1, y) ? 0 : GAP
	const top = isBlocked(kinds, x, y - 1) ? 0 : GAP
	const bottom = isBlocked(kinds, x, y + 1) ? 0 : GAP
	const width = (1 - left - right) * cell
	ctx.fillStyle = palette[colors[0]]
	ctx.fillRect((x + left) * cell, (y + top) * cell, width, (1 - top - bottom) * cell)
	if (top > 0) {
		ctx.fillStyle = palette[colors[1]]
		ctx.fillRect((x + left) * cell, (y + top) * cell, width, cell * 0.12)
	}
	if (bottom > 0) {
		ctx.fillStyle = palette[colors[2]]
		ctx.fillRect((x + left) * cell, (y + 1 - bottom - 0.12) * cell, width, cell * 0.12)
	}
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
	ctx.fillStyle = palette.ink
	ctx.beginPath()
	ctx.arc((x + 0.5) * cell, (y + 0.62) * cell, cell * 0.3, Math.PI, 0)
	ctx.fill()
}

/**
 * A waiting bunny peeks out of its nest: two eyes that follow the robot.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {object} state Game state
 * @param {{x: number, y: number}} nest Nest cell
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 */
function drawPeek(ctx, state, nest, cell, palette) {
	const lookX = Math.sign(state.robot.x - nest.x)
	const lookY = Math.sign(state.robot.y - nest.y)
	drawEyes(ctx, (nest.x + 0.5) * cell, (nest.y + 0.68) * cell, cell * 0.2, lookX, lookY, palette)
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
	ctx.fillStyle = palette.dust
	ctx.beginPath()
	for (let i = 0; i < SPECKS.length; i += 3) {
		const cx = (x + SPECKS[i]) * cell
		const cy = (y + SPECKS[i + 1]) * cell
		ctx.moveTo(cx + SPECKS[i + 2] * cell, cy)
		ctx.arc(cx, cy, SPECKS[i + 2] * cell, 0, TAU)
	}
	ctx.fill()
}

/**
 * The charging dock: a green plate with a lightning bolt, filled while
 * the dock is ready and only outlined while it recharges; a ready dock
 * glows while motion is allowed.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {object} state Game state
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 * @param {{time: number, motion: boolean}} view Frame inputs
 */
function drawDock(ctx, state, cell, palette, view) {
	const { x, y } = state.room.dock
	const ready = state.dockMs === 0
	ctx.fillStyle = palette.green
	roundedRect(ctx, (x + 0.08) * cell, (y + 0.08) * cell, cell * 0.84, cell * 0.84, cell * 0.15)
	if (ready && view.motion) {
		ctx.globalAlpha = 0.3 + 0.7 * pulse(view.time, 1200)
		ctx.lineWidth = Math.max(1, cell * 0.08)
		ctx.strokeStyle = palette.yellow
		ctx.beginPath()
		ctx.arc((x + 0.5) * cell, (y + 0.5) * cell, cell * 0.56, 0, TAU)
		ctx.stroke()
		ctx.globalAlpha = 1
	}
	ctx.beginPath()
	ctx.moveTo((x + BOLT[0]) * cell, (y + BOLT[1]) * cell)
	for (let i = 2; i < BOLT.length; i += 2) ctx.lineTo((x + BOLT[i]) * cell, (y + BOLT[i + 1]) * cell)
	ctx.closePath()
	ctx.lineWidth = Math.max(1, cell * 0.06)
	ctx.strokeStyle = palette.yellow
	if (ready) {
		ctx.fillStyle = palette.yellow
		ctx.fill()
	} else {
		ctx.stroke()
	}
}
