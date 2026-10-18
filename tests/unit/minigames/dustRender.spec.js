/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { createState } from '@/components/Minigames/games/dust/logic.js'
import { themeAt } from '@/components/Minigames/games/dust/rooms.js'
import { render } from '@/components/Minigames/games/dust/render.js'
import { count, fakeContext, namedPalette } from '../helpers/canvasTestHelpers.js'

const CELL = 20
const view = (overrides = {}) => ({ width: 300, height: 300, palette: namedPalette(), time: 0, motion: false, ...overrides })

/** The full circle of a given radius (the body of an actor; nests are half circles). */
const arcOf = (ctx, radius) => ctx.arc.mock.calls.find(([, , r, , end]) => Math.abs(r - radius) < 0.001 && end > 6)

/**
 * Dust Runner on the arcade palette (CS-PLAN-086 WP-G): a floor per room,
 * lit furniture, peeking and hopping bunnies, a gliding robot and a dock
 * that glows only with motion.
 */
describe('Dust Runner renderer', () => {
	it('draws the hallway on planks, its furniture in wood with lit edges, and one fill per dust patch', () => {
		const { fills } = fakeContext()
		const state = createState(() => 0.5)
		const recording = fakeContext()

		render(recording.ctx, state, view())

		expect(recording.fills[0]).toBe('floor')
		// 225 cells: 131 floor (129 with dust), 94 walls, furniture and nests.
		expect(count(recording.fills, 'dust')).toBe(129)
		expect(count(recording.fills, 'wood')).toBe(94)
		expect(count(recording.fills, 'woodLight')).toBeGreaterThan(10)
		expect(count(recording.fills, 'woodDark')).toBeGreaterThan(10)
		expect(count(recording.fills, 'grout')).toBeGreaterThan(131)
		expect(fills).toEqual([])

		state.dust.fill(0)
		const cleaned = fakeContext()
		render(cleaned.ctx, state, view())
		expect(count(cleaned.fills, 'dust')).toBe(0)
	})

	it('tiles the kitchen and carpets the living room, each with its own furniture colors', () => {
		const state = createState(() => 0.5)
		expect([0, 1, 2, 5].map(themeAt)).toEqual(['wood', 'tiles', 'carpet', 'carpet'])

		state.roomIndex = 1
		const kitchen = fakeContext()
		render(kitchen.ctx, state, view())
		expect(count(kitchen.fills, 'tealLight')).toBeGreaterThan(50)
		expect(count(kitchen.fills, 'blueLight')).toBe(94)

		state.roomIndex = 0
		const hallway = fakeContext()
		render(hallway.ctx, state, view())
		const litEdges = count(hallway.fills, 'woodLight')

		state.roomIndex = 2
		const living = fakeContext()
		render(living.ctx, state, view())
		// One carpet dot per floor cell, plus the lit furniture edges in the same color.
		expect(count(living.fills, 'coralLight')).toBe(131 + litEdges)
		expect(count(living.fills, 'coral')).toBe(94)
	})

	it('lets waiting bunnies peek out of their nests with eyes on the robot', () => {
		const state = createState(() => 0.5)
		state.dust.fill(0)
		const waiting = fakeContext()
		render(waiting.ctx, state, view())
		// Two nests: two eyes each, plus the robot's two eyes.
		expect(count(waiting.fills, 'paper')).toBe(4 + 2)
		expect(count(waiting.fills, 'dust')).toBe(0)

		Object.assign(state.bunnies[0], { x: 5, y: 9, fromX: 5, fromY: 9, waitMs: 0 })
		const released = fakeContext()
		render(released.ctx, state, view())
		// One nest peeks; the released bunny has two ears, a body and two tufts.
		expect(count(released.fills, 'paper')).toBe(2 + 2 + 2)
		expect(count(released.fills, 'dust')).toBe(5)
		expect(count(released.fills, 'coralLight')).toBe(2)
	})

	it('hops a bunny from the cell it came from, paler and sleepy while catchable', () => {
		const state = createState(() => 0.5)
		state.dust.fill(0)
		Object.assign(state.bunnies[0], { x: 6, y: 9, fromX: 5, fromY: 9, waitMs: 0, elapsed: 150 })

		const roaming = fakeContext()
		render(roaming.ctx, state, view())
		const body = arcOf(roaming.ctx, CELL * 0.3)
		expect(body[0]).toBeCloseTo((5.5 + 0.5) * CELL)
		expect(body[1]).toBeCloseTo((9 + 0.58 - 0.12) * CELL)

		state.bunnies[0].catchable = true
		const catchable = fakeContext()
		render(catchable.ctx, state, view())
		expect(arcOf(catchable.ctx, CELL * 0.3)[0]).toBeCloseTo((5.25 + 0.5) * CELL)
		expect(count(catchable.fills, 'dustLight')).toBe(5)
		// Closed eyes are two lines instead of four discs.
		expect(catchable.ctx.moveTo).toHaveBeenCalledTimes(roaming.ctx.moveTo.mock.calls.length + 2)
		expect(catchable.ctx.arc).toHaveBeenCalledTimes(roaming.ctx.arc.mock.calls.length - 4)
	})

	it('glides the robot toward its next move and holds it at furniture', () => {
		const state = createState(() => 0.5)
		state.dust.fill(0)
		const still = fakeContext()
		render(still.ctx, state, view())
		expect(arcOf(still.ctx, CELL * 0.4).slice(0, 2)).toEqual([30, 30])
		expect(still.strokes).toContain('tan')

		state.robot.elapsed = 75
		const moving = fakeContext()
		render(moving.ctx, state, view())
		expect(arcOf(moving.ctx, CELL * 0.4)[0]).toBeCloseTo(40)

		Object.assign(state.robot, { x: 13, y: 1, direction: 'right', wanted: null, elapsed: 75 })
		const blocked = fakeContext()
		render(blocked.ctx, state, view())
		expect(arcOf(blocked.ctx, CELL * 0.4)[0]).toBeCloseTo(270)
	})

	it('faces the robot its way and rings it while charged, pulsing only with motion', () => {
		const eyeRow = (direction) => {
			const { ctx } = fakeContext()
			const state = createState(() => 0.5)
			state.dust.fill(0)
			state.robot.direction = direction
			render(ctx, state, view())
			return arcOf(ctx, CELL * 0.22 * 0.42)[1]
		}
		expect(eyeRow('down')).toBeCloseTo(32)
		expect(eyeRow('up')).toBeCloseTo(28)

		const state = createState(() => 0.5)
		state.dust.fill(0)
		state.chargeMs = 3000
		const charged = fakeContext()
		render(charged.ctx, state, view())
		expect(charged.strokes).toContain('yellow')
		expect(arcOf(charged.ctx, CELL * 0.5)).toBeTruthy()
		expect(charged.ctx.globalAlpha).toBe(1)

		const pulsing = fakeContext()
		render(pulsing.ctx, state, view({ motion: true, time: 125 }))
		expect(pulsing.ctx.globalAlpha).toBe(1)
	})

	it('fills the dock bolt while ready and glows only with motion; only outlines it while recharging', () => {
		const state = createState(() => 0.5)
		state.dust.fill(0)
		const ready = fakeContext()
		render(ready.ctx, state, view())
		expect(count(ready.fills, 'green')).toBe(1)
		expect(count(ready.fills, 'yellow')).toBe(1)
		expect(ready.ctx.closePath).toHaveBeenCalledTimes(1)

		const glowing = fakeContext()
		render(glowing.ctx, state, view({ motion: true, time: 300 }))
		expect(glowing.ctx.arc).toHaveBeenCalledTimes(ready.ctx.arc.mock.calls.length + 1)
		expect(glowing.ctx.globalAlpha).toBe(1)

		state.dockMs = 5000
		const recharging = fakeContext()
		render(recharging.ctx, state, view({ motion: true, time: 300 }))
		expect(count(recharging.fills, 'yellow')).toBe(0)
		expect(recharging.strokes.length).toBe(ready.strokes.length + 1)
	})
})
