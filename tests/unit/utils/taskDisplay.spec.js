/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import {
	compareTaskItems,
	daysLabel,
	isOverdue,
	priorityClass,
	priorityLabel,
	frequencyLabel,
	dueClass,
	dueText,
	locationLabel,
	matchesEnergyFilter,
	recurrenceLabel,
	cycleOf,
	plannedDayKey,
	taskEnergy,
} from '../../../src/utils/taskDisplay.js'

describe('taskDisplay utils (CS-PLAN-067 §3.4)', () => {
	describe('cycleOf / plannedDayKey (ADR-021)', () => {
		it('reads the block from the row or from its room task', () => {
			const cycle = { cycleKey: 'c7', state: 'open' }
			expect(cycleOf({ roomTask: { id: 1 }, cycle })).toBe(cycle)
			expect(cycleOf({ roomTask: { id: 1, cycle } })).toBe(cycle)
			expect(cycleOf({ id: 1, cycle })).toBe(cycle)
			expect(cycleOf({ roomTask: { id: 1 } })).toBeUndefined()
			expect(cycleOf({ roomTask: { id: 1 }, cycle: null })).toBeNull()
		})

		it('plans a task on the planned day of its cycle, not on the projected end of its window', () => {
			const roomTask = { nextDue: '2030-05-13T00:00:00Z', cycle: { centreOn: '2030-05-10', windowEndOn: '2030-05-13' } }
			expect(plannedDayKey(roomTask, 'UTC')).toBe('2030-05-10')
			expect(plannedDayKey({ roomTask }, 'UTC')).toBe('2030-05-10')
		})

		it('plans a task without a window on no day', () => {
			expect(plannedDayKey({ nextDue: '2030-05-13T00:00:00Z', cycle: { state: 'bonus', expectedVersion: 2 } }, 'UTC')).toBe('')
			expect(plannedDayKey({ nextDue: '2030-05-13T00:00:00Z', cycle: null }, 'UTC')).toBe('')
		})

		it('falls back to the projected instant for a row delivered without a block', () => {
			expect(plannedDayKey({ nextDue: '2030-05-13T00:00:00Z' }, 'UTC')).toBe('2030-05-13')
		})
	})

	describe('isOverdue (CS-PLAN-084 D-084-08)', () => {
		it('is false for inactive tasks regardless of the cycle', () => {
			expect(isOverdue({ isActive: false, cycle: { state: 'overdue' } })).toBe(false)
		})

		it('follows the server cycle state: only a window that ended is overdue', () => {
			expect(isOverdue({ isActive: true, cycle: { state: 'overdue' } })).toBe(true)
			// A past planned day inside an open flex window is still due.
			expect(isOverdue({ isActive: true, cycle: { state: 'open', centreOn: '2000-01-01' } })).toBe(false)
			expect(isOverdue({ isActive: true, cycle: { state: 'upcoming' } })).toBe(false)
			expect(isOverdue({ isActive: true, cycle: { state: 'bonus' } })).toBe(false)
		})

		it('falls back to the server-computed status without a cycle block', () => {
			expect(isOverdue({ isActive: true, nextDue: '2000-01-01T00:00:00Z', status: 'overdue' })).toBe(true)
			expect(isOverdue({ isActive: true, nextDue: '2000-01-01T00:00:00Z', status: 'due_today' })).toBe(false)
			expect(isOverdue({ isActive: true, status: 'overdue', cycle: null })).toBe(true)
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
			expect(frequencyLabel({ frequencyType: 'daily', frequencyValue: 3 })).toBe('Every 3 days')
			expect(frequencyLabel({ frequencyType: 'daily', frequencyValue: 1 })).toBe('Daily')
			expect(frequencyLabel({ frequencyType: 'weekly', frequencyValue: 2 })).toBe('Every 2 weeks')
			expect(frequencyLabel({ frequencyType: 'monthly', frequencyValue: 3 })).toBe('Every 3 months')
		})

		it('falls back to the raw type for unknown types', () => {
			expect(frequencyLabel({ frequencyType: 'yearly', frequencyValue: 1 })).toBe('yearly')
		})
	})

	const dayKey = (offset) => {
		const date = new Date()
		date.setUTCHours(0, 0, 0, 0)
		date.setUTCDate(date.getUTCDate() + offset)
		return date.toISOString().slice(0, 10)
	}
	/** A row as the lists deliver it: the cycle block and the projected end of its window. */
	const withCycle = (state, centre, end = centre) => ({
		isActive: true,
		nextDue: `${dayKey(end)}T00:00:00Z`,
		cycle: { cycleKey: 'c1', expectedVersion: 0, state, centreOn: dayKey(centre), windowStartOn: dayKey(centre), windowEndOn: dayKey(end) },
	})

	describe('dueClass', () => {
		const tz = 'UTC'

		it('marks inactive tasks completed', () => {
			expect(dueClass({ isActive: false, nextDue: '2000-01-01T00:00:00Z' }, tz)).toBe('completed')
		})

		it('marks a cycle whose window ended overdue', () => {
			expect(dueClass(withCycle('overdue', -3), tz)).toBe('overdue')
		})

		it('keeps a past planned day inside an open flex window due today, not overdue', () => {
			expect(dueClass(withCycle('open', -1, 1), tz)).toBe('today')
		})

		it('buckets a cycle planned for the current house-local day as today', () => {
			expect(dueClass(withCycle('open', 0), tz)).toBe('today')
		})

		it('marks future planned days upcoming, also inside a window that already opened', () => {
			expect(dueClass(withCycle('upcoming', 3), tz)).toBe('upcoming')
			expect(dueClass(withCycle('open', 2, 4), tz)).toBe('upcoming')
		})

		it('treats a task without a window as upcoming', () => {
			expect(dueClass({ isActive: true, nextDue: '2000-01-01T00:00:00Z', cycle: { state: 'bonus', expectedVersion: 0 } }, tz)).toBe('upcoming')
			expect(dueClass({ isActive: true, nextDue: null, status: 'upcoming' }, tz)).toBe('upcoming')
		})

		it('stays today for a row without a block whose 00:00 timestamp already passed (CS-PLAN-068 date-boundary regression)', () => {
			// The UTC-midnight instant of TODAY is in the past for most of the
			// day, but the house-local day bucket still wins.
			const todayMidnightUtc = new Date()
			todayMidnightUtc.setUTCHours(0, 0, 0, 0)
			expect(dueClass({ isActive: true, nextDue: todayMidnightUtc.toISOString() }, tz)).toBe('today')
			expect(dueClass({ isActive: true, nextDue: '2999-01-01T00:00:00Z' }, tz)).toBe('upcoming')
		})
	})

	describe('dueText', () => {
		const tz = 'UTC'

		it('labels completed tasks', () => {
			expect(dueText({ isActive: false, nextDue: '2000-01-01T00:00:00Z' }, tz)).toBe('Completed')
		})

		it('counts the overdue house-local days from the end of the window', () => {
			expect(dueText(withCycle('overdue', -2), tz)).toBe('Overdue by 2 days')
			// Planned five days ago, the flex window ended two days ago.
			expect(dueText(withCycle('overdue', -5, -2), tz)).toBe('Overdue by 2 days')
		})

		// Regression (CS-PLAN-084 final review): the clamp to at least one day
		// made "Overdue by 1 days" reachable; a single day uses the singular key.
		it('uses the singular for one overdue day, including the clamped same-day case', () => {
			expect(dueText(withCycle('overdue', -1), tz)).toBe('Overdue by 1 day')
			expect(dueText(withCycle('overdue', 0), tz)).toBe('Overdue by 1 day')
		})

		it('labels today, tomorrow and the future by the planned day in house-local days', () => {
			expect(dueText(withCycle('open', 0), tz)).toBe('Due today')
			expect(dueText(withCycle('upcoming', 1), tz)).toBe('Due tomorrow')
			expect(dueText(withCycle('upcoming', 5, 7), tz)).toBe('Due in 5 days')
		})

		it('keeps an open flex window due today and labels a task without a window', () => {
			expect(dueText(withCycle('open', -1, 1), tz)).toBe('Due today')
			expect(dueText({ isActive: true, nextDue: '2000-01-01T00:00:00Z', cycle: { state: 'bonus', expectedVersion: 0 } }, tz)).toBe('No due date')
			expect(dueText({ isActive: true, nextDue: null }, tz)).toBe('No due date')
		})
	})

	describe('recurrenceLabel (FEAT-UX-047)', () => {
		it('adds the flex days to the recurrence', () => {
			expect(recurrenceLabel({ frequencyType: 'daily', frequencyValue: 3, flexDays: 2 })).toBe('Every 3 days ±2')
			expect(recurrenceLabel({ frequencyType: 'weekly', frequencyValue: 1, flexDays: 1 })).toBe('Weekly ±1')
		})

		it('stays plain without flex days', () => {
			expect(recurrenceLabel({ frequencyType: 'weekly', frequencyValue: 1, flexDays: 0 })).toBe('Weekly')
		})

		it('calls a one-time task what it is', () => {
			expect(recurrenceLabel({ recurrenceMode: 'once', frequencyType: 'weekly', frequencyValue: 1, flexDays: 2 })).toBe('One-time')
		})
	})

	describe('daysLabel', () => {
		it('counts one day in the singular and every other count in the plural', () => {
			expect(daysLabel(1)).toBe('1 day')
			expect(daysLabel(0)).toBe('0 days')
			expect(daysLabel(4)).toBe('4 days')
		})
	})

	describe('locationLabel (FEAT-UX-047)', () => {
		it('names floor and room', () => {
			expect(locationLabel({ name: 'Bathroom' }, { name: 'Upstairs' })).toBe('Upstairs · Bathroom')
		})

		it('names the room alone while the floors are unknown', () => {
			expect(locationLabel({ name: 'Bathroom' }, undefined)).toBe('Bathroom')
		})

		it('stays empty without a room', () => {
			expect(locationLabel(undefined, undefined)).toBe('')
		})
	})

	describe('taskEnergy (FEAT-UX-047)', () => {
		it('is the energy of the definition: open work is not frozen (ADR-021 decision 1)', () => {
			expect(taskEnergy({ task: { energyCost: 5 }, roomTask: { cycle: { state: 'open' } } })).toBe(5)
			expect(taskEnergy({ task: { energyCost: 2 }, cycle: { state: 'overdue' } })).toBe(2)
		})
	})

	describe('matchesEnergyFilter (FEAT-UX-046)', () => {
		it('matches a listed energy exactly', () => {
			expect(matchesEnergyFilter(3, '3')).toBe(true)
			expect(matchesEnergyFilter(4, '3')).toBe(false)
		})

		it('gathers every task above the list under the longer choice', () => {
			expect(matchesEnergyFilter(5, 'longer')).toBe(false)
			expect(matchesEnergyFilter(6, 'longer')).toBe(true)
			expect(matchesEnergyFilter(20, 'longer')).toBe(true)
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

		it('puts a task without a window last, whatever its stale projection says', () => {
			const bonus = { roomTask: { id: 1, nextDue: '2000-01-01T00:00:00Z', cycle: { state: 'bonus', expectedVersion: 0 } }, task: { priority: 3 } }
			const dated = { roomTask: { id: 2, nextDue: '2030-05-10T00:00:00Z', cycle: { state: 'upcoming', windowEndOn: '2030-05-10' } }, task: { priority: 1 } }
			expect([bonus, dated].sort(compareTaskItems).map(item => item.roomTask.id)).toEqual([2, 1])
		})
	})
})
