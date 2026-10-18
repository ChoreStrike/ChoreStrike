/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { createState } from '@/components/Minigames/games/tiles/logic.js'
import { render } from '@/components/Minigames/games/tiles/render.js'
import { count, fakeContext, namedPalette } from '../helpers/canvasTestHelpers.js'

const VIEW = { width: 320, height: 400, palette: namedPalette(), time: 0, motion: false }

/**
 * Tile Breaker on the arcade palette (CS-PLAN-086 WP-G): tiles marked by
 * grime, a wooden squeegee that shows its power-up, iridescent bubbles and
 * colored drops.
 */
describe('Tile Breaker renderer', () => {
	it('draws the tiles by grime with a shine, streaks or speckles, then the squeegee and the bubble', () => {
		const { ctx, fills, strokes } = fakeContext()
		const state = createState(() => 0.99)
		state.tiles[0] = 0
		state.tiles[1] = 2

		render(ctx, state, VIEW)

		// A clean tile with its shine, two layers with three speckles, one layer with streaks.
		expect(fills.slice(0, 7)).toEqual(['floor', 'tealLight', 'cocoa', 'tan', 'tan', 'tan', 'tan'])
		expect(strokes.slice(0, 2)).toEqual(['paper', 'cocoa'])
		expect(count(strokes, 'cocoa')).toBe(38)
		// The blade, the wooden bar with its highlight, the bubble with its sheen.
		expect(fills.slice(-4)).toEqual(['ink', 'wood', 'woodLight', 'paper'])
		expect(strokes.slice(-3)).toEqual(['teal', 'coralLight', 'yellow'])
		const bubble = ctx.arc.mock.calls.find(([, , radius]) => radius === 7)
		expect(bubble[0]).toBeCloseTo(160)
		expect(bubble[1]).toBeCloseTo(363)
	})

	it('rings the bubbles blue while floor wax slows them', () => {
		const { ctx, strokes } = fakeContext()
		const state = createState(() => 0.99)
		state.waxMs = 1000

		render(ctx, state, VIEW)

		expect(strokes.slice(-3)).toEqual(['blue', 'coralLight', 'yellow'])
	})

	it('shows a widened squeegee glowing and a narrowed one coated', () => {
		const wide = fakeContext()
		const state = createState(() => 0.99)
		state.widthMs = 1000
		state.squeegee.width = 4.5
		render(wide.ctx, state, VIEW)
		expect(wide.fills.slice(-5)).toEqual(['yellow', 'ink', 'wood', 'woodLight', 'paper'])

		const narrow = fakeContext()
		state.squeegee.width = 2
		render(narrow.ctx, state, VIEW)
		expect(narrow.fills.slice(-5)).toEqual(['cocoa', 'ink', 'wood', 'woodLight', 'paper'])
	})

	it('gives every drop its own shape and color', () => {
		const shapes = (kind) => {
			const recording = fakeContext()
			const state = createState(() => 0.99)
			state.drops.push({ x: 4, y: 12, kind })
			render(recording.ctx, state, VIEW)
			return recording
		}

		const sponge = shapes('sponge')
		expect(sponge.fills).toContain('yellow')
		expect(sponge.ctx.arc).toHaveBeenCalledTimes(3 + 3)

		const spray = shapes('spray')
		expect(spray.fills).toContain('blue')
		expect(spray.fills).toContain('teal')
		expect(spray.ctx.fillRect).toHaveBeenCalledTimes(1 + 1 + 2)

		const wax = shapes('wax')
		expect(wax.fills).toContain('coral')
		expect(wax.ctx.closePath).toHaveBeenCalledTimes(1)

		const mud = shapes('mud')
		expect(count(mud.fills, 'cocoa')).toBe(5)
		expect(mud.ctx.arc).toHaveBeenCalledTimes(5 + 3)
	})

	it('falls back to plain rectangle paths without roundRect support, so outlines stay on their shape', () => {
		const { ctx } = fakeContext({ roundRect: false })
		const state = createState(() => 0.99)
		state.drops.push({ x: 4, y: 12, kind: 'sponge' })

		expect(() => render(ctx, state, VIEW)).not.toThrow()
		// 40 tiles, the squeegee's blade and bar, the sponge.
		expect(ctx.rect).toHaveBeenCalledTimes(40 + 2 + 1)
	})
})
