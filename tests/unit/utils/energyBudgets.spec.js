/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import {
	WEEKDAY_KEYS,
	DEFAULT_ENERGY_BUDGET,
	createDefaultEnergyBudget,
	resolveEnergyForDay,
} from '../../../src/utils/energyBudgets.js'

describe('energyBudgets util (TECH-DRY-001)', () => {
	describe('WEEKDAY_KEYS', () => {
		it('lists the seven canonical weekday keys Monday-first', () => {
			expect(WEEKDAY_KEYS).toEqual([
				'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
			])
		})
	})

	describe('DEFAULT_ENERGY_BUDGET', () => {
		it('preserves the backend fallback: mon-fri 10, sat/sun 15', () => {
			expect(DEFAULT_ENERGY_BUDGET).toEqual({
				monday: 10,
				tuesday: 10,
				wednesday: 10,
				thursday: 10,
				friday: 10,
				saturday: 15,
				sunday: 15,
			})
		})
	})

	describe('createDefaultEnergyBudget', () => {
		it('returns a fresh mutable copy each call', () => {
			const first = createDefaultEnergyBudget()
			const second = createDefaultEnergyBudget()
			expect(first).toEqual(DEFAULT_ENERGY_BUDGET)
			expect(first).not.toBe(second)

			first.monday = 1
			expect(second.monday).toBe(10)
		})
	})

	describe('resolveEnergyForDay', () => {
		it('prefers the stored value over the fallback', () => {
			expect(resolveEnergyForDay({ monday: 6 }, 'monday')).toBe(6)
		})

		it('falls back to the weekend defaults for missing days', () => {
			expect(resolveEnergyForDay({}, 'saturday')).toBe(15)
			expect(resolveEnergyForDay(undefined, 'wednesday')).toBe(10)
		})

		it('falls back to 10 for unknown day keys (historical flat default)', () => {
			expect(resolveEnergyForDay(undefined, 'funday')).toBe(10)
		})
	})
})
