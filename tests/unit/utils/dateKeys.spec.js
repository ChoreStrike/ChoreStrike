/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { addDaysToKey, dateKeyInZone, daysBetweenKeys, formatDayKey, formatLongDayKey, formatPastDayKey, todayKeyInZone, weekdayOfKey } from '../../../src/utils/dateKeys.js'

describe('dateKeys (CS-PLAN-057 house-timezone day keys)', () => {
	describe('dateKeyInZone', () => {
		it('buckets an instant in UTC', () => {
			expect(dateKeyInZone('2026-08-30T12:00:00Z', 'UTC')).toBe('2026-08-30')
		})

		it('crosses into the next day for positive offsets (Berlin midnight crossing)', () => {
			// 22:30 UTC = 00:30 CEST next day — the completion-path integration pin.
			expect(dateKeyInZone('2026-07-14T22:30:00Z', 'Europe/Berlin')).toBe('2026-07-15')
			// Same instant in UTC is still the 14th.
			expect(dateKeyInZone('2026-07-14T22:30:00Z', 'UTC')).toBe('2026-07-14')
		})

		it('crosses the date line for Auckland (UTC+12/+13)', () => {
			expect(dateKeyInZone('2026-08-30T12:00:00Z', 'Pacific/Auckland')).toBe('2026-08-31')
			expect(dateKeyInZone('2026-08-30T09:00:00Z', 'Pacific/Auckland')).toBe('2026-08-30')
		})

		it('handles a Date instance and epoch millis input', () => {
			expect(dateKeyInZone(new Date('2026-08-30T00:30:00Z'), 'UTC')).toBe('2026-08-30')
			expect(dateKeyInZone(Date.UTC(2026, 7, 30, 23, 30), 'UTC')).toBe('2026-08-30')
		})

		it('DST fall-back overlap stays on the same local date in Berlin', () => {
			// 2026-10-25 01:30 happens twice in Berlin (CEST then CET) — both are the 25th.
			expect(dateKeyInZone('2026-10-24T23:30:00Z', 'Europe/Berlin')).toBe('2026-10-25')
			expect(dateKeyInZone('2026-10-25T00:30:00Z', 'Europe/Berlin')).toBe('2026-10-25')
		})

		it('returns empty string for missing or unparseable input (epoch guard)', () => {
			expect(dateKeyInZone(null, 'UTC')).toBe('')
			expect(dateKeyInZone(undefined, 'UTC')).toBe('')
			expect(dateKeyInZone('', 'UTC')).toBe('')
			expect(dateKeyInZone('not-a-date', 'UTC')).toBe('')
		})

		it('falls back to UTC for an unknown timezone id', () => {
			expect(dateKeyInZone('2026-08-30T12:00:00Z', 'Mars/Olympus_Mons')).toBe('2026-08-30')
		})

		it('falls back to UTC for a missing timezone argument', () => {
			expect(dateKeyInZone('2026-08-30T12:00:00Z')).toBe('2026-08-30')
		})
	})

	describe('todayKeyInZone', () => {
		it('returns the current day key in the requested zone', () => {
			const key = todayKeyInZone('UTC')
			expect(key).toMatch(/^\d{4}-\d{2}-\d{2}$/)
			// The UTC day key can only differ from a fixed known anchor by the
			// real clock — pin the shape, not the wall clock.
			expect(todayKeyInZone('Europe/Berlin')).toMatch(/^\d{4}-\d{2}-\d{2}$/)
		})
	})

	describe('addDaysToKey', () => {
		it('shifts within a month', () => {
			expect(addDaysToKey('2026-08-10', 7)).toBe('2026-08-17')
			expect(addDaysToKey('2026-08-10', -3)).toBe('2026-08-07')
		})

		it('crosses month and year boundaries', () => {
			expect(addDaysToKey('2026-08-31', 1)).toBe('2026-09-01')
			expect(addDaysToKey('2026-12-31', 1)).toBe('2027-01-01')
			expect(addDaysToKey('2027-01-01', -1)).toBe('2026-12-31')
		})

		it('handles leap years', () => {
			expect(addDaysToKey('2028-02-28', 1)).toBe('2028-02-29')
			expect(addDaysToKey('2028-02-29', 1)).toBe('2028-03-01')
			expect(addDaysToKey('2026-02-28', 1)).toBe('2026-03-01')
		})

		it('returns empty string for invalid keys', () => {
			expect(addDaysToKey('', 1)).toBe('')
			expect(addDaysToKey('nope', 1)).toBe('')
			expect(addDaysToKey(null, 1)).toBe('')
		})
	})

	describe('weekdayOfKey', () => {
		it('resolves the weekday of a key (2026-08-24 is a Monday)', () => {
			expect(weekdayOfKey('2026-08-24')).toBe('monday')
			expect(weekdayOfKey('2026-08-30')).toBe('sunday')
			expect(weekdayOfKey('2026-03-29')).toBe('sunday')
		})

		it('returns null for invalid keys', () => {
			expect(weekdayOfKey('')).toBeNull()
			expect(weekdayOfKey('nope')).toBeNull()
			expect(weekdayOfKey(null)).toBeNull()
		})
	})

	describe('formatDayKey', () => {
		it('labels the civil date of the key, never a shifted instant', () => {
			const expected = new Date(2026, 10, 10).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })
			expect(formatDayKey('2026-11-10')).toBe(expected)
		})

		it('shows a dash without a key', () => {
			expect(formatDayKey('')).toBe('—')
			expect(formatDayKey(null)).toBe('—')
		})
	})

	describe('formatLongDayKey', () => {
		it('labels the civil date of the key in full', () => {
			const expected = new Date(2026, 10, 10).toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' })
			expect(formatLongDayKey('2026-11-10')).toBe(expected)
		})

		it('shows a dash without a key', () => {
			expect(formatLongDayKey(undefined)).toBe('—')
		})
	})

	describe('daysBetweenKeys', () => {
		it('counts whole civil days, also across a month, a leap day and a clock change', () => {
			expect(daysBetweenKeys('2026-11-08', '2026-11-11')).toBe(3)
			expect(daysBetweenKeys('2026-10-30', '2026-11-02')).toBe(3)
			expect(daysBetweenKeys('2028-02-28', '2028-03-01')).toBe(2)
			// The last Sunday of October has 25 hours in Central Europe.
			expect(daysBetweenKeys('2026-10-24', '2026-10-26')).toBe(2)
		})

		it('is zero for the same day and negative for an earlier target', () => {
			expect(daysBetweenKeys('2026-11-11', '2026-11-11')).toBe(0)
			expect(daysBetweenKeys('2026-11-11', '2026-11-09')).toBe(-2)
		})

		it('is null for anything that is not a day key', () => {
			expect(daysBetweenKeys('', '2026-11-11')).toBeNull()
			expect(daysBetweenKeys('2026-11-11', null)).toBeNull()
			expect(daysBetweenKeys('2026-11-11T08:00:00Z', '2026-11-12')).toBeNull()
		})
	})

	// RF-091-09: a history entry from another year names its year.
	describe('formatPastDayKey', () => {
		it('formats a day of this year like formatDayKey and adds the year otherwise', () => {
			expect(formatPastDayKey('2026-11-09', '2026-11-18')).toBe(formatDayKey('2026-11-09'))
			expect(formatPastDayKey('2025-12-30', '2026-01-02')).toContain('2025')
			expect(formatPastDayKey('2025-12-30', '2026-01-02')).not.toBe(formatDayKey('2025-12-30'))
		})

		it('shows the placeholder for no day', () => {
			expect(formatPastDayKey(null, '2026-11-18')).toBe('—')
		})
	})
})
