/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { VISIBILITY_PUBLIC } from '../constants/taskVisibility.js'
import { maxFlexDays } from './flexDays.js'

/**
 * The model of the one form (CS-PLAN-091 WP-B6, FEAT-UX-040): what it
 * shows of a task, and the fields it sends. Pure functions, so the
 * component holds only state and the tests need no mount.
 *
 * A rhythm is `{ mode, every, unit, weekdays, monthDay, flexDays }`:
 * `mode` is `after` (after completion), `fixed` (a fixed weekday or day of
 * the month) or `once`; `unit` is `day`, `week` or `month`.
 */

const UNIT_OF_TYPE = { daily: 'day', weekly: 'week', monthly: 'month', custom: 'week' }
const TYPE_OF_UNIT = { day: 'daily', week: 'weekly', month: 'monthly' }

/**
 * The weekday list of a stored `fixedScheduleDays` (JSON text or a list).
 * @param {?(string|string[])} value The stored value.
 * @return {string[]}
 */
export function parseWeekdays(value) {
	if (!value) {
		return []
	}
	try {
		const parsed = typeof value === 'string' ? JSON.parse(value) : value
		return Array.isArray(parsed) ? parsed.map(String) : []
	} catch {
		return []
	}
}

/**
 * The rhythm a definition shows in the form.
 * @param {?object} task The definition, or null for a new task.
 * @return {object}
 */
export function rhythmOf(task) {
	if (!task) {
		return { mode: 'after', every: 1, unit: 'week', weekdays: [], monthDay: null, flexDays: 1 }
	}
	const mode = task.recurrenceMode === 'once' ? 'once' : (task.schedulingMode === 'static' ? 'fixed' : 'after')
	return {
		mode,
		// A stored `custom` type repeats every week, whatever its value says.
		every: task.frequencyType === 'custom' ? 1 : (task.frequencyValue || 1),
		unit: UNIT_OF_TYPE[task.frequencyType] ?? 'week',
		weekdays: parseWeekdays(task.fixedScheduleDays),
		monthDay: task.fixedMonthDay ?? null,
		flexDays: task.flexDays ?? 0,
	}
}

/**
 * The definition fields of a rhythm. A fixed rule is a weekday list for
 * weeks and a day of the month for months; anything a mode does not use is
 * sent empty, so a changed mode leaves nothing of the old one behind. The
 * form knows no bounded rule: a repeating rhythm of a task stored as
 * `bounded` stays bounded.
 * @param {object} rhythm The form's rhythm.
 * @param {?string} [storedMode] The `recurrenceMode` the task has, when editing.
 * @return {object}
 */
export function rhythmFields(rhythm, storedMode = null) {
	const fixed = rhythm.mode === 'fixed'
	return {
		recurrenceMode: rhythm.mode === 'once' ? 'once' : (storedMode === 'bounded' ? 'bounded' : 'recurring'),
		schedulingMode: fixed ? 'static' : 'dynamic',
		frequencyType: TYPE_OF_UNIT[rhythm.unit] ?? 'weekly',
		frequencyValue: Math.max(1, Number.parseInt(rhythm.every, 10) || 1),
		fixedScheduleDays: fixed && rhythm.unit === 'week' ? JSON.stringify(rhythm.weekdays) : null,
		fixedMonthDay: fixed && rhythm.unit === 'month' ? rhythm.monthDay : null,
		flexDays: rhythm.flexDays,
	}
}

/**
 * The flex days the rhythm allows (FEAT-UX-039). A stored value above the
 * cap stays allowed while the rule and the flex value are unchanged.
 * @param {object} rhythm The form's rhythm.
 * @param {?object} [stored] The rhythm the task has, when editing.
 * @return {number}
 */
export function flexCapOf(rhythm, stored = null) {
	const fields = rhythmFields(rhythm)
	const cap = maxFlexDays({ ...fields, weekdays: rhythm.weekdays })
	const unchanged = stored && JSON.stringify(rhythmFields(stored)) === JSON.stringify(fields)
	return unchanged ? Math.max(cap, rhythm.flexDays) : cap
}

