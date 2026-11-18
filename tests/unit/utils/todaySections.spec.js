/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { buildTodaySections, isDueToday, openEnergy } from '../../../src/utils/todaySections.js'

const dayKey = (days) => new Date(Date.now() + days * 86400000).toISOString().slice(0, 10)
/** The cycle block of a My Day row: planned `planned` days from today, the window ends `end` days from today. */
const cycle = (planned, end = planned) => ({
	cycleKey: 'c1',
	expectedVersion: 0,
	state: end < 0 ? 'overdue' : 'open',
	centreOn: dayKey(planned),
	windowStartOn: dayKey(Math.min(planned, 0)),
	windowEndOn: dayKey(end),
})

/** A row planned for today unless `rest` carries another cycle. */
const row = (id, roomTask = {}, rest = {}) => ({
	roomTask: { id, isActive: true, ...roomTask },
	task: { id, name: `Task ${id}`, energyCost: 2 },
	assignees: [],
	cycle: cycle(0),
	...rest,
})
const ids = (rows) => rows.map((item) => item.roomTask.id)
const build = (input) => buildTodaySections({ daily: [], overdue: [], timezone: 'UTC', isVisible: () => true, ...input })

describe('todaySections (CS-PLAN-090 WP-B)', () => {
	describe('isDueToday', () => {
		it('is the planned day of the cycle, in the house time zone', () => {
			jest.useFakeTimers()
			try {
				jest.setSystemTime(new Date('2026-08-22T06:30:00Z'))
				// It is still 21 Aug in Los Angeles (23:30), already 22 Aug in UTC.
				const planned = { roomTask: { id: 1 }, cycle: { centreOn: '2026-08-21' } }
				expect(isDueToday(planned, 'America/Los_Angeles')).toBe(true)
				expect(isDueToday(planned, 'UTC')).toBe(false)
			} finally {
				jest.useRealTimers()
			}
		})

		it('is not the end of the window: a row planned tomorrow whose window is open is not due today', () => {
			expect(isDueToday(row(1, {}, { cycle: cycle(1, 2) }), 'UTC')).toBe(false)
			expect(isDueToday(row(1, {}, { cycle: cycle(0, 2) }), 'UTC')).toBe(true)
		})

		it('falls back to the projected instant for a row delivered without a block', () => {
			expect(isDueToday({ roomTask: { nextDue: new Date().toISOString() } }, 'UTC')).toBe(true)
		})

		it('is false without a window', () => {
			expect(isDueToday(row(1, {}, { cycle: null }), 'UTC')).toBe(false)
			expect(isDueToday(null, 'UTC')).toBe(false)
		})
	})

	describe('buildTodaySections', () => {
		it('sorts the daily read into due and possible rows', () => {
			const sections = build({
				daily: [
					row(1),
					row(2, {}, { openWindow: true, cycle: cycle(1, 2) }),
					row(3, {}, { cycle: cycle(1, 2) }),
				],
			})

			expect(ids(sections.due)).toEqual([1])
			expect(ids(sections.possible)).toEqual([2])
			expect(sections.overdue).toEqual([])
		})

		it('keeps a completed one-time row for its undo and drops a recurring row that moved on', () => {
			const sections = build({
				daily: [
					row(1, { isActive: false }, { cycle: null }),
					row(2, {}, { cycle: { ...cycle(7), state: 'upcoming' } }),
					row(3, { isActive: false }, { openWindow: true, cycle: null }),
					row(4, {}, { openWindow: true, cycle: cycle(-3, -1) }),
				],
			})

			expect(ids(sections.due)).toEqual([1])
			expect(ids(sections.possible)).toEqual([3])
		})

		it('lists an active miss once: under due today when it is due today, else as overdue', () => {
			const sections = build({
				daily: [row(1)],
				overdue: [row(1), row(2, {}, { cycle: cycle(-3) }), row(3, { isActive: false }, { cycle: cycle(-3) })],
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
					row(3, {}, { assignees: ['teen'], openWindow: true, cycle: cycle(1, 2) }),
				],
				overdue: [row(4, {}, { assignees: ['mom'], cycle: cycle(-1) }), row(5, {}, { assignees: ['teen'], cycle: cycle(-1) })],
				isVisible: mine,
			})

			expect(ids(sections.due)).toEqual([1])
			expect(sections.possible).toEqual([])
			expect(ids(sections.overdue)).toEqual([4])
		})

		it('keeps a held row at its place although the reads moved it on', () => {
			const held = row(2)
			const sections = build({
				// Row 2 was completed: its next cycle is planned for another day.
				daily: [row(1), row(2, {}, { cycle: { ...cycle(7), state: 'upcoming' } }), row(3)],
				held: [{ section: 'due', index: 1, item: held }],
			})

			expect(ids(sections.due)).toEqual([1, 2, 3])
			expect(sections.due[1]).toBe(held)
		})

		it('shows a held row in its own section only, whatever the filter says', () => {
			const held = row(9, {}, { assignees: ['teen'], cycle: cycle(-2) })
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
		it('adds up the active due and overdue rows with the energy of their definition', () => {
			const sections = {
				due: [row(1), row(2, { isActive: false }), { ...row(3), task: { id: 3, name: 'Task 3', energyCost: 5 } }],
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
