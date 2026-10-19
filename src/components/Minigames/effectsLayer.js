/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { disc, star } from './draw.js'

const MAX_PARTICLES = 96
const MAX_POPUPS = 6
export const PARTICLE_MS = 550
export const POPUP_MS = 750
export const SHAKE_MS = 320
export const FLASH_MS = 260
/** Downward pull on particles, in cells per second². */
const GRAVITY = 4
const ZERO = Object.freeze({ x: 0, y: 0 })

/** The burst of each game event, in colors of the board palette. */
const BURSTS = Object.freeze({
	pickup: { color: 'yellow', count: 10, speed: 3, shape: 'star', size: 0.12 },
	bonus: { color: 'teal', count: 16, speed: 4, shape: 'star', size: 0.14 },
	penalty: { color: 'cocoa', count: 8, speed: 2.5, shape: 'dot', size: 0.1 },
	clean: { color: 'dust', count: 3, speed: 1.2, shape: 'dot', size: 0.07 },
	cleared: { color: 'yellow', count: 40, speed: 6, shape: 'star', size: 0.14 },
})

/** Board shake per event, in cells. */
const SHAKES = Object.freeze({ miss: 0.15, crash: 0.3 })

/**
 * The shell's effects layer for canvas games (CS-PLAN-086 WP-G): short-lived
 * particles at pickups, floating score popups, a board shake with a flash
 * on a lost life. Everything is pooled, so a frame allocates nothing, and
 * the shell calls in only while the motion opt-in allows it. Positions are
 * board cells; the layer scales them with the cell size at draw time.
 *
 * @param {object} [options] Sources, replaced in tests
 * @param {function(): number} [options.random] Random source in [0, 1)
 * @param {function(): number} [options.clock] Current time in milliseconds
 * @return {object} The layer
 */
