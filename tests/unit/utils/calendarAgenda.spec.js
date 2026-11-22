/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { buildAgendaDays } from '../../../src/utils/calendarAgenda.js'

const TODAY = '2026-11-25' // A Wednesday
const WEEK = ['2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26', '2026-11-27', '2026-11-28', '2026-11-29']

const entry = (roomTaskId, userIds, rest = {}) => ({
	roomTaskId,
	cycleKey: `c${roomTaskId}`,
	userIds,
	energy: 2,
	overload: false,
	windowStartOn: '2026-11-25',
	windowEndOn: '2026-11-27',
	taskName: `Task ${roomTaskId}`,
	roomName: 'Kitchen',
	...rest,
})

const suggestion = (over = {}) => ({
	days: {
		'2026-11-25': [entry(1, ['mom']), entry(2, ['kid']), entry(3, ['kid', 'mom'], { overload: true })],
		'2026-11-26': [entry(4, ['mom'], { cycleKey: null })],
	},
	overdue: [entry(9, ['mom']), entry(10, ['kid']), entry(11, [])],
	loads: {
		'2026-11-25': { mom: { userId: 'mom', day: '2026-11-25', budget: 6, placed: 8, preferred: false, absent: false } },
		'2026-11-26': { mom: { userId: 'mom', day: '2026-11-26', budget: 6, placed: 2, preferred: true, absent: false } },
	},
	// The server names the horizon with every suggestion.
	horizonDays: 14,
	horizonEndOn: '2026-12-08',
	...over,
})

const build = (input = {}) => buildAgendaDays({
	days: WEEK,
	todayKey: TODAY,
	suggestion: suggestion(),
	rangeByDay: {},
	userId: 'mom',
	mineOnly: true,
	...input,
})
const byDay = (days) => Object.fromEntries(days.map((day) => [day.day, day]))
const names = (rows) => rows.map((row) => row.name)

