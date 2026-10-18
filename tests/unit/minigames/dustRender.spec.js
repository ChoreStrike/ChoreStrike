/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest } from '@jest/globals'
import { createState } from '@/components/Minigames/games/dust/logic.js'
import { render } from '@/components/Minigames/games/dust/render.js'

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

const fakeContext = () => {
	const fills = []
	const ctx = {
		fillStyle: '',
		strokeStyle: '',
		lineWidth: 1,
		globalAlpha: 1,
		fillRect: jest.fn(() => fills.push(ctx.fillStyle)),
		beginPath: jest.fn(),
		closePath: jest.fn(),
		arc: jest.fn(),
		fill: jest.fn(() => fills.push(ctx.fillStyle)),
		stroke: jest.fn(),
		moveTo: jest.fn(),
		lineTo: jest.fn(),
	}
	return { ctx, fills }
}

const VIEW = { width: 300, height: 300, palette: PALETTE, sparkles: [] }
const count = (fills, color) => fills.filter((fill) => fill === color).length

describe('Dust Runner renderer', () => {
	it('draws the floor, the furniture, the nests and four specks in one fill per dust patch from the theme palette', () => {
		const { ctx, fills } = fakeContext()
		const state = createState(() => 0.5)

		render(ctx, state, VIEW)

		expect(fills[0]).toBe('floor')
		// 225 cells: 131 floor (129 with dust), 94 walls, furniture and nests.
		expect(count(fills, 'furniture')).toBe(94 + 129)
		expect(count(fills, 'tile')).toBeGreaterThan(50)

		const cleaned = fakeContext()
		state.dust.fill(0)
		render(cleaned.ctx, state, VIEW)
		expect(count(cleaned.fills, 'furniture')).toBe(94)
		expect(ctx.arc.mock.calls.length - cleaned.ctx.arc.mock.calls.length).toBe(4 * 129)
	})

	it('fills the dock bolt while the dock is ready and only outlines it while it recharges', () => {
		const state = createState(() => 0.5)
		const ready = fakeContext()
		render(ready.ctx, state, VIEW)

		state.dockMs = 5000
		const recharging = fakeContext()
		render(recharging.ctx, state, VIEW)

		expect(ready.ctx.closePath).toHaveBeenCalledTimes(1)
		expect(count(ready.fills, 'golden')).toBe(1)
		expect(count(recharging.fills, 'golden')).toBe(0)
		expect(recharging.ctx.stroke.mock.calls.length).toBe(ready.ctx.stroke.mock.calls.length + 1)
	})

	it('draws bunnies with ears that droop while catchable, and a ring around the charged robot', () => {
		const state = createState(() => 0.5)
		state.dust.fill(0)
		// Out on the floor at (5, 9): body centered at 110, 191.6 with 20 px cells.
		Object.assign(state.bunnies[0], { x: 5, y: 9, waitMs: 0 })
		const earY = (ctx) => ctx.arc.mock.calls.find(([x, y]) => x > 100 && x < 120 && y > 170 && y < 200)[1]

		const roaming = fakeContext()
		render(roaming.ctx, state, VIEW)
		state.bunnies[0].catchable = true
		state.chargeMs = 3000
		const catchable = fakeContext()
		render(catchable.ctx, state, VIEW)

		// Upright ears sit above the body, drooping ones beside it.
		expect(earY(roaming.ctx)).toBeLessThan(earY(catchable.ctx))
		expect(count(roaming.fills, 'nozzle')).toBe(count(catchable.fills, 'nozzle') + 3)
		expect(count(catchable.fills, 'golden')).toBe(1 + 3)
		expect(catchable.ctx.arc).toHaveBeenCalledTimes(roaming.ctx.arc.mock.calls.length + 1)
	})

	it('points the robot sensor the way it heads', () => {
		const sensor = (direction) => {
			const { ctx } = fakeContext()
			const state = createState(() => 0.5)
			state.robot.direction = direction
			render(ctx, state, VIEW)
			return ctx.arc.mock.calls.at(-1)
		}

		// The start cell (1, 1) is centered at 30, 30 with 20 px cells.
		expect(sensor('right')[0]).toBeCloseTo(34.4)
		expect(sensor('right')[1]).toBeCloseTo(30)
		expect(sensor('up')[0]).toBeCloseTo(30)
		expect(sensor('up')[1]).toBeCloseTo(25.6)
	})

	it('draws stars only for given sparkles and restores the alpha', () => {
		const state = createState(() => 0.5)
		state.dust.fill(0)
		const { ctx } = fakeContext()

		render(ctx, state, { ...VIEW, sparkles: [{ x: 10, y: 12, progress: 0.5 }] })

		// The dock bolt, then four rays.
		expect(ctx.moveTo).toHaveBeenCalledTimes(1 + 4)
		expect(ctx.globalAlpha).toBe(1)
	})
})
