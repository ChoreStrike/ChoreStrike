/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { createState, step } from '@/components/Minigames/games/snake/logic.js'
import { render } from '@/components/Minigames/games/snake/render.js'
import { fakeContext, namedPalette } from '../helpers/canvasTestHelpers.js'

const CELL = 20
const view = (overrides = {}) => ({ width: 400, height: 400, palette: namedPalette(), time: 0, motion: false, ...overrides })

/** The nozzle's rounded square (0.9 cells wide). */
const nozzle = (ctx) => ctx.roundRect.mock.calls.find(([, , width]) => width === CELL * 0.9)

/**
 * Crumb Snake on the arcade palette (CS-PLAN-086 WP-G): a glide between
 * cells, furniture with a look, a cookie crumb and a nozzle with eyes.
 */
describe('Crumb Snake renderer', () => {
	it('draws the floor, three kinds of furniture, the cookie and the striped hose from the arcade palette', () => {
		const { ctx, fills, strokes } = fakeContext()
		const state = createState(() => 0)
		// Kinds follow the cell: a chair, a plant, a crate.
		state.furniture = [{ x: 0, y: 0 }, { x: 1, y: 0 }, { x: 2, y: 0 }]

		render(ctx, state, view())

		expect(fills[0]).toBe('floor')
		expect(fills).toEqual(expect.arrayContaining(['floorAlt', 'woodDark', 'wood', 'bronze', 'green', 'woodLight', 'cocoa', 'ink', 'paper', 'blueLight']))
		// The cookie's outline, then the hose and its stripe.
		expect(strokes).toEqual(['ink', 'blue', 'blueLight'])
		expect(ctx.lineCap).toBe('round')
	})

	it('glides: every segment is drawn on its way to the next cell', () => {
		const { ctx } = fakeContext()
		const state = createState(() => 0)
		state.elapsed = 75

		render(ctx, state, view())

		// The nozzle half a cell ahead of (5, 10); the tail half way to (4, 10).
		expect(nozzle(ctx).slice(0, 2)).toEqual([120 - CELL * 0.45, 210 - CELL * 0.45])
		expect(ctx.moveTo.mock.calls[0]).toEqual([80, 210])
		expect(ctx.lineTo.mock.calls).toEqual([[100, 210], [120, 210]])
	})

	it('keeps the tail end put while the next move eats the crumb', () => {
		const { ctx } = fakeContext()
		const state = createState(() => 0)
		state.crumb = { x: 6, y: 10, golden: false }
		state.elapsed = 75

		render(ctx, state, view())

		expect(ctx.moveTo.mock.calls[0]).toEqual([70, 210])
	})

	it('looks ahead along a queued turn', () => {
		const { ctx } = fakeContext()
		const state = createState(() => 0)
		step(state, 'up', 0)
		state.elapsed = 75

		render(ctx, state, view())

		expect(nozzle(ctx).slice(0, 2)).toEqual([110 - CELL * 0.45, 200 - CELL * 0.45])
	})

	it('after a crash the nozzle sits bumped into the obstacle with closed eyes', () => {
		const alive = fakeContext()
		render(alive.ctx, createState(() => 0), view())
		const { ctx, strokes } = fakeContext()
		const state = createState(() => 0)
		state.over = true

		render(ctx, state, view())

		expect(nozzle(ctx)[0]).toBeCloseTo(117 - CELL * 0.45)
		expect(strokes).toEqual(['ink', 'blue', 'blueLight', 'ink', 'ink'])
		expect(ctx.arc).toHaveBeenCalledTimes(alive.ctx.arc.mock.calls.length - 4)
	})

	it('draws the golden crumb as a starred coin that pulses only with motion', () => {
		const still = fakeContext()
		const state = createState(() => 0)
		state.crumb.golden = true
		render(still.ctx, state, view())
		expect(still.ctx.arc.mock.calls[0][2]).toBeCloseTo(CELL * 0.34)
		expect(still.fills).toEqual(expect.arrayContaining(['yellow', 'paper']))
		expect(still.ctx.closePath).toHaveBeenCalledTimes(1)

		const moving = fakeContext()
		render(moving.ctx, state, view({ motion: true, time: 225 }))
		expect(moving.ctx.arc.mock.calls[0][2]).toBeCloseTo(CELL * 0.4)
	})

	it('falls back to plain rectangles without roundRect support', () => {
		const { ctx } = fakeContext({ roundRect: false })
		const state = createState(() => 0)
		state.furniture = [{ x: 1, y: 1 }]

		expect(() => render(ctx, state, view())).not.toThrow()
		expect(ctx.rect).toHaveBeenCalled()
	})
})