describe('calendarAgenda (CS-PLAN-091 WP-C)', () => {
	it('looks as far ahead as the server\'s suggestion says', () => {
		const days = byDay(build({ days: [...WEEK, '2026-12-01'], suggestion: suggestion({ horizonEndOn: '2026-11-26' }) }))

		expect(days['2026-11-26'].beyond).toBe(false)
		expect(names(days['2026-11-26'].entries)).toEqual(['Task 4'])
		expect(days['2026-11-27'].beyond).toBe(true)
		expect(days['2026-12-01'].beyond).toBe(true)
	})

	it('knows no horizon before a suggestion is read: nothing is placed and nothing lies beyond', () => {
		const rangeByDay = { '2026-12-09': { scheduled: [{ roomTask: { id: 5 }, task: { name: 'Due', energyCost: 1 }, room: null, assignees: [], dueDate: '2026-12-09' }], completed: [] } }
		const days = byDay(build({
			days: [...WEEK, '2026-12-09'],
			rangeByDay,
			suggestion: { days: {}, overdue: [], loads: {}, horizonDays: null, horizonEndOn: null },
		}))

		expect(Object.values(days).map((day) => day.beyond)).not.toContain(true)
		expect(days['2026-11-25'].entries).toEqual([])
		expect(days['2026-12-09'].entries).toEqual([])
	})

	it('marks today, the past and what lies beyond the horizon', () => {
		const days = build({ days: [...WEEK, '2026-12-09'] })

		expect(days.map((day) => [day.isPast, day.isToday, day.beyond])).toEqual([
			[true, false, false], [true, false, false], [false, true, false],
			[false, false, false], [false, false, false], [false, false, false], [false, false, false],
			[false, false, true],
		])
	})

	it('shows the viewer what the suggestion places on them, with the window and the overload', () => {
		const days = byDay(build())

		expect(names(days['2026-11-25'].entries)).toEqual(['Task 1', 'Task 3'])
		expect(days['2026-11-25'].entries[1]).toMatchObject({ overload: true, userIds: ['kid', 'mom'], windowStartOn: '2026-11-25', windowEndOn: '2026-11-27', room: 'Kitchen', energy: 2 })
		expect(names(days['2026-11-26'].entries)).toEqual(['Task 4'])
		expect(days['2026-11-27'].entries).toEqual([])
	})

	it('shows everyone\'s suggestion without the filter', () => {
		const days = byDay(build({ mineOnly: false }))

		expect(names(days['2026-11-25'].entries)).toEqual(['Task 1', 'Task 2', 'Task 3'])
		expect(names(days['2026-11-25'].overdue)).toEqual(['Task 9', 'Task 10', 'Task 11'])
	})

	it('lists the overdue work on today only: the viewer\'s and what nobody is responsible for', () => {
		const days = byDay(build())

		expect(names(days['2026-11-25'].overdue)).toEqual(['Task 9', 'Task 11'])
		expect(days['2026-11-26'].overdue).toEqual([])
	})

	it('carries the viewer\'s load inside the horizon and none outside it', () => {
		const days = byDay(build({ days: [...WEEK, '2026-12-09'] }))

		expect(days['2026-11-25'].load).toMatchObject({ budget: 6, placed: 8 })
		expect(days['2026-11-26'].load.preferred).toBe(true)
		expect(days['2026-11-27'].load).toBeNull()
		expect(days['2026-11-24'].load).toBeNull()
		expect(days['2026-12-09'].load).toBeNull()
	})

	it('shows what was done on past days and today, and nothing planned for a past day', () => {
		const rangeByDay = {
			'2026-11-24': {
				scheduled: [{ roomTask: { id: 5 }, task: { name: 'Planned yesterday', energyCost: 1 }, room: { name: 'Hall' }, assignees: ['mom'], dueDate: '2026-11-24' }],
				completed: [{ taskName: 'Done yesterday', roomName: 'Hall' }, { taskName: null, roomName: 'Hall' }],
			},
			'2026-11-25': { scheduled: [], completed: [{ taskName: 'Done today', roomName: 'Kitchen' }] },
			'2026-11-26': { scheduled: [], completed: [{ taskName: 'Never shown', roomName: 'Kitchen' }] },
		}
		const days = byDay(build({ rangeByDay }))

		expect(days['2026-11-24'].entries).toEqual([])
		expect(names(days['2026-11-24'].completed)).toEqual(['Done yesterday', null])
		expect(names(days['2026-11-25'].completed)).toEqual(['Done today'])
		expect(days['2026-11-26'].completed).toEqual([])
	})

	it('shows the windows that are due beyond the horizon, from the range read', () => {
		const rangeByDay = {
			'2026-12-09': {
				scheduled: [
					{ roomTask: { id: 5 }, task: { name: 'Mine', energyCost: 3 }, room: { name: 'Hall' }, assignees: ['mom'], dueDate: '2026-12-09', windowStartOn: '2026-12-08', windowEndOn: '2026-12-10' },
					{ roomTask: { id: 6 }, task: { name: 'Unassigned', energyCost: 1 }, room: null, assignees: [], dueDate: '2026-12-09' },
					{ roomTask: { id: 7 }, task: { name: 'The kid\'s', energyCost: 1 }, room: { name: 'Hall' }, assignees: ['kid'], dueDate: '2026-12-09' },
				],
				completed: [],
			},
		}
		const [day] = build({ days: ['2026-12-09'], rangeByDay })

		expect(names(day.entries)).toEqual(['Mine', 'Unassigned'])
		expect(day.entries[0]).toMatchObject({ energy: 3, room: 'Hall', windowStartOn: '2026-12-08', windowEndOn: '2026-12-10', overload: false })
		// Without a window in the read the entry stands on its planned day.
		expect(day.entries[1]).toMatchObject({ room: '', windowStartOn: '2026-12-09', windowEndOn: '2026-12-09' })
	})

	it('shows every entry while the viewer is not known yet', () => {
		const days = byDay(build({ userId: null }))

		expect(days['2026-11-25'].entries).toHaveLength(3)
		expect(days['2026-11-25'].load).toBeNull()
	})
})
