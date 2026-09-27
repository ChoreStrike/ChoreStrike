/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import {
	ENERGY_MINOR_PER_UNIT,
	COIN_MINOR_PER_ENERGY,
	formatEnergyMinor,
	formatCoinMinor,
	formatMinor,
} from '../../../src/utils/minorUnits.js'

describe('minorUnits util (WP3 §3.6 D8/C3b)', () => {
	describe('unit scales', () => {
		it('fixes 100 energy minor per energy and 1000 coin minor per Coin', () => {
			expect(ENERGY_MINOR_PER_UNIT).toBe(100)
			expect(COIN_MINOR_PER_ENERGY).toBe(1000)
		})
	})

	describe('formatEnergyMinor', () => {
		it('formats whole energy without fraction digits', () => {
			expect(formatEnergyMinor(400)).toBe('4')
			expect(formatEnergyMinor(100)).toBe('1')
		})

		it('formats sub-unit energy with trimmed fraction digits', () => {
			expect(formatEnergyMinor(450)).toBe('4.5')
			expect(formatEnergyMinor(434)).toBe('4.34')
		})

		it('formats the zero value exactly', () => {
			expect(formatEnergyMinor(0)).toBe('0')
		})

		it('formats negative amounts with a leading minus (correction results)', () => {
			expect(formatEnergyMinor(-250)).toBe('-2.5')
			expect(formatEnergyMinor(-100)).toBe('-1')
		})

		it('keeps signed safe-integer boundaries exact', () => {
			expect(formatEnergyMinor(Number.MAX_SAFE_INTEGER)).toBe('90071992547409.91')
			expect(formatEnergyMinor(-Number.MAX_SAFE_INTEGER)).toBe('-90071992547409.91')
		})
	})

	describe('formatCoinMinor', () => {
		it('formats whole Coins without fraction digits', () => {
			expect(formatCoinMinor(2500)).toBe('25')
			expect(formatCoinMinor(1000)).toBe('10')
		})

		it('formats sub-Coin amounts with trimmed fraction digits', () => {
			expect(formatCoinMinor(1250)).toBe('12.5')
			expect(formatCoinMinor(1050)).toBe('10.5')
		})

		it('formats negative sub-Coin corrections', () => {
			expect(formatCoinMinor(-1500)).toBe('-15')
			expect(formatCoinMinor(-330)).toBe('-3.3')
		})

		it('keeps signed safe-integer boundaries exact', () => {
			expect(formatCoinMinor(Number.MAX_SAFE_INTEGER)).toBe('90071992547409.91')
			expect(formatCoinMinor(-Number.MAX_SAFE_INTEGER)).toBe('-90071992547409.91')
		})
	})

	describe('formatMinor (generic 100-scale)', () => {
		it('mirrors the energy scale for generic minor values', () => {
			expect(formatMinor(100)).toBe('1')
			expect(formatMinor(50)).toBe('0.5')
			expect(formatMinor(-34)).toBe('-0.34')
		})
	})

	describe('robustness', () => {
		it('renders non-finite input as 0 instead of NaN/Infinity', () => {
			expect(formatCoinMinor(Number.NaN)).toBe('0')
			expect(formatCoinMinor(Number.POSITIVE_INFINITY)).toBe('0')
			expect(formatEnergyMinor(Number.NEGATIVE_INFINITY)).toBe('0')
		})

		it('truncates fractional input instead of rounding stored values', () => {
			expect(formatCoinMinor(1250.7)).toBe('12.5')
		})
	})
})
