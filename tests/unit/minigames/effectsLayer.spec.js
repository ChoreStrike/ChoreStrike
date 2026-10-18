/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach } from '@jest/globals'
import { FLASH_MS, PARTICLE_MS, POPUP_MS, SHAKE_MS, createEffectsLayer } from '@/components/Minigames/effectsLayer.js'
import { fakeContext, namedPalette } from '../helpers/canvasTestHelpers.js'
import { seededRandom } from '../helpers/gameTestHelpers.js'

const CELL = 20
const VIEW = { width: 400, height: 400, cell: CELL }
const CENTER = { x: 10, y: 10 }

/**
 * CS-PLAN-086 WP-G: the shell's pooled particles, score popups, shake and
 * flash — deterministic with an injected clock and random source.
 */
describe('effects layer', () => {
	let clock
	let layer
	let palette

	beforeEach(() => {
		clock = { now: 1000 }
		layer = createEffectsLayer({ random: seededRandom(7), clock: () => clock.now })
		palette = namedPalette()
	})

	/** Draw a frame at `time` and answer the recording context. */
	const frame = (time) => {
		const recording = fakeContext()
		layer.update(time)
		layer.draw(recording.ctx, VIEW, time)
		return recording
	}

	it('bursts golden stars at a pickup that fade out over their lifetime', () => {
		layer.react({ type: 'pickup', kind: 'normal', x: 2, y: 3 }, 2.5, 3.5, palette, CENTER)

		const first = frame(1000)
		expect(first.fills).toEqual(new Array(10).fill('yellow'))
		expect(first.ctx.closePath).toHaveBeenCalledTimes(10)
		expect(first.ctx.globalAlpha).toBe(1)

		const later = frame(1000 + PARTICLE_MS / 2)
		expect(later.fills).toHaveLength(10)
		expect(later.ctx.moveTo.mock.calls[0]).not.toEqual(first.ctx.moveTo.mock.calls[0])

		expect(frame(1000 + PARTICLE_MS).fills).toEqual([])
	})

	it('colors a bonus teal, a penalty cocoa and a vacuumed patch dust, and centers a cleared wall', () => {
		layer.react({ type: 'pickup', kind: 'bonus' }, 1, 1, palette, CENTER)
		expect(frame(1000).fills).toEqual(new Array(16).fill('teal'))
		layer.clear()

		layer.react({ type: 'penalty' }, 1, 1, palette, CENTER)
		const penalty = frame(1000)
		expect(penalty.fills).toEqual(new Array(8).fill('cocoa'))
		expect(penalty.ctx.arc).toHaveBeenCalledTimes(8)
		layer.clear()

		layer.react({ type: 'clean' }, 1, 1, palette, CENTER)
		expect(frame(1000).fills).toEqual(new Array(3).fill('dust'))
		layer.clear()

		layer.react({ type: 'cleared' }, 1, 1, palette, CENTER)
		const cleared = fakeContext()
		layer.draw(cleared.ctx, VIEW, 1000)
		expect(cleared.fills).toEqual(new Array(40).fill('yellow'))
		for (const [x] of cleared.ctx.moveTo.mock.calls) expect(x).toBe(CENTER.x * CELL)
	})

	it('does nothing for events without a board effect', () => {
		layer.react({ type: 'bounce' }, 1, 1, palette, CENTER)
		layer.react({ type: 'flip' }, 1, 1, palette, CENTER)
		const { fills, ctx } = frame(1000)
		expect(fills).toEqual([])
		expect(ctx.fillRect).not.toHaveBeenCalled()
		expect(layer.offset(1000, CELL)).toEqual({ x: 0, y: 0 })
	})

	it('shakes the board and flashes it coral on a lost life, both fading out', () => {
		layer.react({ type: 'miss' }, 0, 0, palette, CENTER)

		const shaken = layer.offset(1000, CELL)
		expect(Math.hypot(shaken.x, shaken.y)).toBeGreaterThan(0)
		expect(Math.hypot(shaken.x, shaken.y)).toBeLessThanOrEqual(0.15 * CELL * Math.SQRT2)
		expect(Math.hypot(...Object.values(layer.offset(1000 + SHAKE_MS / 2, CELL)))).toBeLessThan(Math.hypot(shaken.x, shaken.y) + 0.01)
		expect(layer.offset(1000 + SHAKE_MS, CELL)).toEqual({ x: 0, y: 0 })

		const flashed = frame(1000)
		expect(flashed.ctx.fillRect).toHaveBeenCalledWith(0, 0, 400, 400)
		expect(flashed.fills).toEqual(['coral'])
		expect(flashed.ctx.globalAlpha).toBe(1)
		expect(frame(1000 + FLASH_MS).ctx.fillRect).not.toHaveBeenCalled()
	})

	it('a crash shakes harder than a miss, and the stronger shake wins while one runs', () => {
		layer.react({ type: 'miss' }, 0, 0, palette, CENTER)
		const miss = Math.hypot(...Object.values(layer.offset(1001, CELL)))
		layer.react({ type: 'crash' }, 0, 0, palette, CENTER)
		const crash = Math.hypot(...Object.values(layer.offset(1001, CELL)))
		layer.react({ type: 'miss' }, 0, 0, palette, CENTER)
		const both = Math.hypot(...Object.values(layer.offset(1001, CELL)))

		expect(crash).toBeCloseTo(miss * 2)
		expect(both).toBeCloseTo(crash)
	})

	it('floats a score popup that rises and fades, in gold for points and coral for a penalty', () => {
		layer.score(1, 1, 10, palette)

		const fresh = frame(1000)
		expect(fresh.ctx.strokeText).toHaveBeenCalledWith('+10', CELL, CELL)
		expect(fresh.ctx.fillText).toHaveBeenCalledWith('+10', CELL, CELL)
		expect(fresh.ctx.fillStyle).toBe('yellow')
		expect(fresh.ctx.strokeStyle).toBe('ink')
		expect(fresh.ctx.font).toBe('bold 17px system-ui, sans-serif')

		const risen = fakeContext()
		layer.draw(risen.ctx, VIEW, 1000 + POPUP_MS * 0.8)
		const [, x, y] = risen.ctx.fillText.mock.calls[0]
		expect(x).toBe(CELL)
		expect(y).toBeCloseTo((1 - 0.8 * 1.2) * CELL)
		expect(frame(1000 + POPUP_MS).ctx.fillText).not.toHaveBeenCalled()

		clock.now = 1000 + POPUP_MS
		layer.score(2, 2, -20, palette)
		const penalty = frame(1000 + POPUP_MS)
		expect(penalty.ctx.fillText).toHaveBeenCalledWith('−20', 2 * CELL, 2 * CELL)
		expect(penalty.ctx.fillStyle).toBe('coral')
	})

	it('pools its particles: a burst beyond the pool reuses the oldest ones', () => {
		layer.burst(1, 1, { count: 500, speed: 1, shape: 'dot', size: 0.1 }, 'dust')
		expect(frame(1000).fills).toHaveLength(96)
	})

	it('clear drops every particle, popup, shake and flash', () => {
		layer.react({ type: 'crash' }, 1, 1, palette, CENTER)
		layer.react({ type: 'pickup', kind: 'normal' }, 1, 1, palette, CENTER)
		layer.score(1, 1, 10, palette)

		layer.clear()

		const cleared = frame(1000)
		expect(cleared.fills).toEqual([])
		expect(cleared.ctx.fillText).not.toHaveBeenCalled()
		expect(layer.offset(1000, CELL)).toEqual({ x: 0, y: 0 })
	})
})
