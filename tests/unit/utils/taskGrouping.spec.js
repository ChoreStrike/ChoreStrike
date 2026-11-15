/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import {
	compareByDueGroup,
	groupByDue,
	groupByPerson,
	groupByRoom,
	groupingFromQuery,
	openGroupFromQuery,
	sortByDueGroup,
	UNASSIGNED,
} from '../../../src/utils/taskGrouping.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))

const daysFromToday = (days) => new Date(Date.now() + days * 86400000).toISOString()

const row = (id, roomTask = {}, task = {}) => ({
	roomTask: { id, roomId: 10, isActive: true, nextDue: daysFromToday(3), assignees: [], ...roomTask },
	task: { id, name: `Task ${id}`, priority: 2, ...task },
})
const late = { nextDue: daysFromToday(-2), occurrence: { classification: 'late' } }
const ids = (rows) => rows.map((item) => item.roomTask.id)

describe('taskGrouping (CS-PLAN-090 WP-C)', () => {
	describe('route query', () => {
		it('reads the grouping and falls back to the list by due date', () => {
			expect(groupingFromQuery('room')).toBe('room')
			expect(groupingFromQuery('person')).toBe('person')
			expect(groupingFromQuery(undefined)).toBe('due')
			expect(groupingFromQuery('floor')).toBe('due')
		})

		it('opens a room only in the list by room and only for a number', () => {
			expect(openGroupFromQuery({ group: 'room', room: '12' })).toBe(12)
			expect(openGroupFromQuery({ group: 'room' })).toBeNull()
			expect(openGroupFromQuery({ group: 'room', room: 'kitchen' })).toBeNull()
			expect(openGroupFromQuery({ group: 'person', room: '12' })).toBeNull()
			expect(openGroupFromQuery({ room: '12' })).toBeNull()
		})
	})

	describe('by due date', () => {
		const rows = [
			row(1),
			row(2, late),
			row(3, { nextDue: daysFromToday(0) }),
			row(4, { isActive: false, nextDue: daysFromToday(-9) }),
			// Planned in the past, its flex window still open: due, not missed.
			row(5, { nextDue: daysFromToday(-3), occurrence: { classification: 'agreed' } }),
			row(6, { nextDue: null }),
		]

		it('groups by due class in list order and leaves out an empty class', () => {
			const groups = groupByDue(rows, 'UTC')

			expect(groups.map((group) => group.key)).toEqual(['overdue', 'today', 'upcoming', 'completed'])
			expect(groups.map((group) => ids(group.items))).toEqual([[2], [5, 3], [1, 6], [4]])
			expect(groupByDue([row(1)], 'UTC').map((group) => group.key)).toEqual(['upcoming'])
			expect(groupByDue([], 'UTC')).toEqual([])
		})

		it('counts open and overdue rows per group', () => {
			const [overdue, today, , completed] = groupByDue(rows, 'UTC')

			expect(overdue).toMatchObject({ open: 1, overdue: 1 })
			expect(today).toMatchObject({ open: 2, overdue: 0 })
			expect(completed).toMatchObject({ open: 0, overdue: 0 })
		})

		it('orders a paged list by due class first, so a class is never split by an earlier date', () => {
			// Row 5 is planned before the missed row 2 and still comes after it.
			expect(ids([...rows].sort(compareByDueGroup('UTC')))).toEqual([2, 5, 3, 1, 6, 4])
		})

		it('sorts a whole list into the same order without touching its input', () => {
			const input = [...rows]

			expect(ids(sortByDueGroup(input, 'UTC'))).toEqual([2, 5, 3, 1, 6, 4])
			expect(ids(input)).toEqual([1, 2, 3, 4, 5, 6])
			expect(sortByDueGroup([], 'UTC')).toEqual([])
		})
	})

	describe('by room', () => {
		const floors = [{ id: 1, name: 'Ground' }, { id: 2, name: 'Upstairs' }, { id: 3, name: 'Attic' }]
		const rooms = [
			{ id: 10, floorId: 1, name: 'Kitchen' },
			{ id: 11, floorId: 1, name: 'Hall' },
			{ id: 20, floorId: 2, name: 'Bath' },
		]
		const rows = [row(1), row(2, { roomId: 20, ...late }), row(3, { roomId: 10, nextDue: daysFromToday(1) }), row(4, { roomId: 99 })]

		it('lists floors and rooms in their order, with a room that has no rows', () => {
			const groups = groupByRoom(rows, floors, rooms)

			expect(groups.map((group) => group.floor.name)).toEqual(['Ground', 'Upstairs'])
			expect(groups[0].rooms.map((group) => group.room.name)).toEqual(['Kitchen', 'Hall'])
			expect(ids(groups[0].rooms[0].items)).toEqual([3, 1])
			expect(groups[0].rooms[1]).toMatchObject({ items: [], open: 0, overdue: 0 })
		})

		it('sums the counts of a floor from its rooms', () => {
			const [ground, upstairs] = groupByRoom(rows, floors, rooms)

			expect(ground).toMatchObject({ open: 2, overdue: 0 })
			expect(upstairs).toMatchObject({ open: 1, overdue: 1 })
			expect(upstairs.rooms[0]).toMatchObject({ open: 1, overdue: 1 })
		})

		it('leaves out a floor without a room to show, and rows of a room that is not shown', () => {
			const groups = groupByRoom(rows, floors, rooms.filter((room) => room.id === 20))

			expect(groups).toHaveLength(1)
			expect(ids(groups[0].rooms[0].items)).toEqual([2])
		})
	})

	describe('by person', () => {
		const members = [
			{ nextcloudUserId: 'mom', displayName: 'Parent' },
			{ nextcloudUserId: 'teen', displayName: '' },
			{ nextcloudUserId: 'kid', displayName: 'Kid' },
		]

		it('lists members with rows in member order, then the unassigned rows', () => {
			const groups = groupByPerson([
				row(1, { assignees: ['teen'] }),
				row(2),
				row(3, { assignees: ['mom'], ...late }),
			], members)

			expect(groups.map((group) => group.key)).toEqual(['mom', 'teen', UNASSIGNED])
			expect(groups.map((group) => group.name)).toEqual(['Parent', 'teen', UNASSIGNED])
			expect(groups[0]).toMatchObject({ open: 1, overdue: 1 })
		})

		it('shows a shared row under each of its assignees', () => {
			const groups = groupByPerson([row(1, { assignees: ['mom', 'kid'] })], members)

			expect(groups.map((group) => [group.key, ids(group.items)])).toEqual([['mom', [1]], ['kid', [1]]])
		})

		it('keeps a group for an assignee who is no longer a member', () => {
			const groups = groupByPerson([row(1, { assignees: ['gone'] })], members)

			expect(groups).toHaveLength(1)
			expect(groups[0]).toMatchObject({ key: 'gone', name: 'gone' })
		})

		it('is empty without rows', () => {
			expect(groupByPerson([], members)).toEqual([])
		})
	})
})
