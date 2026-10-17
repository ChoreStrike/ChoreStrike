/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest } from '@jest/globals'
import { createState } from '@/components/Minigames/games/snake/logic.js'
import { render } from '@/components/Minigames/games/snake/render.js'

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
		arc: jest.fn(),
		fill: jest.fn(() => fills.push(ctx.fillStyle)),
		stroke: jest.fn(),
		moveTo: jest.fn(),
		lineTo: jest.fn(),
	}
	if (roundRect) ctx.roundRect = jest.fn()
	return { ctx, fills }
}

describe('Crumb Snake renderer', () => {
	it('draws floor, crumb, hose and nozzle from the theme palette', () => {
		const { ctx, fills } = fakeContext()
		const state = createState(() => 0)
		state.furniture = [{ x: 1, y: 1 }]

		render(ctx, state, { width: 400, height: 400, palette: PALETTE, sparkles: [] })

		expect(fills[0]).toBe('floor')
		expect(fills).toEqual(expect.arrayContaining(['tile', 'furniture', 'crumb', 'hose', 'nozzle']))
		expect(ctx.arc).toHaveBeenCalledWith(190, 210, expect.any(Number), 0, Math.PI * 2)
		expect(ctx.stroke).toHaveBeenCalledTimes(1)
	})

	it('draws the golden crumb larger and sparkles only when given', () => {
		const { ctx, fills } = fakeContext()
		const state = createState(() => 0)
		state.crumb.golden = true

		render(ctx, state, { width: 200, height: 200, palette: PALETTE, sparkles: [{ x: 2, y: 2, progress: 0.5 }] })

		expect(fills).toContain('golden')
		expect(ctx.arc.mock.calls[0][2]).toBeCloseTo(10 * 0.36)
		expect(ctx.moveTo).toHaveBeenCalledTimes(4)
		expect(ctx.globalAlpha).toBe(1)
	})

	it('falls back to plain rectangles without roundRect support', () => {
		const { ctx } = fakeContext({ roundRect: false })
		expect(() => render(ctx, createState(() => 0), { width: 200, height: 200, palette: PALETTE, sparkles: [] })).not.toThrow()
		expect(ctx.fillRect.mock.calls.length).toBeGreaterThan(200)
	})
})
