/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { buildTodaySections, isSuggestedToday, openEnergy, viewerFilters } from '../../../src/utils/todaySections.js'

const dayKey = (days) => new Date(Date.now() + days * 86400000).toISOString().slice(0, 10)
/** The cycle block of a row of Today: planned `planned` days from today, the window ends `end` days from today. */
const cycle = (planned, end = planned) => ({
	cycleKey: 'c1',
	expectedVersion: 0,
	state: end < 0 ? 'overdue' : 'open',
	centreOn: dayKey(planned),
	windowStartOn: dayKey(Math.min(planned, 0)),
	windowEndOn: dayKey(end),
})

/** A row the suggestion placed on today unless `rest` says otherwise. */
const row = (id, roomTask = {}, rest = {}) => ({
	roomTask: { id, isActive: true, ...roomTask },
	task: { id, name: `Task ${id}`, energyCost: 2 },
	assignees: [],
	suggestedFor: ['mom'],
	cycle: cycle(0),
	...rest,
})
/** A row whose window is open although today's suggestion does not hold it. */
const open = (id, rest = {}) => row(id, {}, { openWindow: true, suggestedFor: undefined, cycle: cycle(1, 2), ...rest })
const ids = (rows) => rows.map((item) => item.roomTask.id)
const build = (input) => buildTodaySections({ daily: [], overdue: [], isVisible: () => true, ...input })

describe('todaySections (CS-PLAN-090 WP-B, CS-PLAN-091 WP-C)', () => {
	describe('isSuggestedToday', () => {
		it('is what the daily read does not mark as only possible, wherever its window is centred', () => {
			expect(isSuggestedToday(row(1, {}, { cycle: cycle(2, 3) }))).toBe(true)
			expect(isSuggestedToday(open(1, { cycle: cycle(0) }))).toBe(false)
			expect(isSuggestedToday(null)).toBe(false)
		})
	})

	describe('viewerFilters', () => {
		it('counts as the work of the viewer what is assigned to them or to nobody', () => {
			const { isMine } = viewerFilters('mom')

			expect(isMine(row(1, {}, { assignees: ['mom', 'teen'] }))).toBe(true)
			expect(isMine(row(1))).toBe(true)
			expect(isMine(row(1, {}, { assignees: ['teen'] }))).toBe(false)
			// The task list hands the assignees over inside the room task.
			expect(isMine({ roomTask: { id: 1, assignees: ['teen'] } })).toBe(false)
		})

		it('counts as suggested to the viewer what today places on them, whoever it is assigned to', () => {
			const { isSuggested } = viewerFilters('mom')

			expect(isSuggested(row(1, {}, { suggestedFor: ['teen', 'mom'], assignees: ['teen'] }))).toBe(true)
			expect(isSuggested(row(1, {}, { suggestedFor: ['teen'] }))).toBe(false)
			// A row that names no suggestion falls back to the assignment.
			expect(isSuggested(row(1, {}, { suggestedFor: undefined }))).toBe(true)
			expect(isSuggested(row(1, {}, { suggestedFor: undefined, assignees: ['teen'] }))).toBe(false)
		})

		it('shows everything before the viewer is known, rather than an empty list', () => {
			const { isMine, isSuggested } = viewerFilters(null)

			expect(isMine(row(1, {}, { assignees: ['teen'] }))).toBe(true)
			expect(isSuggested(row(1, {}, { suggestedFor: ['teen'], assignees: ['teen'] }))).toBe(true)
		})
	})

	describe('buildTodaySections', () => {
		it('sorts the daily read into today\'s suggestion and what is also possible', () => {
			const sections = build({ daily: [row(1), open(2), row(3, {}, { cycle: cycle(1, 2) })] })

			// Row 3 is planned for tomorrow, and the suggestion placed it on today.
			expect(ids(sections.due)).toEqual([1, 3])
			expect(ids(sections.possible)).toEqual([2])
			expect(sections.overdue).toEqual([])
		})

		it('keeps a completed one-time row for its undo and drops a recurring row that moved on', () => {
			const sections = build({
				daily: [
					row(1, { isActive: false }, { cycle: null }),
					row(2, {}, { cycle: { ...cycle(7), state: 'upcoming' } }),
					open(3, { roomTask: { id: 3, isActive: false }, cycle: null }),
					open(4, { cycle: cycle(-3, -1) }),
				],
			})

			expect(ids(sections.due)).toEqual([1])
			expect(ids(sections.possible)).toEqual([3])
		})

		it('lists an active miss once: with today\'s suggestion when it is on it, else as overdue', () => {
			const sections = build({
				daily: [row(1)],
				overdue: [row(1), row(2, {}, { cycle: cycle(-3) }), row(3, { isActive: false }, { cycle: cycle(-3) })],
			})

			expect(ids(sections.due)).toEqual([1])
			expect(ids(sections.overdue)).toEqual([2])
		})

		it('shows today\'s suggestion to the people it names, and their other work as also possible', () => {
			const mine = (item) => item.assignees.length === 0 || item.assignees.includes('mom')
			const sections = build({
				daily: [
					row(1, {}, { assignees: ['mom'] }),
					// Unassigned work that today suggests to someone else: possible for everyone who may do it.
					row(2, {}, { suggestedFor: ['teen'] }),
					row(3, {}, { assignees: ['teen'], suggestedFor: ['teen'] }),
					open(4, { assignees: ['teen'] }),
					open(5, { assignees: ['mom'] }),
				],
				overdue: [row(6, {}, { assignees: ['mom'], cycle: cycle(-1) }), row(7, {}, { assignees: ['teen'], cycle: cycle(-1) })],
				isVisible: mine,
				isSuggested: (item) => item.suggestedFor.includes('mom'),
			})

			expect(ids(sections.due)).toEqual([1])
			expect(ids(sections.possible)).toEqual([2, 5])
			expect(ids(sections.overdue)).toEqual([6])
		})

		it('shows everyone\'s suggestion without a filter', () => {
			const sections = build({ daily: [row(1), row(2, {}, { suggestedFor: ['teen'] }), open(3)] })

			expect(ids(sections.due)).toEqual([1, 2])
			expect(ids(sections.possible)).toEqual([3])
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
				// The completed miss is on today's list again in the fresh daily read.
				daily: [row(9, {}, { assignees: ['teen'] })],
				overdue: [],
				isVisible: () => false,
				isSuggested: () => false,
				held: [{ section: 'overdue', index: 0, item: held }],
			})

			expect(sections.due).toEqual([])
			expect(sections.possible).toEqual([])
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
		it('adds up the active suggested and overdue rows with the energy of their definition', () => {
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