/**
 * What keeps a rhythm from being saved, or null.
 * @param {object} rhythm The form's rhythm.
 * @param {?object} [stored] The rhythm the task has, when editing.
 * @return {?string} `every`, `weekdays`, `monthDay` or `flex`
 */
export function rhythmProblem(rhythm, stored = null) {
	if (!(Number.parseInt(rhythm.every, 10) >= 1)) {
		return 'every'
	}
	if (rhythm.mode === 'fixed' && rhythm.unit === 'week' && rhythm.weekdays.length === 0) {
		return 'weekdays'
	}
	if (rhythm.mode === 'fixed' && rhythm.unit === 'month' && !(rhythm.monthDay >= 1 && rhythm.monthDay <= 31)) {
		return 'monthDay'
	}
	return rhythm.flexDays < 0 || rhythm.flexDays > flexCapOf(rhythm, stored) ? 'flex' : null
}

/**
 * The descriptive part of a definition the form edits.
 * @param {?object} task The definition, or null for a new task.
 * @param {function(?string): string} category Resolves a stored category key.
 * @return {object}
 */
export function detailsOf(task, category) {
	return {
		name: task?.name ?? '',
		description: task?.description ?? '',
		instructions: task?.instructions ?? '',
		energyCost: task?.energyCost || 2,
		priority: task?.priority || 2,
		category: category(task?.category ?? null),
		visibilityType: task?.visibilityType || VISIBILITY_PUBLIC,
		isVoluntary: task?.isVoluntary === true,
	}
}

/**
 * The form's fields as the server takes them (`POST /room-tasks`,
 * `PUT /room-tasks/{id}`). A new task sends everything. An existing one
 * sends of its definition only what the member changed (D-091-100): the
 * details that differ from what the form opened with, and the rhythm when
 * it changed — so a stored value the form shows differently (a deleted
 * category as Other, a legacy `custom` type) stays as it is. A first date
 * goes only while it can still move the window and differs from the one
 * the task has; the wake date only with sleeping.
 * @param {object} state `{ details, storedDetails, rhythm, storedRhythm, storedMode, roomId, assigneeUserIds, subTasks, firstDate, storedFirstDate, firstDateEditable, sleeping, wakeOn }`; the `stored…` values are null for a new task.
 * @return {object}
 */
export function formFields(state) {
	const details = { ...state.details, name: state.details.name.trim() }
	const changed = state.storedDetails
		? Object.fromEntries(Object.entries(details).filter(([key, value]) => value !== state.storedDetails[key]))
		: details
	const rhythmChanged = !state.storedRhythm || JSON.stringify(state.rhythm) !== JSON.stringify(state.storedRhythm)
	const fields = {
		roomId: state.roomId,
		task: { ...changed, ...(rhythmChanged ? rhythmFields(state.rhythm, state.storedMode ?? null) : {}) },
		assigneeUserIds: [...state.assigneeUserIds],
		checklistItems: state.subTasks.map((entry) => (entry.id ? { id: entry.id, label: entry.label } : { label: entry.label })),
		sleeping: state.sleeping,
	}
	if (state.firstDateEditable && state.firstDate && state.firstDate !== (state.storedFirstDate ?? '')) {
		fields.firstDate = state.firstDate
	}
	if (state.sleeping && state.wakeOn) {
		fields.wakeOn = state.wakeOn
	}
	return fields
}

/**
 * What a catalog template fills in (FEAT-UX-043: it only fills the form).
 * @param {object} template A catalog template.
 * @param {string} level The cleaning level whose rhythm it takes.
 * @return {{details: object, rhythm: object}} Partial details and a rhythm.
 */
export function templateFill(template, level) {
	const levels = template.levels || {}
	const rule = levels[level] || Object.values(levels).find(Boolean) || {}
	return {
		details: {
			name: template.name || '',
			description: template.description || '',
			instructions: template.instructions || '',
			energyCost: template.energyCost || 2,
			priority: template.priority || 2,
			category: template.category || null,
		},
		rhythm: { ...rhythmOf(null), every: rule.frequencyValue || 1, unit: UNIT_OF_TYPE[rule.frequencyType] ?? 'week' },
	}
}
