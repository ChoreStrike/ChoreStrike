/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { filtersFromQuery, filterTaskItems, pageFromQuery, peopleOfScope, scopeOfPeople } from '../../../src/utils/taskFilters.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))

const dayKey = (days) => new Date(Date.now() + days * 86400000).toISOString().slice(0, 10)
/**
 * What a list row carries for its current cycle (ADR-021): the block and
 * the projected end of the window. Planned `planned` days from today, the
 * window ends `end` days from today.
 */
const due = (planned, end = planned) => ({
	nextDue: `${dayKey(end)}T00:00:00Z`,
	cycle: {
		cycleKey: 'c1',
		expectedVersion: 0,
		state: end < 0 ? 'overdue' : (planned <= 0 ? 'open' : 'upcoming'),
		centreOn: dayKey(planned),
		windowStartOn: dayKey(planned),
		windowEndOn: dayKey(end),
	},
})

const row = (id, roomTask = {}, task = {}) => ({
	roomTask: { id, roomId: 10, isActive: true, ...due(3), assignees: [], ...roomTask },
	task: { id, name: `Task ${id}`, priority: 2, energyCost: 2, ...task },
})
const rooms = [{ id: 10, floorId: 1 }, { id: 20, floorId: 2 }]
const context = { rooms, actorId: 'mom', timezone: 'UTC' }
const filtered = (rows, patch, over = {}) => filterTaskItems(rows, { ...filtersFromQuery({ scope: 'all' }), ...patch }, { ...context, ...over })
	.map((item) => item.roomTask.id)

