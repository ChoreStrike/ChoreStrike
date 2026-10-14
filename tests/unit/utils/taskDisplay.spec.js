/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import {
	compareTaskItems,
	isOverdue,
	priorityClass,
	priorityLabel,
	frequencyLabel,
	dueClass,
	dueText,
	flexWindowKeys,
} from '../../../src/utils/taskDisplay.js'

describe('taskDisplay utils (CS-PLAN-067 §3.4)', () => {
	describe('isOverdue (CS-PLAN-084 D-084-08)', () => {
		it('is false for inactive tasks regardless of the date', () => {
			expect(isOverdue({ isActive: false, nextDue: '2000-01-01T00:00:00Z', occurrence: { classification: 'late' } })).toBe(false)
		})

		it('follows the server occurrence classification: only a missed (late) occurrence is overdue', () => {
			expect(isOverdue({ isActive: true, nextDue: '2000-01-01T00:00:00Z', occurrence: { classification: 'late' } })).toBe(true)
			// A past planned day inside an open flex window is still due.
			expect(isOverdue({ isActive: true, nextDue: '2000-01-01T00:00:00Z', occurrence: { classification: 'agreed' } })).toBe(false)
		})

		it('never marks work due today overdue although its midnight instant has passed', () => {
			const todayMidnightUtc = new Date()
			todayMidnightUtc.setUTCHours(0, 0, 0, 0)
			expect(isOverdue({ isActive: true, nextDue: todayMidnightUtc.toISOString(), occurrence: { classification: 'agreed' } })).toBe(false)
		})

		it('falls back to the server-computed status without an occurrence block', () => {
			expect(isOverdue({ isActive: true, nextDue: '2000-01-01T00:00:00Z', status: 'overdue' })).toBe(true)
			expect(isOverdue({ isActive: true, nextDue: '2000-01-01T00:00:00Z', status: 'due_today' })).toBe(false)
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

		it('marks a missed occurrence overdue', () => {
			expect(dueClass({ isActive: true, nextDue: '2000-01-01T00:00:00Z', occurrence: { classification: 'late' } }, tz)).toBe('overdue')
		})

		it('keeps a past planned day inside an open flex window due today, not overdue', () => {
			expect(dueClass({ isActive: true, nextDue: '2000-01-01T00:00:00Z', occurrence: { classification: 'agreed' } }, tz)).toBe('today')
		})

		it('treats a missing due date as upcoming', () => {
			expect(dueClass({ isActive: true, nextDue: null, status: 'upcoming' }, tz)).toBe('upcoming')
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
		const tz = 'UTC'
		const dayStart = (offset) => {
			const date = new Date()
			date.setUTCHours(0, 0, 0, 0)
			date.setUTCDate(date.getUTCDate() + offset)
			return date.toISOString()
		}

		it('labels completed tasks', () => {
			expect(dueText({ isActive: false, nextDue: '2000-01-01T00:00:00Z' }, tz)).toBe('Completed')
		})

		it('counts overdue house-local days of a missed occurrence', () => {
			expect(dueText({ isActive: true, nextDue: dayStart(-2), occurrence: { classification: 'late' } }, tz)).toBe('Overdue by 2 days')
		})

		it('labels today (also after its midnight instant), tomorrow and the future in house-local days', () => {
			expect(dueText({ isActive: true, nextDue: dayStart(0), occurrence: { classification: 'agreed' } }, tz)).toBe('Due today')
			expect(dueText({ isActive: true, nextDue: dayStart(1) }, tz)).toBe('Due tomorrow')
			expect(dueText({ isActive: true, nextDue: dayStart(5) }, tz)).toBe('Due in 5 days')
		})

		it('keeps an open flex window due today and labels a missing due date', () => {
			expect(dueText({ isActive: true, nextDue: dayStart(-1), occurrence: { classification: 'agreed' } }, tz)).toBe('Due today')
			expect(dueText({ isActive: true, nextDue: null }, tz)).toBe('No due date')
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

	describe('compareTaskItems (CS-PLAN-084 WP-D)', () => {
		const row = (id, nextDue, priority = 2) => ({ roomTask: { id, nextDue }, task: { priority } })

		it('orders by due date, then higher priority, with undated rows last', () => {
			const rows = [
				row(1, null),
				row(2, '2030-05-11T00:00:00Z'),
				row(3, '2030-05-10T00:00:00Z', 1),
				row(4, '2030-05-10T00:00:00Z', 3),
				row(5, undefined, 3),
			]
			expect([...rows].sort(compareTaskItems).map(item => item.roomTask.id)).toEqual([4, 3, 2, 5, 1])
		})
	})
})
