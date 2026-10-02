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
	parseCoinMinor,
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

	describe('parseCoinMinor (WP8 §3.3 exact price entry)', () => {
		it('parses whole prices to integer hundredths', () => {
			expect(parseCoinMinor('12')).toBe(1200)
			expect(parseCoinMinor('1')).toBe(100)
			expect(parseCoinMinor('  7 ')).toBe(700)
		})

		it('parses dot and comma decimals with at most two fraction digits', () => {
			expect(parseCoinMinor('12.5')).toBe(1250)
			expect(parseCoinMinor('0.99')).toBe(99)
			expect(parseCoinMinor('12,50')).toBe(1250)
			expect(parseCoinMinor('12.05')).toBe(1205)
			expect(parseCoinMinor('0.05')).toBe(5)
		})

		it('rejects zero, negatives and signs', () => {
			expect(() => parseCoinMinor('0')).toThrow()
			expect(() => parseCoinMinor('0.00')).toThrow()
			expect(() => parseCoinMinor('-5')).toThrow()
			expect(() => parseCoinMinor('+5')).toThrow()
		})

		it('rejects mixed or repeated separators and grouping', () => {
			expect(() => parseCoinMinor('1.234,56')).toThrow()
			expect(() => parseCoinMinor('1,234.56')).toThrow()
			expect(() => parseCoinMinor('1.2.3')).toThrow()
			expect(() => parseCoinMinor('1,2,3')).toThrow()
		})

		it('rejects leading/trailing separators, empty and non-string input', () => {
			expect(() => parseCoinMinor(',5')).toThrow()
			expect(() => parseCoinMinor('.5')).toThrow()
			expect(() => parseCoinMinor('12.')).toThrow()
			expect(() => parseCoinMinor('12,')).toThrow()
			expect(() => parseCoinMinor('')).toThrow()
			expect(() => parseCoinMinor('   ')).toThrow()
			expect(() => parseCoinMinor(null)).toThrow()
			expect(() => parseCoinMinor(12.5)).toThrow()
		})

		it('rejects exponents and excess precision', () => {
			expect(() => parseCoinMinor('1e3')).toThrow()
			expect(() => parseCoinMinor('1E2')).toThrow()
			expect(() => parseCoinMinor('1.234')).toThrow()
			expect(() => parseCoinMinor('1.005')).toThrow()
		})

		it('rejects letters and unsafe magnitudes', () => {
			expect(() => parseCoinMinor('abc')).toThrow()
			expect(() => parseCoinMinor('12a')).toThrow()
			expect(() => parseCoinMinor('99999999999999999999')).toThrow()
			expect(() => parseCoinMinor('90071992547409.92')).toThrow()
		})

		it('rejects leading zeros', () => {
			expect(() => parseCoinMinor('007')).toThrow()
			expect(() => parseCoinMinor('00.5')).toThrow()
		})

		it('never returns a float: results are exact safe integers', () => {
			const minor = parseCoinMinor('123.45')
			expect(minor).toBe(12345)
			expect(Number.isInteger(minor)).toBe(true)
			expect(Number.isSafeInteger(minor)).toBe(true)
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
