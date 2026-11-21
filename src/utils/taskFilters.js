/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/**
 * The filters of the task list as pure functions: the values a `/tasks`
 * link presets, and the rows they leave. No store access — the view passes
 * what the rules need.
 */

import { DEFAULT_CATEGORY_LIST, resolveCategory } from './taskCategories.js'
import { dueClass, isOverdue, matchesEnergyFilter, taskEnergy } from './taskDisplay.js'
import { groupingFromQuery } from './taskGrouping.js'

// Status filter values a `/tasks?status=` link may preset.
const STATUS_PRESETS = ['active', 'overdue', 'due-today']
const PRIORITIES = { urgent: 3, normal: 2, low: 1 }

/**
 * Initial filters from the router contract (`docs/concepts/ui-use-cases.md`):
 * `?visibility=private` (CS-PLAN-041), `?scope=`, `?status=` (CS-PLAN-084
 * WP-C), `?category=` (CS-PLAN-090 WP-I); unknown values keep the
 * defaults. A grouped list starts with everyone's tasks unless the link
 * names a scope (CS-PLAN-090 D-090-41).
 * @param {object} query Route query.
 * @return {object} Filter values for TaskFilterBar.
 */
export function filtersFromQuery(query = {}) {
	const filters = { scope: 'mine', people: [], unassigned: true, floor: '', room: '', status: 'all', priority: 'all', difficulty: 'all', visibility: 'all', category: 'all', search: '' }
	// A key shape only: the list arrives with the house, after this preset.
	if (typeof query.category === 'string' && /^[a-z0-9]{1,50}$/.test(query.category)) {
		filters.category = query.category
	}
	if (query.visibility === 'private') {
		filters.visibility = 'private'
		filters.scope = 'all'
	}
	if (groupingFromQuery(query.group) !== 'due') {
		filters.scope = 'all'
	}
	if (['mine', 'unassigned', 'all'].includes(query.scope)) {
		filters.scope = query.scope
	}
	if (STATUS_PRESETS.includes(query.status)) {
		filters.status = query.status
	}
	return filters
}

/**
 * Page number from `?page=` (1 when missing or invalid).
 * @param {unknown} value Raw query value.
 * @return {number}
 */
export function pageFromQuery(value) {
	const page = Number.parseInt(value, 10)
	return Number.isInteger(page) && page > 0 ? page : 1
}

/**
 * What a scope ticks in the people filter: the three presets, or the
 * custom choice the filters hold.
 * @param {{scope: string, people?: string[], unassigned?: boolean, actorId?: ?string}} filters Scope and custom choice, with the viewer.
 * @param {string[]} memberIds The household's members.
 * @return {{people: string[], unassigned: boolean}}
 */
export function peopleOfScope(filters, memberIds) {
	if (filters.scope === 'custom') {
		return { people: filters.people ?? [], unassigned: filters.unassigned === true }
	}
	if (filters.scope === 'all') {
		return { people: memberIds, unassigned: true }
	}
	return { people: filters.scope === 'mine' && filters.actorId ? [filters.actorId] : [], unassigned: true }
}

/**
 * The filter values of a people choice: a preset where it is one, so the
 * scope buttons and the ticks always say the same.
 * @param {string[]} people The ticked members.
 * @param {boolean} unassigned Whether unassigned work is ticked.
 * @param {string[]} memberIds The household's members.
 * @param {?string} actorId The viewer.
 * @return {{scope: string, people: string[], unassigned: boolean}}
 */
export function scopeOfPeople(people, unassigned, memberIds, actorId) {
	const preset = { people: [], unassigned: true }
	if (unassigned && memberIds.length > 0 && memberIds.every((id) => people.includes(id))) {
		return { scope: 'all', ...preset }
	}
	if (unassigned && people.length === 0) {
		return { scope: 'unassigned', ...preset }
	}
	if (unassigned && people.length === 1 && people[0] === actorId) {
		return { scope: 'mine', ...preset }
	}
	return { scope: 'custom', people: [...people], unassigned }
}

/**
 * Whether a row passes the scope (with a custom choice of people), floor
 * and room filters.
 * @param {object} roomTask Room task payload.
 * @param {object} filters Filter values.
 * @param {{rooms: object[], actorId: ?string}} context Rooms of the house and the viewer.
 * @return {boolean}
 */
function matchesScope(roomTask, filters, { rooms, actorId }) {
	const { scope, floor, room } = filters
	const assignees = roomTask.assignees ?? []
	// Unknown user (identity not resolved yet): show assigned rows rather
	// than an empty list, as Today does.
	if (scope === 'mine' && actorId && assignees.length > 0 && !assignees.includes(actorId)) {
		return false
	}
	if (scope === 'unassigned' && assignees.length > 0) {
		return false
	}
	if (scope === 'custom' && !(assignees.length === 0 ? filters.unassigned === true : assignees.some((id) => (filters.people ?? []).includes(id)))) {
		return false
	}
	if (floor && !rooms.some((entry) => entry.id === roomTask.roomId && String(entry.floorId) === floor)) {
		return false
	}
	return !room || String(roomTask.roomId) === room
}

/**
 * The rows the filters leave, unsorted.
 * @param {object[]} items Enriched rows `{ roomTask, task }`.
 * @param {object} filters Filter values of TaskFilterBar.
 * @param {{rooms: object[], actorId: ?string, timezone: string, categories?: object[]}} context Rooms, viewer, house timezone and the household's category list.
 * @return {object[]}
 */
export function filterTaskItems(items, filters, context) {
	const { status, priority, difficulty, visibility, category = 'all', search } = filters
	const categories = context.categories ?? DEFAULT_CATEGORY_LIST.items
	const query = search.trim().toLowerCase()
	return items.filter((item) => {
		const { roomTask, task } = item
		if (!matchesScope(roomTask, filters, context)) {
			return false
		}
		if (status === 'active' && !roomTask.isActive) {
			return false
		}
		// The row badge's rules: missed work, and due today or an open flex
		// window in the house timezone (D-084-08).
		if (status === 'overdue' && !isOverdue(roomTask)) {
			return false
		}
		if (status === 'due-today' && !(roomTask.nextDue && dueClass(roomTask, context.timezone) === 'today')) {
			return false
		}
		if (priority !== 'all' && task.priority !== PRIORITIES[priority]) {
			return false
		}
		if (difficulty !== 'all' && !matchesEnergyFilter(taskEnergy(item), difficulty)) {
			return false
		}
		// "private" scopes the list to the viewer's private definitions (CS-PLAN-041).
		if (visibility === 'private' && task.visibilityType !== 'private') {
			return false
		}
		// A key the household deleted counts as Other (D-090-65).
		if (category !== 'all' && resolveCategory(task.category, categories) !== category) {
			return false
		}
		return !query || Boolean(task.name?.toLowerCase().includes(query))
	})
}