export function createEffectsLayer({ random = Math.random, clock = () => performance.now() } = {}) {
	const particles = []
	for (let i = 0; i < MAX_PARTICLES; i++) {
		particles.push({ live: false, x: 0, y: 0, vx: 0, vy: 0, born: 0, size: 0, color: '', shape: 'dot' })
	}
	const popups = []
	for (let i = 0; i < MAX_POPUPS; i++) {
		popups.push({ live: false, x: 0, y: 0, born: 0, text: '', color: '', outline: '' })
	}
	const offsetPoint = { x: 0, y: 0 }
	let nextParticle = 0
	let nextPopup = 0
	let shakeUntil = 0
	let shakeStrength = 0
	let flashUntil = 0
	let flashColor = ''
	let last = null
	// The popup font, rebuilt only when the cell size changes.
	let popupFont = ''
	let popupFontCell = 0

	/**
	 * @param {number} x Origin column (cell units)
	 * @param {number} y Origin row (cell units)
	 * @param {{count: number, speed: number, shape: string, size: number}} options Burst shape
	 * @param {string} color Particle color
	 */
	function burst(x, y, options, color) {
		const now = clock()
		for (let i = 0; i < options.count; i++) {
			const particle = particles[nextParticle]
			nextParticle = (nextParticle + 1) % MAX_PARTICLES
			const angle = random() * Math.PI * 2
			const pace = options.speed * (0.4 + random() * 0.6)
			particle.live = true
			particle.x = x
			particle.y = y
			particle.vx = Math.cos(angle) * pace
			particle.vy = Math.sin(angle) * pace - options.speed * 0.4
			particle.born = now
			particle.size = options.size * (0.6 + random() * 0.8)
			particle.color = color
			particle.shape = options.shape
		}
	}

	/**
	 * @param {number} x Column (cell units)
	 * @param {number} y Row (cell units)
	 * @param {string} text Short text, e.g. "+10"
	 * @param {string} color Fill color
	 * @param {string} outline Outline color
	 */
	function popup(x, y, text, color, outline) {
		const item = popups[nextPopup]
		nextPopup = (nextPopup + 1) % MAX_POPUPS
		item.live = true
		item.x = x
		item.y = y
		item.born = clock()
		item.text = text
		item.color = color
		item.outline = outline
	}

	/**
	 * @param {number} strength Reach of the shake in cells
	 */
	function shake(strength) {
		const now = clock()
		shakeStrength = Math.max(now < shakeUntil ? shakeStrength : 0, strength)
		shakeUntil = now + SHAKE_MS
	}

	/**
	 * @param {string} color Tint drawn over the board, fading out
	 */
	function flash(color) {
		flashColor = color
		flashUntil = clock() + FLASH_MS
	}

	/**
	 * The board effect of one game event: a burst at its cell (a cleared
	 * wall or room bursts at the board center), a shake and a flash on a
	 * lost life; other events have no board effect.
	 *
	 * @param {{type: string, kind?: string}} event Game event
	 * @param {number} x Event column (cell units)
	 * @param {number} y Event row (cell units)
	 * @param {object} palette Board colors
	 * @param {{x: number, y: number}} center Board center (cell units)
	 */
	function react(event, x, y, palette, center) {
		const options = BURSTS[event.type === 'pickup' && event.kind === 'bonus' ? 'bonus' : event.type]
		if (options) {
			const centered = event.type === 'cleared'
			burst(centered ? center.x : x, centered ? center.y : y, options, palette[options.color])
		}
		if (SHAKES[event.type]) {
			shake(SHAKES[event.type])
			flash(palette.coral)
		}
	}

	/**
	 * A floating score change.
	 *
	 * @param {number} x Column (cell units)
	 * @param {number} y Row (cell units)
	 * @param {number} delta Score change, never zero
	 * @param {object} palette Board colors
	 */
	function score(x, y, delta, palette) {
		popup(x, y, delta > 0 ? `+${delta}` : `−${-delta}`, delta > 0 ? palette.yellow : palette.coral, palette.ink)
	}

	/**
	 * Age and move everything by the time since the last update.
	 *
	 * @param {number} time Frame time in milliseconds
	 */
	function update(time) {
		const dt = last === null ? 0 : Math.min(0.05, Math.max(0, (time - last) / 1000))
		last = time
		for (const particle of particles) {
			if (!particle.live) continue
			if (time - particle.born >= PARTICLE_MS) {
				particle.live = false
				continue
			}
			particle.vy += GRAVITY * dt
			particle.x += particle.vx * dt
			particle.y += particle.vy * dt
		}
		for (const item of popups) {
			if (item.live && time - item.born >= POPUP_MS) item.live = false
		}
	}

	/**
	 * The board's shake offset for this frame (a shared zero while still).
	 *
	 * @param {number} time Frame time in milliseconds
	 * @param {number} cell Cell edge in CSS pixels
	 * @return {{x: number, y: number}} Offset in CSS pixels
	 */
	function offset(time, cell) {
		if (time >= shakeUntil) return ZERO
		const left = (shakeUntil - time) / SHAKE_MS
		const reach = shakeStrength * left * cell
		offsetPoint.x = Math.sin(time * 0.11) * reach
		offsetPoint.y = Math.cos(time * 0.17) * reach
		return offsetPoint
	}

	/**
	 * Draw the live particles, popups and flash over the rendered board.
	 *
	 * @param {CanvasRenderingContext2D} ctx Context scaled to CSS pixels
	 * @param {{width: number, height: number, cell: number}} view Frame inputs
	 * @param {number} time Frame time in milliseconds
	 */
	function draw(ctx, view, time) {
		const cell = view.cell
		for (const particle of particles) {
			if (!particle.live) continue
			ctx.globalAlpha = Math.max(0, 1 - (time - particle.born) / PARTICLE_MS)
			ctx.fillStyle = particle.color
			if (particle.shape === 'star') star(ctx, particle.x * cell, particle.y * cell, particle.size * cell)
			else disc(ctx, particle.x * cell, particle.y * cell, particle.size * cell)
		}
		drawPopups(ctx, cell, time)
		if (time < flashUntil) {
			ctx.globalAlpha = 0.35 * (flashUntil - time) / FLASH_MS
			ctx.fillStyle = flashColor
			ctx.fillRect(0, 0, view.width, view.height)
		}
		ctx.globalAlpha = 1
	}

	function drawPopups(ctx, cell, time) {
		let styled = false
		for (const item of popups) {
			if (!item.live) continue
			if (!styled) {
				if (cell !== popupFontCell) {
					popupFont = `bold ${Math.round(cell * 0.85)}px system-ui, sans-serif`
					popupFontCell = cell
				}
				ctx.font = popupFont
				ctx.textAlign = 'center'
				ctx.textBaseline = 'middle'
				ctx.lineWidth = Math.max(2, cell * 0.12)
				styled = true
			}
			const progress = (time - item.born) / POPUP_MS
			ctx.globalAlpha = progress < 0.6 ? 1 : Math.max(0, 1 - (progress - 0.6) / 0.4)
			const px = item.x * cell
			const py = (item.y - progress * 1.2) * cell
			ctx.strokeStyle = item.outline
			ctx.strokeText(item.text, px, py)
			ctx.fillStyle = item.color
			ctx.fillText(item.text, px, py)
		}
	}

	/** Drop everything (a new round). */
	function clear() {
		for (const particle of particles) particle.live = false
		for (const item of popups) item.live = false
		shakeUntil = 0
		flashUntil = 0
		last = null
	}

	return { burst, popup, shake, flash, react, score, update, offset, draw, clear }
}
