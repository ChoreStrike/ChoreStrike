/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach, jest } from '@jest/globals'

jest.mock('canvas-confetti', () => jest.fn())

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

import confetti from 'canvas-confetti'
import { getCelebrationPalette, fireConfetti, fireMilestoneConfetti, areCelebrationsEnabled } from '../../../src/utils/confetti.js'

describe('confetti utilities', () => {
	beforeEach(() => {
		confetti.mockReset()
	})

	describe('getCelebrationPalette', () => {
		it('returns a non-empty palette even when CSS variables are missing', () => {
			const palette = getCelebrationPalette()
			expect(palette.length).toBeGreaterThan(0)
			palette.forEach((color) => {
				expect(typeof color).toBe('string')
				expect(color.length).toBeGreaterThan(0)
			})
		})
	})

	describe('fireConfetti', () => {
		it('forwards options and the celebration palette to canvas-confetti', () => {
			fireConfetti({ particleCount: 42 })
			expect(confetti).toHaveBeenCalledTimes(1)
			const call = confetti.mock.calls[0][0]
			expect(call.particleCount).toBe(42)
			expect(call.colors.length).toBeGreaterThan(0)
			expect(call.disableForReducedMotion).toBe(true)
		})

		it('does not throw when the canvas is unavailable', () => {
			confetti.mockImplementationOnce(() => {
				throw new Error('no canvas')
			})
			expect(() => fireConfetti()).not.toThrow()
		})
	})

	describe('fireMilestoneConfetti', () => {
		it('fires from both sides of the viewport', () => {
			fireMilestoneConfetti({ particleCount: 30 })
			expect(confetti).toHaveBeenCalledTimes(2)
			const [left, right] = confetti.mock.calls.map((c) => c[0])
			expect(left.angle).toBe(60)
			expect(right.angle).toBe(120)
			expect(left.origin.x).toBe(0)
			expect(right.origin.x).toBe(1)
		})
	})

	describe('areCelebrationsEnabled', () => {
		it('returns a boolean', () => {
			expect(typeof areCelebrationsEnabled()).toBe('boolean')
		})
	})
})
