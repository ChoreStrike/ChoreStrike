/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest } from '@jest/globals'
import { createState } from '@/components/Minigames/games/tiles/logic.js'
import { render } from '@/components/Minigames/games/tiles/render.js'

const PALETTE = {
	floor: 'floor',
	tile: 'tile',
	furniture: 'furniture',
	hose: 'hose',
	nozzle: 'nozzle',
	outline: 'outline',
	crumb: 'crumb',
	golden: 'golden',
	sparkle: 'sparkle',
}

const fakeContext = ({ roundRect = true } = {}) => {
	const fills = []
	const ctx = {
		fillStyle: '',
		strokeStyle: '',
		lineWidth: 1,
		globalAlpha: 1,
		fillRect: jest.fn(() => fills.push(ctx.fillStyle)),
		beginPath: jest.fn(),
		closePath: jest.fn(),
		rect: jest.fn(),
		arc: jest.fn(),
		fill: jest.fn(() => fills.push(ctx.fillStyle)),
		stroke: jest.fn(),
		moveTo: jest.fn(),
		lineTo: jest.fn(),
	}
	if (roundRect) ctx.roundRect = jest.fn()
	return { ctx, fills }
}

const VIEW = { width: 320, height: 400, palette: PALETTE, sparkles: [] }

describe('Tile Breaker renderer', () => {
	it('draws the floor, the tiles by grime, the squeegee and the bubble from the theme palette', () => {
		const { ctx, fills } = fakeContext()
		const state = createState(() => 0.99)
		state.tiles[0] = 0
		state.tiles[1] = 2

		render(ctx, state, VIEW)

		expect(fills[0]).toBe('floor')
		// Clean (tile), two layers (nozzle) with three speckles, one layer (furniture).
		expect(fills.slice(1, 7)).toEqual(['tile', 'nozzle', 'furniture', 'furniture', 'furniture', 'furniture'])
		expect(fills).toEqual(expect.arrayContaining(['hose']))
		// The speckles mark the double grime (never color alone); then the bubble's ring and highlight.
		expect(ctx.arc).toHaveBeenCalledTimes(3 + 2)
		const [cx, cy] = ctx.arc.mock.calls.at(-2)
		expect(cx).toBeCloseTo(160)
		expect(cy).toBeCloseTo(363)
	})

	it('gives every drop its own shape', () => {
		const shapes = (kind) => {
			const { ctx, fills } = fakeContext()
			const state = createState(() => 0.99)
			state.drops.push({ x: 4, y: 12, kind })
			render(ctx, state, VIEW)
			return { ctx, fills }
		}

		const sponge = shapes('sponge')
		expect(sponge.fills).toContain('golden')
		expect(sponge.ctx.arc).toHaveBeenCalledTimes(3 + 2)

		const spray = shapes('spray')
		expect(spray.ctx.fillRect).toHaveBeenCalledTimes(1 + 2)

		const wax = shapes('wax')
		expect(wax.fills).toContain('crumb')
		expect(wax.ctx.closePath).toHaveBeenCalledTimes(1)

		const mud = shapes('mud')
		expect(mud.ctx.arc).toHaveBeenCalledTimes(5 + 2)
		expect(mud.ctx.closePath).not.toHaveBeenCalled()
	})

	it('draws rising rings only for given sparkles and restores the alpha', () => {
		const { ctx } = fakeContext()

		render(ctx, createState(() => 0.99), { ...VIEW, sparkles: [{ x: 5, y: 6.6, progress: 0.5 }] })

		expect(ctx.arc).toHaveBeenCalledTimes(2 + 3)
		expect(ctx.globalAlpha).toBe(1)
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
