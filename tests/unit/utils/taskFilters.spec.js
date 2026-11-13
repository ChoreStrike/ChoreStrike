/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { filtersFromQuery, filterTaskItems, pageFromQuery } from '../../../src/utils/taskFilters.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))

const daysFromToday = (days) => new Date(Date.now() + days * 86400000).toISOString()

const row = (id, roomTask = {}, task = {}) => ({
	roomTask: { id, roomId: 10, isActive: true, nextDue: daysFromToday(3), assignees: [], ...roomTask },
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

		it('narrows by person, floor and room', () => {
			expect(filtered(rows, { person: 'teen' })).toEqual([2])
			expect(filtered(rows, { floor: '2' })).toEqual([2])
			expect(filtered(rows, { floor: '1', room: '10' })).toEqual([1, 3])
			expect(filtered(rows, { floor: '1', room: '20' })).toEqual([])
		})

		it('narrows by status with the rules of the row badge', () => {
			const statusRows = [
				row(1, { nextDue: daysFromToday(-2), occurrence: { classification: 'late' } }),
				row(2, { nextDue: daysFromToday(0) }),
				row(3, { isActive: false }),
				row(4),
			]

			expect(filtered(statusRows, { status: 'overdue' })).toEqual([1])
			expect(filtered(statusRows, { status: 'due-today' })).toEqual([2])
			expect(filtered(statusRows, { status: 'active' })).toEqual([1, 2, 4])
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
