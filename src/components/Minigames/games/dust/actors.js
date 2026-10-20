/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { DIRECTIONS } from './rooms.js'
import { bunnyInterval } from './bunnies.js'
import { ROBOT_MS, nextMove } from './logic.js'
import { TAU, disc, drawEyes, lerp, pulse } from '../../draw.js'

/** Hop height of a moving bunny, in cells. */
const HOP = 0.12

/**
 * A dust bunny on the floor: a fluffy grey body with two ears (pink
 * inside), standing up while it roams and drooping to the sides while it
 * can be caught (then paler, with closed eyes); its eyes follow the robot.
 * It hops from the cell it came from to its cell over its move interval.
 * A waiting bunny is not drawn — it peeks out of its nest (`render.js`).
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {object} state Game state
 * @param {object} bunny Bunny
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 */
export function drawBunny(ctx, state, bunny, cell, palette) {
	if (bunny.waitMs > 0) {
		return
	}
	const own = bunnyInterval(state.roomIndex) * (bunny.catchable ? 2 : 1)
	const t = Math.min(1, bunny.elapsed / own)
	const cx = (lerp(bunny.fromX, bunny.x, t) + 0.5) * cell
	const cy = (lerp(bunny.fromY, bunny.y, t) + 0.58 - Math.sin(t * Math.PI) * HOP) * cell
	const radius = cell * 0.3
	const body = bunny.catchable ? palette.dustLight : palette.dust
	const earX = radius * (bunny.catchable ? 1.05 : 0.5)
	const earY = radius * (bunny.catchable ? 0.15 : 1.1)
	ctx.lineWidth = Math.max(1, cell * 0.05)
	ctx.strokeStyle = palette.ink
	for (let side = -1; side <= 1; side += 2) {
		ctx.fillStyle = body
		disc(ctx, cx + side * earX, cy - earY, radius * 0.4)
		ctx.stroke()
		ctx.fillStyle = palette.coralLight
		disc(ctx, cx + side * earX, cy - earY, radius * 0.2)
	}
	ctx.fillStyle = body
	disc(ctx, cx, cy, radius)
	ctx.stroke()
	disc(ctx, cx - radius * 0.75, cy + radius * 0.35, radius * 0.4)
	disc(ctx, cx + radius * 0.75, cy + radius * 0.35, radius * 0.4)
	const lookX = Math.sign(state.robot.x - bunny.x)
	const lookY = Math.sign(state.robot.y - bunny.y)
	drawEyes(ctx, cx, cy - radius * 0.1, radius * 0.5, lookX, lookY, palette, bunny.catchable)
}

/**
 * The robot vacuum: a blue disc with a lit bumper and two sensor eyes on
 * the way it heads, a spinning side brush while it moves, and a ring
 * while it is charged (pulsing when motion is allowed). It is drawn on its
 * way to the next cell, so it glides instead of jumping.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {object} state Game state
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 * @param {{time: number, motion: boolean}} view Frame inputs
 */
export function drawRobot(ctx, state, cell, palette, view) {
	const robot = state.robot
	const move = nextMove(state)
	const turn = move ? Math.min(1, robot.elapsed / ROBOT_MS) : 0
	const facing = DIRECTIONS[move ?? robot.direction]
	const cx = (robot.x + 0.5 + facing.x * turn) * cell
	const cy = (robot.y + 0.5 + facing.y * turn) * cell
	const radius = cell * 0.4
	drawBrush(ctx, cx, cy, facing, turn, cell, palette)
	ctx.fillStyle = palette.blue
	disc(ctx, cx, cy, radius)
	ctx.lineWidth = Math.max(1, cell * 0.06)
	ctx.strokeStyle = palette.ink
	ctx.stroke()
	const heading = Math.atan2(facing.y, facing.x)
	ctx.beginPath()
	ctx.arc(cx, cy, radius * 0.82, heading - Math.PI / 3, heading + Math.PI / 3)
	ctx.lineWidth = Math.max(1.5, cell * 0.09)
	ctx.strokeStyle = palette.blueLight
	ctx.stroke()
	if (state.chargeMs > 0) {
		drawCharge(ctx, cx, cy, cell, palette, view)
	}
	drawEyes(ctx, cx + facing.x * cell * 0.1, cy + facing.y * cell * 0.1, cell * 0.22, facing.x, facing.y, palette)
}

/**
 * The side brush at the robot's front-right: three bristles that turn
 * half a round per move.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {number} cx Robot center x in CSS pixels
 * @param {number} cy Robot center y in CSS pixels
 * @param {{x: number, y: number}} facing The way the robot heads
 * @param {number} turn Share of the current move (0..1)
 * @param {number} cell Cell edge in CSS pixels
 * @param {object} palette Board colors
 */
function drawBrush(ctx, cx, cy, facing, turn, cell, palette) {
	const bx = cx + (facing.x - facing.y) * cell * 0.3
	const by = cy + (facing.y + facing.x) * cell * 0.3
	ctx.strokeStyle = palette.tan
	ctx.lineWidth = Math.max(1, cell * 0.05)
	ctx.beginPath()
	for (let bristle = 0; bristle < 3; bristle++) {
		const angle = (turn * 0.5 + bristle / 3) * TAU
		ctx.moveTo(bx, by)
		ctx.lineTo(bx + Math.cos(angle) * cell * 0.22, by + Math.sin(angle) * cell * 0.22)
	}
	ctx.stroke()
}

function drawCharge(ctx, cx, cy, cell, palette, view) {
	ctx.beginPath()
	ctx.arc(cx, cy, cell * 0.5, 0, TAU)
	ctx.lineWidth = Math.max(1.5, cell * 0.08)
	ctx.strokeStyle = palette.yellow
	ctx.globalAlpha = view.motion ? 0.5 + 0.5 * pulse(view.time, 500) : 1
	ctx.stroke()
	ctx.globalAlpha = 1
}
