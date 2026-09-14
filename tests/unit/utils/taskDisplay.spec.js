/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import {
	isOverdue,
	priorityClass,
	priorityLabel,
	frequencyLabel,
	dueClass,
	dueText,
	flexWindowKeys,
} from '../../../src/utils/taskDisplay.js'

describe('taskDisplay utils (CS-PLAN-067 §3.4)', () => {
	describe('isOverdue', () => {
		it('is false for inactive tasks regardless of the date', () => {
			expect(isOverdue({ isActive: false, nextDue: '2000-01-01T00:00:00Z' })).toBe(false)
		})

		it('is true for an active task whose nextDue is in the past', () => {
			expect(isOverdue({ isActive: true, nextDue: '2000-01-01T00:00:00Z' })).toBe(true)
		})

		it('is false for an active task due in the future', () => {
			expect(isOverdue({ isActive: true, nextDue: '2999-01-01T00:00:00Z' })).toBe(false)
		})
	})

	describe('priorityClass / priorityLabel', () => {
		it('maps 1..3 to low/medium/high and defaults to medium', () => {
			expect(priorityClass(1)).toBe('low')
			expect(priorityClass(2)).toBe('medium')
			expect(priorityClass(3)).toBe('high')
			expect(priorityClass(99)).toBe('medium')
		})

		it('labels the three stored priorities', () => {
			expect(priorityLabel(1)).toBe('Low')
			expect(priorityLabel(2)).toBe('Normal')
			expect(priorityLabel(3)).toBe('High')
		})
	})

	describe('frequencyLabel', () => {
		it('labels singular weekly/monthly without a count', () => {
			expect(frequencyLabel({ frequencyType: 'daily' })).toBe('Daily')
			expect(frequencyLabel({ frequencyType: 'weekly', frequencyValue: 1 })).toBe('Weekly')
			expect(frequencyLabel({ frequencyType: 'monthly', frequencyValue: 1 })).toBe('Monthly')
			expect(frequencyLabel({ frequencyType: 'custom' })).toBe('Custom')
		})

		it('carries the interval count for n > 1', () => {
			expect(frequencyLabel({ frequencyType: 'weekly', frequencyValue: 2 })).toBe('Every 2 weeks')
			expect(frequencyLabel({ frequencyType: 'monthly', frequencyValue: 3 })).toBe('Every 3 months')
		})

		it('falls back to the raw type for unknown types', () => {
			expect(frequencyLabel({ frequencyType: 'yearly', frequencyValue: 1 })).toBe('yearly')
		})
	})

	describe('dueClass', () => {
		const tz = 'UTC'

		it('marks inactive tasks completed', () => {
			expect(dueClass({ isActive: false, nextDue: '2000-01-01T00:00:00Z' }, tz)).toBe('completed')
		})

		it('marks a past active due date overdue', () => {
			expect(dueClass({ isActive: true, nextDue: '2000-01-01T00:00:00Z' }, tz)).toBe('overdue')
		})

		it('buckets the current house-local day as today', () => {
			const now = new Date()
			expect(dueClass({ isActive: true, nextDue: now.toISOString() }, tz)).toBe('today')
		})

		it('stays today for a same-day due date whose 00:00 timestamp already passed (CS-PLAN-068 date-boundary regression)', () => {
			// The UTC-midnight instant of TODAY is in the past for most of the
			// day, but the house-local day bucket still wins.
			const todayMidnightUtc = new Date()
			todayMidnightUtc.setUTCHours(0, 0, 0, 0)
			expect(dueClass({ isActive: true, nextDue: todayMidnightUtc.toISOString() }, tz)).toBe('today')
		})

		it('marks future days upcoming', () => {
			expect(dueClass({ isActive: true, nextDue: '2999-01-01T00:00:00Z' }, tz)).toBe('upcoming')
		})
	})

	describe('dueText', () => {
		it('labels completed tasks', () => {
			expect(dueText({ isActive: false, nextDue: '2000-01-01T00:00:00Z' })).toBe('Completed')
		})

		it('counts overdue days', () => {
			// Slightly MORE than two days in the past → ceil(-2.0007) = -2.
			const twoDaysAgo = new Date(Date.now() - 2 * 86400000 - 60000).toISOString()
			expect(dueText({ isActive: true, nextDue: twoDaysAgo })).toBe('Overdue by 2 days')
		})

		it('labels today, tomorrow and the future', () => {
			expect(dueText({ isActive: true, nextDue: new Date().toISOString() })).toBe('Due today')
			expect(dueText({ isActive: true, nextDue: new Date(Date.now() + 86400000).toISOString() })).toBe('Due tomorrow')
			expect(dueText({ isActive: true, nextDue: new Date(Date.now() + 5 * 86400000).toISOString() })).toBe('Due in 5 days')
		})
	})

	describe('flexWindowKeys', () => {
		it('falls back to nextDue as the ideal anchor', () => {
			const item = {
				roomTask: { nextDue: '2030-05-10T00:00:00Z', idealDueDate: null },
				task: { flexDays: 2 },
			}
			const win = flexWindowKeys(item)
			expect(win.ideal).toBe('2030-05-10T00:00:00Z')
		})

		it('computes earliest/latest symmetrically around the ideal day', () => {
			const item = {
				roomTask: { nextDue: '2030-05-10T00:00:00Z', idealDueDate: '2030-05-12T00:00:00Z' },
				task: { flexDays: 2 },
			}
			const win = flexWindowKeys(item)
			expect(win.ideal).toBe('2030-05-12T00:00:00Z')
			expect(new Date(win.earliest).toISOString()).toBe('2030-05-10T00:00:00.000Z')
			expect(new Date(win.latest).toISOString()).toBe('2030-05-14T00:00:00.000Z')
		})

		it('returns null bounds for non-flex tasks', () => {
			const item = {
				roomTask: { nextDue: '2030-05-10T00:00:00Z', idealDueDate: null },
				task: { flexDays: 0 },
			}
			const win = flexWindowKeys(item)
			expect(win.earliest).toBeNull()
			expect(win.latest).toBeNull()
			expect(win.ideal).toBe('2030-05-10T00:00:00Z')
		})
	})
})
