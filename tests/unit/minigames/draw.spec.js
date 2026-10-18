/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { disc, drawEyes, lerp, pulse, roundedRect, star } from '@/components/Minigames/draw.js'
import { fakeContext, namedPalette } from '../helpers/canvasTestHelpers.js'

/**
 * CS-PLAN-086 WP-G: the canvas primitives every renderer shares.
 */
describe('canvas primitives', () => {
	it('fills a rounded rectangle, or a plain one without roundRect support, and leaves the path for a stroke', () => {
		const rounded = fakeContext()
		rounded.ctx.fillStyle = 'wood'
		roundedRect(rounded.ctx, 1, 2, 30, 40, 5)
		expect(rounded.ctx.roundRect).toHaveBeenCalledWith(1, 2, 30, 40, 5)
		expect(rounded.fills).toEqual(['wood'])

		const plain = fakeContext({ roundRect: false })
		roundedRect(plain.ctx, 1, 2, 30, 40, 5)
		expect(plain.ctx.rect).toHaveBeenCalledWith(1, 2, 30, 40)
		expect(plain.ctx.beginPath).toHaveBeenCalledTimes(1)
	})

	it('fills a disc and a four-pointed star', () => {
		const { ctx, fills } = fakeContext()
		ctx.fillStyle = 'yellow'
		disc(ctx, 10, 20, 4)
		star(ctx, 10, 20, 6)

		expect(ctx.arc).toHaveBeenCalledWith(10, 20, 4, 0, Math.PI * 2)
		expect(ctx.moveTo).toHaveBeenCalledWith(10, 14)
		expect(ctx.lineTo).toHaveBeenCalledTimes(7)
		expect(ctx.closePath).toHaveBeenCalledTimes(1)
		expect(fills).toEqual(['yellow', 'yellow'])
	})

	it('draws two eyes whose pupils follow the gaze, or two closed lines', () => {
		const open = fakeContext()
		drawEyes(open.ctx, 100, 50, 10, 1, 0, namedPalette())
		expect(open.fills).toEqual(['paper', 'ink', 'paper', 'ink'])
		const [leftEye, leftPupil] = open.ctx.arc.mock.calls
		expect(leftEye.slice(0, 2)).toEqual([94.5, 50])
		expect(leftPupil[0]).toBeCloseTo(94.5 + 1.4)
		expect(leftPupil[1]).toBe(50)

		const closed = fakeContext()
		drawEyes(closed.ctx, 100, 50, 10, 0, 0, namedPalette(), true)
		expect(closed.fills).toEqual([])
		expect(closed.ctx.moveTo).toHaveBeenCalledTimes(2)
		expect(closed.strokes).toEqual(['ink', 'ink'])
	})

	it('interpolates and pulses', () => {
		expect(lerp(2, 6, 0.25)).toBe(3)
		expect(pulse(0, 900)).toBeCloseTo(0.5)
		expect(pulse(225, 900)).toBeCloseTo(1)
		expect(pulse(675, 900)).toBeCloseTo(0)
	})
})