describe('taskFilters', () => {
	describe('filtersFromQuery', () => {
		it('defaults to the viewer\'s own tasks', () => {
			expect(filtersFromQuery()).toMatchObject({ scope: 'mine', status: 'all', visibility: 'all' })
		})

		it('starts without a custom choice of people, and without the old person filter', () => {
			expect(filtersFromQuery()).toMatchObject({ people: [], unassigned: true, floor: '', room: '' })
			expect(filtersFromQuery()).not.toHaveProperty('person')
		})

		it('starts a grouped list with everyone\'s tasks unless the link names a scope (D-090-41)', () => {
			expect(filtersFromQuery({ group: 'room' }).scope).toBe('all')
			expect(filtersFromQuery({ group: 'person' }).scope).toBe('all')
			expect(filtersFromQuery({ group: 'room', scope: 'mine' }).scope).toBe('mine')
			expect(filtersFromQuery({ group: 'due' }).scope).toBe('mine')
			expect(filtersFromQuery({ group: 'unknown' }).scope).toBe('mine')
		})

		it('reads the presets and ignores unknown values', () => {
			expect(filtersFromQuery({ visibility: 'private' })).toMatchObject({ visibility: 'private', scope: 'all' })
			expect(filtersFromQuery({ status: 'overdue', scope: 'unassigned' })).toMatchObject({ status: 'overdue', scope: 'unassigned' })
			expect(filtersFromQuery({ status: 'completed', scope: 'nobody' })).toMatchObject({ status: 'all', scope: 'mine' })
		})

		it('presets a category by its key shape only (CS-PLAN-090 WP-I)', () => {
			expect(filtersFromQuery().category).toBe('all')
			expect(filtersFromQuery({ category: 'kitchen' }).category).toBe('kitchen')
			expect(filtersFromQuery({ category: 'c0a1b2c3d' }).category).toBe('c0a1b2c3d')
			expect(filtersFromQuery({ category: '../x' }).category).toBe('all')
			expect(filtersFromQuery({ category: ['kitchen'] }).category).toBe('all')
		})
	})

	describe('peopleOfScope', () => {
		const memberIds = ['mom', 'teen', 'kid']

		it('ticks what each scope button stands for', () => {
			expect(peopleOfScope({ scope: 'mine', actorId: 'mom' }, memberIds)).toEqual({ people: ['mom'], unassigned: true })
			expect(peopleOfScope({ scope: 'unassigned', actorId: 'mom' }, memberIds)).toEqual({ people: [], unassigned: true })
			expect(peopleOfScope({ scope: 'all', actorId: 'mom' }, memberIds)).toEqual({ people: memberIds, unassigned: true })
		})

		it('ticks nobody under "mine" while the viewer is unknown', () => {
			expect(peopleOfScope({ scope: 'mine', actorId: null }, memberIds)).toEqual({ people: [], unassigned: true })
			expect(peopleOfScope({ scope: 'mine' }, memberIds)).toEqual({ people: [], unassigned: true })
		})

		it('ignores a left-over custom choice under a preset', () => {
			expect(peopleOfScope({ scope: 'unassigned', people: ['teen'], unassigned: false }, memberIds)).toEqual({ people: [], unassigned: true })
		})

		it('ticks the custom choice as it is held', () => {
			expect(peopleOfScope({ scope: 'custom', people: ['teen'], unassigned: false }, memberIds)).toEqual({ people: ['teen'], unassigned: false })
			expect(peopleOfScope({ scope: 'custom', people: ['teen'], unassigned: true }, memberIds)).toEqual({ people: ['teen'], unassigned: true })
			expect(peopleOfScope({ scope: 'custom' }, memberIds)).toEqual({ people: [], unassigned: false })
		})
	})

	describe('scopeOfPeople', () => {
		const memberIds = ['mom', 'teen', 'kid']
		const preset = (scope) => ({ scope, people: [], unassigned: true })

		it('names the preset a choice is', () => {
			expect(scopeOfPeople(['kid', 'mom', 'teen'], true, memberIds, 'mom')).toEqual(preset('all'))
			expect(scopeOfPeople([], true, memberIds, 'mom')).toEqual(preset('unassigned'))
			expect(scopeOfPeople(['mom'], true, memberIds, 'mom')).toEqual(preset('mine'))
		})

		it('keeps any other choice as a custom one, with a copy of the people', () => {
			const people = ['teen']
			const custom = scopeOfPeople(people, true, memberIds, 'mom')
			expect(custom).toEqual({ scope: 'custom', people: ['teen'], unassigned: true })
			expect(custom.people).not.toBe(people)

			expect(scopeOfPeople(['mom', 'teen'], true, memberIds, 'mom').scope).toBe('custom')
			expect(scopeOfPeople(['mom'], true, memberIds, null).scope).toBe('custom')
		})

		it('is never a preset without unassigned work: every preset shows it', () => {
			expect(scopeOfPeople(memberIds, false, memberIds, 'mom')).toEqual({ scope: 'custom', people: memberIds, unassigned: false })
			expect(scopeOfPeople(['mom'], false, memberIds, 'mom')).toEqual({ scope: 'custom', people: ['mom'], unassigned: false })
			expect(scopeOfPeople([], false, memberIds, 'mom')).toEqual({ scope: 'custom', people: [], unassigned: false })
		})

		it('does not take an empty member list for everyone', () => {
			expect(scopeOfPeople([], true, [], 'mom')).toEqual(preset('unassigned'))
		})

		it('round-trips every preset through its ticks', () => {
			for (const scope of ['mine', 'unassigned', 'all']) {
				const ticks = peopleOfScope({ scope, actorId: 'mom' }, memberIds)
				expect(scopeOfPeople(ticks.people, ticks.unassigned, memberIds, 'mom').scope).toBe(scope)
			}
		})
	})

	it('reads the page and falls back to the first', () => {
		expect(pageFromQuery('3')).toBe(3)
		expect(pageFromQuery('0')).toBe(1)
		expect(pageFromQuery('abc')).toBe(1)
		expect(pageFromQuery(undefined)).toBe(1)
	})

	describe('filterTaskItems', () => {
		const rows = [
			row(1, { assignees: ['mom'] }),
			row(2, { assignees: ['teen'], roomId: 20 }),
			row(3),
		]

		it('shows own and unassigned rows under "mine", and assigned rows while the viewer is unknown', () => {
			expect(filtered(rows, { scope: 'mine' })).toEqual([1, 3])
			expect(filtered(rows, { scope: 'mine' }, { actorId: undefined })).toEqual([1, 2, 3])
			expect(filtered(rows, { scope: 'unassigned' })).toEqual([3])
		})

		it('narrows by a custom choice of people, with or without unassigned work', () => {
			const custom = (people, unassigned) => filtered(rows, { scope: 'custom', people, unassigned })

			expect(custom(['teen'], false)).toEqual([2])
			expect(custom(['teen'], true)).toEqual([2, 3])
			expect(custom(['mom', 'teen'], false)).toEqual([1, 2])
			expect(custom([], false)).toEqual([])
			// A row counts for a ticked person also when it has others.
			expect(filtered([row(4, { assignees: ['mom', 'teen'] })], { scope: 'custom', people: ['teen'], unassigned: false })).toEqual([4])
			// A custom choice without its lists ticks nobody.
			expect(filtered(rows, { scope: 'custom', people: undefined, unassigned: undefined })).toEqual([])
		})

		it('keeps a custom choice apart from the viewer: nobody is added', () => {
			expect(filtered(rows, { scope: 'custom', people: ['teen'], unassigned: false }, { actorId: undefined })).toEqual([2])
		})

		it('ignores the people of a custom choice under a preset', () => {
			expect(filtered(rows, { scope: 'all', people: ['teen'], unassigned: false })).toEqual([1, 2, 3])
			expect(filtered(rows, { scope: 'mine', people: ['teen'], unassigned: false })).toEqual([1, 3])
		})

		it('narrows by floor and room, also together with a choice of people', () => {
			expect(filtered(rows, { floor: '2' })).toEqual([2])
			expect(filtered(rows, { floor: '1', scope: 'custom', people: ['mom', 'teen'], unassigned: false })).toEqual([1])
			expect(filtered(rows, { floor: '1', room: '10' })).toEqual([1, 3])
			expect(filtered(rows, { floor: '1', room: '20' })).toEqual([])
		})

		it('narrows by status with the rules of the row badge', () => {
			const statusRows = [
				row(1, due(-2)),
				row(2, due(0)),
				row(3, { isActive: false, cycle: null }),
				row(4),
				// Planned yesterday, the flex window still open: due, not missed.
				row(5, due(-1, 1)),
			]

			expect(filtered(statusRows, { status: 'overdue' })).toEqual([1])
			expect(filtered(statusRows, { status: 'due-today' })).toEqual([2, 5])
			expect(filtered(statusRows, { status: 'active' })).toEqual([1, 2, 4, 5])
		})

		it('narrows by priority, energy, visibility and name', () => {
			const mixed = [
				row(1, {}, { priority: 3, name: 'Vacuum' }),
				row(2, {}, { energyCost: 4, name: 'Windows' }),
				row(3, {}, { visibilityType: 'private', name: 'Diary' }),
				row(4, {}, { name: undefined }),
			]

			expect(filtered(mixed, { priority: 'urgent' })).toEqual([1])
			expect(filtered(mixed, { difficulty: '4' })).toEqual([2])
			expect(filtered(mixed, { visibility: 'private' })).toEqual([3])
			expect(filtered(mixed, { search: '  WIN ' })).toEqual([2])
		})

		it('narrows by category and counts a deleted key as Other (D-090-65)', () => {
			const categorized = [
				row(1, {}, { category: 'kitchen' }),
				row(2, {}, { category: 'cleaning' }),
				row(3, {}, { category: 'c0a1b2c3d' }),
				row(4, {}, { category: 'other' }),
			]
			const household = { categories: [{ key: 'kitchen', label: null }, { key: 'c0a1b2c3d', label: 'Garden' }, { key: 'other', label: null }] }

			expect(filtered(categorized, { category: 'kitchen' }, household)).toEqual([1])
			expect(filtered(categorized, { category: 'c0a1b2c3d' }, household)).toEqual([3])
			expect(filtered(categorized, { category: 'other' }, household)).toEqual([2, 4])
			expect(filtered(categorized, { category: 'all' }, household)).toEqual([1, 2, 3, 4])
			// Before the house loads, the six built-in keys apply.
			expect(filtered(categorized, { category: 'cleaning' })).toEqual([2])
		})
	})
})
