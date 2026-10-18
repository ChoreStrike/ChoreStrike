/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { PALETTE_KEYS } from '@/components/Minigames/board.js'

/**
 * A recording 2D context for renderer tests: every fill and stroke notes
 * the style it used, so a spec can assert which palette color drew what.
 *
 * @param {{roundRect?: boolean}} [options] Whether the context offers roundRect
 * @return {{ctx: object, fills: string[], strokes: string[]}}
 */
export function fakeContext({ roundRect = true } = {}) {
	const fills = []
	const strokes = []
	const ctx = {
		fillStyle: '',
		strokeStyle: '',
		lineWidth: 1,
		lineCap: 'butt',
		lineJoin: 'miter',
		globalAlpha: 1,
		font: '',
		textAlign: '',
		textBaseline: '',
		fillRect: jest.fn(() => fills.push(ctx.fillStyle)),
		beginPath: jest.fn(),
		closePath: jest.fn(),
		rect: jest.fn(),
		arc: jest.fn(),
		fill: jest.fn(() => fills.push(ctx.fillStyle)),
		stroke: jest.fn(() => strokes.push(ctx.strokeStyle)),
		moveTo: jest.fn(),
		lineTo: jest.fn(),
		fillText: jest.fn(),
		strokeText: jest.fn(),
		setTransform: jest.fn(),
	}
	if (roundRect) ctx.roundRect = jest.fn()
	return { ctx, fills, strokes }
}

/**
 * A palette whose every color is its own name, so fills read as names.
 *
 * @return {object} Palette
 */
export function namedPalette() {
	return Object.fromEntries(PALETTE_KEYS.map((key) => [key, key]))
}

/**
 * @param {Array} list Recorded values
 * @param {*} value Value to count
 * @return {number} How often the value occurs
 */
export function count(list, value) {
	return list.filter((item) => item === value).length
}
