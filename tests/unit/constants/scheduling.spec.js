/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { weekDays, ENERGY_MIN, ENERGY_MAX, isMemberAvailable, getMemberEnergy } from '../../../src/constants/scheduling.js'

describe('scheduling constants (CR-016-04)', () => {
	describe('ENERGY bounds', () => {
		it('match the backend-enforced range', () => {
			expect(ENERGY_MIN).toBe(1)
			expect(ENERGY_MAX).toBe(20)
		})
	})

	describe('weekDays', () => {
		it('has seven translated entries with key, name and short label', () => {
			expect(weekDays).toHaveLength(7)
			for (const day of weekDays) {
				expect(day.key).toBeTruthy()
				expect(day.name).toBeTruthy()
				expect(day.short).toBeTruthy()
			}
		})

		it('starts on Monday and ends on Sunday (UI order)', () => {
			expect(weekDays[0].key).toBe('monday')
			expect(weekDays[6].key).toBe('sunday')
		})
	})

	describe('isMemberAvailable', () => {
		it('defaults to available when data is missing', () => {
			expect(isMemberAvailable({}, 'monday')).toBe(true)
			expect(isMemberAvailable({ weekdayAvailability: {} }, 'monday')).toBe(true)
		})

		it('respects explicit false flags only', () => {
			const member = { weekdayAvailability: { monday: false, tuesday: true } }
			expect(isMemberAvailable(member, 'monday')).toBe(false)
			expect(isMemberAvailable(member, 'tuesday')).toBe(true)
			expect(isMemberAvailable(member, 'wednesday')).toBe(true)
		})
	})

	describe('getMemberEnergy', () => {
		it('returns the member value when present', () => {
			expect(getMemberEnergy({ energyBudget: { monday: 8 } }, 'monday')).toBe(8)
		})

		it('falls back to the canonical weekend defaults for missing days', () => {
			expect(getMemberEnergy({ energyBudget: {} }, 'saturday')).toBe(15)
			expect(getMemberEnergy({ energyBudget: {} }, 'sunday')).toBe(15)
			expect(getMemberEnergy({}, 'friday')).toBe(10)
		})
	})
})
