/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { buildTodaySections, isDueToday, openEnergy } from '../../../src/utils/todaySections.js'

const todayAt = (hour) => {
	const now = new Date()
	return new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate(), hour)).toISOString()
}
const daysFromToday = (days) => new Date(Date.now() + days * 86400000).toISOString()

const row = (id, roomTask = {}, rest = {}) => ({
	roomTask: { id, isActive: true, nextDue: todayAt(8), ...roomTask },
	task: { id, name: `Task ${id}`, energyCost: 2 },
	assignees: [],
	...rest,
})
const ids = (rows) => rows.map((item) => item.roomTask.id)
const build = (input) => buildTodaySections({ daily: [], overdue: [], timezone: 'UTC', isVisible: () => true, ...input })

describe('todaySections (CS-PLAN-090 WP-B)', () => {
	describe('isDueToday', () => {
		it('reads the day in the house time zone, not in the browser\'s', () => {
			jest.useFakeTimers()
			try {
				jest.setSystemTime(new Date('2026-08-22T06:30:00Z'))
				// 21 Aug 20:00 UTC is still 21 Aug in Los Angeles, where it is 21 Aug 23:30.
				expect(isDueToday({ nextDue: '2026-08-21T20:00:00Z' }, 'America/Los_Angeles')).toBe(true)
				expect(isDueToday({ nextDue: '2026-08-21T20:00:00Z' }, 'UTC')).toBe(false)
			} finally {
				jest.useRealTimers()
			}
		})

		it('is false without a due date', () => {
			expect(isDueToday({ nextDue: null }, 'UTC')).toBe(false)
			expect(isDueToday(null, 'UTC')).toBe(false)
		})
	})

	describe('buildTodaySections', () => {
		it('sorts the daily read into due and possible rows', () => {
			const sections = build({
				daily: [
					row(1),
					row(2, { nextDue: daysFromToday(1) }, { openWindow: true, occurrence: { classification: 'agreed' } }),
					row(3, { nextDue: daysFromToday(1) }),
				],
			})

			expect(ids(sections.due)).toEqual([1])
			expect(ids(sections.possible)).toEqual([2])
			expect(sections.overdue).toEqual([])
		})

		it('keeps a completed one-time row for its undo and drops a recurring row that moved on', () => {
			const sections = build({
				daily: [
					row(1, { isActive: false, nextDue: null }),
					row(2, { nextDue: daysFromToday(7) }),
					row(3, { isActive: false }, { openWindow: true, occurrence: { classification: 'late' } }),
					row(4, {}, { openWindow: true, occurrence: { classification: 'late' } }),
				],
			})

			expect(ids(sections.due)).toEqual([1])
			expect(ids(sections.possible)).toEqual([3])
		})

		it('lists an active miss once: under due today when it is due today, else as overdue', () => {
			const sections = build({
				daily: [row(1)],
				overdue: [row(1), row(2, { nextDue: daysFromToday(-3) }), row(3, { isActive: false, nextDue: daysFromToday(-3) })],
			})

			expect(ids(sections.due)).toEqual([1])
			expect(ids(sections.overdue)).toEqual([2])
		})

		it('applies the viewer\'s filter to every section', () => {
			const mine = (item) => item.assignees.includes('mom')
			const sections = build({
				daily: [
					row(1, {}, { assignees: ['mom'] }),
					row(2, {}, { assignees: ['teen'] }),
					row(3, {}, { assignees: ['teen'], openWindow: true, occurrence: { classification: 'agreed' } }),
				],
				overdue: [row(4, { nextDue: daysFromToday(-1) }, { assignees: ['mom'] }), row(5, { nextDue: daysFromToday(-1) }, { assignees: ['teen'] })],
				isVisible: mine,
			})

			expect(ids(sections.due)).toEqual([1])
			expect(sections.possible).toEqual([])
			expect(ids(sections.overdue)).toEqual([4])
		})

		it('keeps a held row at its place although the reads moved it on', () => {
			const held = row(2)
			const sections = build({
				// Row 2 was completed: its next due date left today.
				daily: [row(1), row(2, { nextDue: daysFromToday(7) }), row(3)],
				held: [{ section: 'due', index: 1, item: held }],
			})

			expect(ids(sections.due)).toEqual([1, 2, 3])
			expect(sections.due[1]).toBe(held)
		})

		it('shows a held row in its own section only, whatever the filter says', () => {
			const held = row(9, { nextDue: daysFromToday(-2) }, { assignees: ['teen'] })
			const sections = build({
				// The completed miss is due again today in the fresh daily read.
				daily: [row(9, {}, { assignees: ['teen'] })],
				overdue: [],
				isVisible: () => false,
				held: [{ section: 'overdue', index: 0, item: held }],
			})

			expect(sections.due).toEqual([])
			expect(sections.overdue).toEqual([held])
		})

		it('places several held rows in their old order and clamps a place beyond the end', () => {
			const sections = build({
				daily: [row(1)],
				held: [
					{ section: 'due', index: 5, item: row(4) },
					{ section: 'due', index: 0, item: row(2) },
				],
			})

			expect(ids(sections.due)).toEqual([2, 1, 4])
		})
	})

	describe('openEnergy', () => {
		it('adds up the active due and overdue rows with the energy their occurrence holds', () => {
			const sections = {
				due: [row(1), row(2, { isActive: false }), row(3, {}, { occurrence: { energy: 5 } })],
				overdue: [row(4)],
				possible: [row(5)],
			}

			expect(openEnergy(sections)).toBe(9)
		})

		it('is zero for a free day', () => {
			expect(openEnergy({ due: [], overdue: [], possible: [] })).toBe(0)
		})
	})
})
