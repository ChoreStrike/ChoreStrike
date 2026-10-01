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
import {
	getCelebrationPalette,
	fireConfetti,
	fireMilestoneConfetti,
	areCelebrationsEnabled,
	setAnimationsEnabled,
} from '../../../src/utils/confetti.js'

/**
 * Calm feedback contract (CS-PLAN-070 WP6 §3.2, C5g): animations are a
 * personal opt-in (default off); `prefers-reduced-motion` is the hard
 * floor and ALWAYS overrides even an explicit opt-in.
 */
describe('confetti utilities', () => {
	let originalMatchMedia

	beforeEach(() => {
		confetti.mockReset()
		setAnimationsEnabled(false)
	})

	afterEach(() => {
		if (originalMatchMedia !== undefined) {
			window.matchMedia = originalMatchMedia
			originalMatchMedia = undefined
		}
	})

	const stubReducedMotion = (matches) => {
		originalMatchMedia = window.matchMedia
		window.matchMedia = (query) => ({
			matches,
			media: query,
			onchange: null,
			addListener: () => {},
			removeListener: () => {},
			addEventListener: () => {},
			removeEventListener: () => {},
			dispatchEvent: () => false,
		})
	}

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
		it('stays silent without the personal opt-in (C5g default)', () => {
			fireConfetti({ particleCount: 42 })
			expect(confetti).not.toHaveBeenCalled()
		})

		it('forwards options and the celebration palette after the explicit opt-in', () => {
			setAnimationsEnabled(true)
			fireConfetti({ particleCount: 42 })
			expect(confetti).toHaveBeenCalledTimes(1)
			const call = confetti.mock.calls[0][0]
			expect(call.particleCount).toBe(42)
			expect(call.colors.length).toBeGreaterThan(0)
			expect(call.disableForReducedMotion).toBe(true)
		})

		it('reduced motion overrides an explicit opt-in (hard floor)', () => {
			stubReducedMotion(true)
			setAnimationsEnabled(true)
			fireConfetti()
			expect(confetti).not.toHaveBeenCalled()
			expect(areCelebrationsEnabled()).toBe(false)
		})

		it('does not throw when the canvas is unavailable', () => {
			setAnimationsEnabled(true)
			confetti.mockImplementationOnce(() => {
				throw new Error('no canvas')
			})
			expect(() => fireConfetti()).not.toThrow()
		})
	})

	describe('fireMilestoneConfetti', () => {
		it('fires from both sides of the viewport after the explicit opt-in', () => {
			setAnimationsEnabled(true)
			fireMilestoneConfetti({ particleCount: 30 })
			expect(confetti).toHaveBeenCalledTimes(2)
			const [left, right] = confetti.mock.calls.map((c) => c[0])
			expect(left.angle).toBe(60)
			expect(right.angle).toBe(120)
			expect(left.origin.x).toBe(0)
			expect(right.origin.x).toBe(1)
		})

		it('reduced motion overrides an explicit opt-in (hard floor)', () => {
			stubReducedMotion(true)
			setAnimationsEnabled(true)
			fireMilestoneConfetti()
			expect(confetti).not.toHaveBeenCalled()
		})
	})

	describe('areCelebrationsEnabled', () => {
		it('is false by default and reflects the opt-in', () => {
			expect(areCelebrationsEnabled()).toBe(false)
			setAnimationsEnabled(true)
			expect(areCelebrationsEnabled()).toBe(true)
		})
	})
})
