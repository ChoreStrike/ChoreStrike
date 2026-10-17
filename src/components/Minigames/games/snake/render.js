/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { COLS, ROWS } from './logic.js'

/**
 * Draw a Crumb Snake frame: tiled floor, furniture, the crumb and the
 * vacuum hose with its nozzle. Colors come from the shell's theme palette,
 * so the board follows the light and dark Nextcloud themes.
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
	ctx.fillStyle = palette.tile
	for (let y = 0; y < ROWS; y++) {
		for (let x = (y % 2); x < COLS; x += 2) {
			ctx.fillRect(x * cell, y * cell, cell, cell)
		}
	}
	ctx.fillStyle = palette.furniture
	for (const piece of state.furniture) {
		roundedRect(ctx, piece.x * cell + 1, piece.y * cell + 1, cell - 2, cell - 2, cell * 0.2)
	}
	drawCrumb(ctx, state.crumb, cell, palette)
	drawHose(ctx, state, cell, palette)
	drawSparkles(ctx, view.sparkles, cell, palette)
}

function drawCrumb(ctx, crumb, cell, palette) {
	ctx.beginPath()
	ctx.arc((crumb.x + 0.5) * cell, (crumb.y + 0.5) * cell, cell * (crumb.golden ? 0.36 : 0.28), 0, Math.PI * 2)
	ctx.fillStyle = crumb.golden ? palette.golden : palette.crumb
	ctx.fill()
	ctx.lineWidth = Math.max(1, cell * 0.06)
	ctx.strokeStyle = palette.outline
	ctx.stroke()
}

function drawHose(ctx, state, cell, palette) {
	ctx.fillStyle = palette.hose
	for (let i = state.snake.length - 1; i > 0; i--) {
		const part = state.snake[i]
		roundedRect(ctx, part.x * cell + 2, part.y * cell + 2, cell - 4, cell - 4, cell * 0.3)
	}
	const head = state.snake[0]
	ctx.fillStyle = palette.nozzle
	roundedRect(ctx, head.x * cell + 1, head.y * cell + 1, cell - 2, cell - 2, cell * 0.25)
}

/**
 * Short-lived stars at recent pickups (only when motion is allowed).
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
			const angle = (Math.PI / 4) * (ray * 2 + 1)
			ctx.moveTo(cx + Math.cos(angle) * radius * 0.4, cy + Math.sin(angle) * radius * 0.4)
			ctx.lineTo(cx + Math.cos(angle) * radius, cy + Math.sin(angle) * radius)
		}
		ctx.stroke()
	}
	ctx.globalAlpha = 1
}

function roundedRect(ctx, x, y, width, height, radius) {
	if (typeof ctx.roundRect === 'function') {
		ctx.beginPath()
		ctx.roundRect(x, y, width, height, radius)
		ctx.fill()
	} else {
		ctx.fillRect(x, y, width, height)
	}
}
