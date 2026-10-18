/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/**
 * Canvas primitives shared by the arcade renderers (CS-PLAN-086 WP-G):
 * plain drawing helpers without state. Shapes that every game needs —
 * rounded rectangles, discs, stars and a pair of eyes — live here once;
 * particles and popups belong to the shell's effects layer.
 */

export const TAU = Math.PI * 2

/**
 * Fill a rounded rectangle (a plain one without `roundRect` support). The
 * shape stays the current path, so a caller may stroke it afterwards.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {number} x Left edge in CSS pixels
 * @param {number} y Top edge in CSS pixels
 * @param {number} width Width in CSS pixels
 * @param {number} height Height in CSS pixels
 * @param {number} radius Corner radius in CSS pixels
 */
export function roundedRect(ctx, x, y, width, height, radius) {
	ctx.beginPath()
	if (typeof ctx.roundRect === 'function') {
		ctx.roundRect(x, y, width, height, radius)
	} else {
		ctx.rect(x, y, width, height)
	}
	ctx.fill()
}

/**
 * Fill a disc; the circle stays the current path for a stroke.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {number} cx Center x in CSS pixels
 * @param {number} cy Center y in CSS pixels
 * @param {number} radius Radius in CSS pixels
 */
export function disc(ctx, cx, cy, radius) {
	ctx.beginPath()
	ctx.arc(cx, cy, radius, 0, TAU)
	ctx.fill()
}

/**
 * Fill a four-pointed star.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {number} cx Center x in CSS pixels
 * @param {number} cy Center y in CSS pixels
 * @param {number} radius Outer radius in CSS pixels
 */
export function star(ctx, cx, cy, radius) {
	ctx.beginPath()
	for (let point = 0; point < 8; point++) {
		const reach = point % 2 === 0 ? radius : radius * 0.4
		const angle = (point / 8) * TAU - Math.PI / 2
		const x = cx + Math.cos(angle) * reach
		const y = cy + Math.sin(angle) * reach
		if (point === 0) ctx.moveTo(x, y)
		else ctx.lineTo(x, y)
	}
	ctx.closePath()
	ctx.fill()
}

/**
 * Two eyes looking toward (lookX, lookY); closed eyes are two short lines.
 *
 * @param {CanvasRenderingContext2D} ctx Drawing context
 * @param {number} cx Center between the eyes, x in CSS pixels
 * @param {number} cy Eye line, y in CSS pixels
 * @param {number} size Eye size in CSS pixels (about the eye's diameter)
 * @param {number} lookX Gaze -1..1 (left to right)
 * @param {number} lookY Gaze -1..1 (up to down)
 * @param {{paper: string, ink: string}} palette Board colors
 * @param {boolean} [closed] Closed eyes (a crash, a sleeping bunny)
 */
export function drawEyes(ctx, cx, cy, size, lookX, lookY, palette, closed = false) {
	const gap = size * 0.55
	for (let side = -1; side <= 1; side += 2) {
		const ex = cx + side * gap
		if (closed) {
			ctx.beginPath()
			ctx.moveTo(ex - size * 0.3, cy)
			ctx.lineTo(ex + size * 0.3, cy)
			ctx.lineWidth = Math.max(1, size * 0.2)
			ctx.strokeStyle = palette.ink
			ctx.stroke()
			continue
		}
		ctx.fillStyle = palette.paper
		disc(ctx, ex, cy, size * 0.42)
		ctx.fillStyle = palette.ink
		disc(ctx, ex + lookX * size * 0.14, cy + lookY * size * 0.14, size * 0.2)
	}
}

/**
 * @param {number} from Start value
 * @param {number} to End value
 * @param {number} t Share 0..1
 * @return {number} The value t of the way from `from` to `to`
 */
export function lerp(from, to, t) {
	return from + (to - from) * t
}

/**
 * A 0..1 sine pulse, for idle motion that the motion opt-in allows.
 *
 * @param {number} time Frame time in milliseconds
 * @param {number} periodMs Length of one pulse
 * @return {number} 0..1
 */
export function pulse(time, periodMs) {
	return 0.5 + 0.5 * Math.sin((time / periodMs) * TAU)
}
