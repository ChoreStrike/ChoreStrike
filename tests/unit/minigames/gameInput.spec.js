/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { createSteerKeys, isPaddleControl } from '@/components/Minigames/gameInput.js'

/**
 * CS-PLAN-086 WP-E: the `paddle` input mode reports held left/right keys as
 * steer changes, and only the latest paddle control waits in the queue.
 */
describe('paddle input helpers', () => {
	it('answers a steer value only when it changes, never for key repeats', () => {
		const keys = createSteerKeys()

		expect(keys.press('ArrowLeft', 'left')).toBe(-1)
		expect(keys.press('ArrowLeft', 'left')).toBeNull()
		expect(keys.press('ArrowRight', 'right')).toBe(0)
		expect(keys.release('ArrowLeft')).toBe(1)
		expect(keys.release('ArrowLeft')).toBeNull()
		expect(keys.release('ArrowRight')).toBe(0)
	})

	it('keeps steering while another key for the same side is still held (R086E-01)', () => {
		const keys = createSteerKeys()

		expect(keys.press('ArrowLeft', 'left')).toBe(-1)
		expect(keys.press('KeyA', 'left')).toBeNull()
		expect(keys.release('KeyA')).toBeNull()
		expect(keys.release('ArrowLeft')).toBe(0)
	})

	it('ignores up and down, and forgets every held key on clear', () => {
		const keys = createSteerKeys()

		expect(keys.press('ArrowUp', 'up')).toBeNull()
		expect(keys.press('KeyS', 'down')).toBeNull()
		keys.press('ArrowRight', 'right')
		keys.clear()

		expect(keys.release('ArrowRight')).toBeNull()
		expect(keys.press('ArrowRight', 'right')).toBe(1)
	})

	it('recognizes steer and aim inputs as paddle controls only', () => {
		expect(isPaddleControl({ steer: 0 })).toBe(true)
		expect(isPaddleControl({ aim: 0.5 })).toBe(true)
		for (const input of ['act', 'left', { cell: 3 }, null, undefined]) {
			expect(isPaddleControl(input)).toBe(false)
		}
	})
})
