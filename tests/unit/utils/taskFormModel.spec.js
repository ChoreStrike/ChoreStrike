/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, expect, it } from '@jest/globals'
import {
	detailsOf,
	flexCapOf,
	formFields,
	parseWeekdays,
	rhythmFields,
	rhythmOf,
	rhythmProblem,
	templateFill,
} from '@/utils/taskFormModel.js'

const rhythm = (overrides = {}) => ({ ...rhythmOf(null), ...overrides })

/** The model of the one form (CS-PLAN-091 WP-B6, FEAT-UX-040). */
describe('taskFormModel', () => {
	describe('parseWeekdays', () => {
		it('reads stored JSON text and a stored list', () => {
			expect(parseWeekdays('["monday","friday"]')).toEqual(['monday', 'friday'])
			expect(parseWeekdays(['tuesday'])).toEqual(['tuesday'])
		})

		it('reads nothing from empty, malformed or non-list values', () => {
			expect(parseWeekdays(null)).toEqual([])
			expect(parseWeekdays('')).toEqual([])
			expect(parseWeekdays('{not json')).toEqual([])
			expect(parseWeekdays('{"a":1}')).toEqual([])
		})
	})

	describe('rhythmOf', () => {
		it('starts a new task weekly after completion with one flex day', () => {
			expect(rhythmOf(null)).toEqual({ mode: 'after', every: 1, unit: 'week', weekdays: [], monthDay: null, flexDays: 1 })
		})

		it('reads the three modes from a stored definition', () => {
			expect(rhythmOf({ recurrenceMode: 'once', schedulingMode: 'static' }).mode).toBe('once')
			expect(rhythmOf({ recurrenceMode: 'recurring', schedulingMode: 'static' }).mode).toBe('fixed')
			expect(rhythmOf({ recurrenceMode: 'recurring', schedulingMode: 'dynamic' }).mode).toBe('after')
		})

		it('reads the unit, the rule and the flex days, and shows a custom rule as weeks', () => {
			expect(rhythmOf({
				schedulingMode: 'static',
				frequencyType: 'weekly',
				frequencyValue: 2,
				fixedScheduleDays: '["monday","thursday"]',
				flexDays: 1,
			})).toEqual({ mode: 'fixed', every: 2, unit: 'week', weekdays: ['monday', 'thursday'], monthDay: null, flexDays: 1 })
			expect(rhythmOf({ frequencyType: 'daily', frequencyValue: 3 })).toMatchObject({ unit: 'day', every: 3, flexDays: 0 })
			expect(rhythmOf({ frequencyType: 'monthly', fixedMonthDay: 15 })).toMatchObject({ unit: 'month', every: 1, monthDay: 15 })
			expect(rhythmOf({ frequencyType: 'custom' }).unit).toBe('week')
		})
	})

	describe('rhythmFields', () => {
		it('keeps a repeating rhythm of a task stored as bounded bounded: the form knows no bounded rule', () => {
			expect(rhythmFields(rhythm(), 'bounded').recurrenceMode).toBe('bounded')
			expect(rhythmFields(rhythm({ mode: 'fixed', weekdays: ['monday'] }), 'bounded').recurrenceMode).toBe('bounded')
			// Once is the member's choice, and every other stored mode repeats.
			expect(rhythmFields(rhythm({ mode: 'once' }), 'bounded').recurrenceMode).toBe('once')
			expect(rhythmFields(rhythm(), 'once').recurrenceMode).toBe('recurring')
			expect(rhythmFields(rhythm()).recurrenceMode).toBe('recurring')
		})

		it('sends a fixed weekly rule as a weekday list and nothing of a month day', () => {
			expect(rhythmFields(rhythm({ mode: 'fixed', weekdays: ['monday'], monthDay: 9, flexDays: 0 }))).toEqual({
				recurrenceMode: 'recurring',
				schedulingMode: 'static',
				frequencyType: 'weekly',
				frequencyValue: 1,
				fixedScheduleDays: '["monday"]',
				fixedMonthDay: null,
				flexDays: 0,
			})
		})

		it('sends a fixed monthly rule as a day of the month', () => {
			expect(rhythmFields(rhythm({ mode: 'fixed', unit: 'month', weekdays: ['monday'], monthDay: 15 })))
				.toMatchObject({ frequencyType: 'monthly', fixedScheduleDays: null, fixedMonthDay: 15 })
		})

		it('leaves nothing of a fixed rule behind after completion or once', () => {
			const after = rhythmFields(rhythm({ mode: 'after', unit: 'day', every: '3', weekdays: ['monday'], monthDay: 4 }))
			expect(after).toMatchObject({ recurrenceMode: 'recurring', schedulingMode: 'dynamic', frequencyType: 'daily', frequencyValue: 3 })
			expect(after.fixedScheduleDays).toBeNull()
			expect(after.fixedMonthDay).toBeNull()
			expect(rhythmFields(rhythm({ mode: 'once' }))).toMatchObject({ recurrenceMode: 'once', schedulingMode: 'dynamic' })
		})

		it('sends at least an interval of one', () => {
			expect(rhythmFields(rhythm({ every: '' })).frequencyValue).toBe(1)
			expect(rhythmFields(rhythm({ every: -2 })).frequencyValue).toBe(1)
		})

		it('round-trips a stored definition', () => {
			const stored = { recurrenceMode: 'recurring', schedulingMode: 'static', frequencyType: 'monthly', frequencyValue: 2, fixedScheduleDays: null, fixedMonthDay: 31, flexDays: 3 }

			expect(rhythmFields(rhythmOf(stored))).toEqual(stored)
		})
	})

	describe('flexCapOf and rhythmProblem', () => {
		it('caps the flex days at half the period, at the weekday gap of a fixed rule, and at seven once', () => {
			expect(flexCapOf(rhythm({ unit: 'day' }))).toBe(1)
			expect(flexCapOf(rhythm())).toBe(4)
			expect(flexCapOf(rhythm({ unit: 'month' }))).toBe(15)
			expect(flexCapOf(rhythm({ mode: 'fixed', weekdays: ['monday', 'wednesday'] }))).toBe(1)
			expect(flexCapOf(rhythm({ mode: 'once' }))).toBe(7)
		})

		it('keeps a stored value above the cap while the rule and the value are unchanged', () => {
			const stored = rhythm({ unit: 'day', flexDays: 3 })

			expect(flexCapOf(stored, stored)).toBe(3)
			expect(rhythmProblem(stored, stored)).toBeNull()
			expect(flexCapOf(rhythm({ unit: 'day', flexDays: 2 }), stored)).toBe(1)
			expect(rhythmProblem(rhythm({ unit: 'day', every: 2, flexDays: 3 }), stored)).toBe('flex')
		})

		it('names what keeps a rhythm from being saved', () => {
			expect(rhythmProblem(rhythm({ every: 0 }))).toBe('every')
			expect(rhythmProblem(rhythm({ every: '' }))).toBe('every')
			expect(rhythmProblem(rhythm({ mode: 'fixed', weekdays: [] }))).toBe('weekdays')
			expect(rhythmProblem(rhythm({ mode: 'fixed', unit: 'month', monthDay: null }))).toBe('monthDay')
			expect(rhythmProblem(rhythm({ mode: 'fixed', unit: 'month', monthDay: 32 }))).toBe('monthDay')
			expect(rhythmProblem(rhythm({ flexDays: -1 }))).toBe('flex')
			expect(rhythmProblem(rhythm({ flexDays: 5 }))).toBe('flex')
			expect(rhythmProblem(rhythm({ mode: 'fixed', unit: 'month', monthDay: 31, flexDays: 2 }))).toBeNull()
			expect(rhythmProblem(rhythm())).toBeNull()
		})
	})

	describe('detailsOf', () => {
		it('fills a new task with the defaults', () => {
			expect(detailsOf(null, () => 'other')).toEqual({
				name: '',
				description: '',
				instructions: '',
				energyCost: 2,
				priority: 2,
				category: 'other',
				visibilityType: 'public',
				isVoluntary: false,
			})
		})

		it('reads a stored definition and resolves its category key', () => {
			const category = (key) => `resolved:${key}`

			expect(detailsOf({ name: 'Mop', description: 'd', instructions: 'i', energyCost: 4, priority: 3, category: 'floor', visibilityType: 'private', isVoluntary: true }, category))
				.toEqual({ name: 'Mop', description: 'd', instructions: 'i', energyCost: 4, priority: 3, category: 'resolved:floor', visibilityType: 'private', isVoluntary: true })
		})
	})

	describe('formFields', () => {
		const state = (overrides = {}) => ({
			details: { ...detailsOf(null, () => null), name: '  Dishes  ' },
			rhythm: rhythm({ unit: 'day', flexDays: 0 }),
			roomId: 3,
			assigneeUserIds: ['mom'],
			subTasks: [{ id: 4, label: 'Rinse', key: 4 }, { label: 'Dry', key: 'new-1' }],
			firstDate: '2026-12-01',
			firstDateEditable: true,
			sleeping: false,
			wakeOn: '2026-12-24',
			...overrides,
		})

		it('sends the trimmed definition with its rhythm, the people and the sub-tasks without their keys', () => {
			const fields = formFields(state())

			expect(fields).toEqual({
				roomId: 3,
				task: expect.objectContaining({ name: 'Dishes', frequencyType: 'daily', recurrenceMode: 'recurring', flexDays: 0 }),
				assigneeUserIds: ['mom'],
				checklistItems: [{ id: 4, label: 'Rinse' }, { label: 'Dry' }],
				sleeping: false,
				firstDate: '2026-12-01',
			})
		})

		it('sends the rhythm of a task only when it changed, so a stored form it shows differently stays', () => {
			const stored = rhythm({ unit: 'day', flexDays: 0 })

			expect(formFields(state({ storedRhythm: stored })).task).not.toHaveProperty('frequencyType')
			expect(formFields(state({ storedRhythm: stored, rhythm: rhythm({ unit: 'day', flexDays: 1 }) })).task)
				.toMatchObject({ frequencyType: 'daily', flexDays: 1 })
		})

		it('reads a legacy custom type as every week, as the windows do', () => {
			expect(rhythmOf({ frequencyType: 'custom', frequencyValue: 3, schedulingMode: 'dynamic' })).toMatchObject({ every: 1, unit: 'week' })
		})

		it('sends a copy of the assignee list', () => {
			const assignees = ['mom']
			const fields = formFields(state({ assigneeUserIds: assignees }))
			assignees.push('dad')

			expect(fields.assigneeUserIds).toEqual(['mom'])
		})

		it('sends the first date only while it can still move the window, and none when empty', () => {
			expect(formFields(state({ firstDateEditable: false }))).not.toHaveProperty('firstDate')
			expect(formFields(state({ firstDate: '' }))).not.toHaveProperty('firstDate')
		})

		it('sends the first date of a task only when it differs from the one the task has', () => {
			expect(formFields(state({ storedFirstDate: '2026-12-01' }))).not.toHaveProperty('firstDate')
			expect(formFields(state({ storedFirstDate: '2026-11-20' })).firstDate).toBe('2026-12-01')
		})

		it('sends of an existing definition only the details the member changed (D-091-100)', () => {
			const stored = { ...detailsOf({ name: 'Dishes', category: 'bathroom', energyCost: null, description: null }, () => 'other') }
			const same = { details: { ...stored }, storedDetails: stored, storedRhythm: rhythm({ unit: 'day', flexDays: 0 }) }

			// Nothing changed: a deleted category shown as Other and legacy defaults stay as stored.
			expect(formFields(state(same)).task).toEqual({})
			expect(formFields(state({ ...same, details: { ...stored, name: ' Wash up ', category: 'kitchen' } })).task)
				.toEqual({ name: 'Wash up', category: 'kitchen' })
			expect(formFields(state({ ...same, details: { ...stored, isVoluntary: true } })).task).toEqual({ isVoluntary: true })
		})

		it('keeps a bounded task bounded when its rhythm changes', () => {
			const fields = formFields(state({ storedRhythm: rhythm({ unit: 'week' }), storedMode: 'bounded' }))

			expect(fields.task).toMatchObject({ recurrenceMode: 'bounded', frequencyType: 'daily' })
		})

		it('sends the wake date only with sleeping', () => {
			expect(formFields(state({ sleeping: true }))).toMatchObject({ sleeping: true, wakeOn: '2026-12-24' })
			expect(formFields(state({ sleeping: true, wakeOn: '' }))).not.toHaveProperty('wakeOn')
		})
	})

	describe('templateFill', () => {
		const template = {
			name: 'Clean the sink',
			description: 'Sink and tap',
			instructions: 'Use the soft sponge',
			energyCost: 3,
			priority: 1,
			category: 'kitchen',
			levels: {
				putzteufel: { frequencyType: 'daily', frequencyValue: 2 },
				entspannt: { frequencyType: 'weekly', frequencyValue: 1 },
			},
		}

		it('fills the details and the rhythm of the named level, after completion', () => {
			expect(templateFill(template, 'putzteufel')).toEqual({
				details: { name: 'Clean the sink', description: 'Sink and tap', instructions: 'Use the soft sponge', energyCost: 3, priority: 1, category: 'kitchen' },
				rhythm: { mode: 'after', every: 2, unit: 'day', weekdays: [], monthDay: null, flexDays: 1 },
			})
		})

		it('takes the first level when the named one is missing, and defaults without levels', () => {
			expect(templateFill(template, 'unknown').rhythm).toMatchObject({ every: 2, unit: 'day' })
			expect(templateFill({ name: 'Bare' }, 'putzteufel')).toEqual({
				details: { name: 'Bare', description: '', instructions: '', energyCost: 2, priority: 2, category: null },
				rhythm: rhythmOf(null),
			})
		})
	})
})
