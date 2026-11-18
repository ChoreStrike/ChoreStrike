/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/**
 * Pure display helpers for the TaskList row surface (CS-PLAN-067 §3.4):
 * priority/frequency/energy/due-date presentation, shared by the task rows
 * and their unit tests. No store access — context (e.g. the
 * house timezone) is passed in by the caller.
 */

import { translate as t } from '@nextcloud/l10n'
import { ENERGY_LISTED_MAX, ENERGY_LONGER, ENERGY_MAX } from '../constants/scheduling.js'
import { dateKeyInZone, formatDayKey, todayKeyInZone } from './dateKeys.js'

/**
 * Visible assignment names, including the unassigned state.
 * @param {string[]} userIds Assigned user identifiers.
 * @param {object[]} members Loaded household members.
 * @return {string}
 */
export function assignmentLabel(userIds = [], members = []) {
	const ids = userIds ?? []
	if (ids.length === 0) {
		return t('chorestrike', 'Unassigned')
	}
	const namesById = new Map(members.map((member) => [member.nextcloudUserId, member.displayName]))
	const names = ids.map((id) => namesById.get(id) || id)
	return t('chorestrike', 'Assigned to: {names}', { names: names.join(', ') })
}

/**
 * The `cycle` block of a row (ADR-021): on the row itself in the My Day
 * reads, inside the room task in the task lists.
 * @param {?object} item Enriched row or flat room task payload.
 * @return {object|null|undefined} Undefined when the read carried none.
 */
export function cycleOf(item) {
	return item?.cycle !== undefined ? item.cycle : item?.roomTask?.cycle
}

/**
 * The house-local day a room task is planned for: the planned day of its
 * current cycle. Empty for a task without a window (bonus, asleep, done);
 * a row delivered without a cycle block falls back to the projected due
 * instant.
 * @param {object} item Enriched row or flat room task payload.
 * @param {string} timezone IANA timezone of the household.
 * @return {string} `Y-m-d` or ''.
 */
export function plannedDayKey(item, timezone) {
	const cycle = cycleOf(item)
	if (cycle !== undefined) {
		return cycle?.centreOn ?? ''
	}
	return dateKeyInZone((item?.roomTask ?? item)?.nextDue, timezone)
}

/**
 * Whether the room task's current work is missed (CS-PLAN-084 D-084-08):
 * the server's cycle state is authoritative (`overdue` = the window ended
 * in the house timezone); rows without a cycle block fall back to the
 * server-computed status. A task due today is never overdue before the
 * house-local day is over.
 * @param {object} roomTask Room task payload with isActive/cycle/status.
 * @return {boolean}
 */
export function isOverdue(roomTask) {
	if (!roomTask?.isActive) {
		return false
	}
	if (roomTask.cycle) {
		return roomTask.cycle.state === 'overdue'
	}
	return roomTask.status === 'overdue'
}

/**
 * Priority badge class for the stored 1..3 priority.
 * @param {number} priority Stored task priority.
 * @return {string}
 */
export function priorityClass(priority) {
	return { 1: 'low', 2: 'medium', 3: 'high' }[priority] || 'medium'
}

/**
 * Localized priority label for the stored 1..3 priority.
 * @param {number} priority Stored task priority.
 * @return {string}
 */
export function priorityLabel(priority) {
	return { 1: t('chorestrike', 'Low'), 2: t('chorestrike', 'Normal'), 3: t('chorestrike', 'High') }[priority]
}

/**
 * Localized recurrence label ("Every {n} days" for n > 1).
 * @param {object} task Task definition with frequencyType/frequencyValue.
 * @return {string}
 */
export function frequencyLabel(task) {
	const labels = {
		daily: task.frequencyValue > 1 ? t('chorestrike', 'Every {n} days', { n: task.frequencyValue }) : t('chorestrike', 'Daily'),
		weekly: task.frequencyValue === 1 ? t('chorestrike', 'Weekly') : t('chorestrike', 'Every {n} weeks', { n: task.frequencyValue }),
		monthly: task.frequencyValue === 1 ? t('chorestrike', 'Monthly') : t('chorestrike', 'Every {n} months', { n: task.frequencyValue }),
		custom: t('chorestrike', 'Custom'),
	}
	return labels[task.frequencyType] || task.frequencyType
}

/**
 * How often a task repeats, with its flex days in one label: "Every 3 days ±2".
 * @param {object} task Task definition.
 * @return {string}
 */
export function recurrenceLabel(task) {
	if (task.recurrenceMode === 'once') {
		return t('chorestrike', 'One-time')
	}
	const label = frequencyLabel(task)
	return task.flexDays > 0 ? `${label} ±${task.flexDays}` : label
}

/**
 * Floor and room of a task, for the line below its title.
 * @param {?object} room Room of the task.
 * @param {?object} floor Floor of that room.
 * @return {string} Empty while neither is known.
 */
export function locationLabel(room, floor) {
	return [floor?.name, room?.name].filter(Boolean).join(' · ')
}

/**
 * The energy of a row: what completing it pays. Open work follows the
 * definition (ADR-021 decision 1), completed work keeps its value (ADR-013).
 * @param {object} item Enriched row with its task.
 * @return {number}
 */
export function taskEnergy(item) {
	return item.task.energyCost
}

/**
 * A count of days as a label: "1 day", "4 days".
 * @param {number} days Whole days.
 * @return {string}
 */
export function daysLabel(days) {
	return days === 1 ? t('chorestrike', '1 day') : t('chorestrike', '{days} days', { days })
}

/**
 * The choice for a task above the listed energies.
 * @return {string}
 */
export function longerTaskLabel() {
	return t('chorestrike', 'Longer task ({min} to {max})', { min: ENERGY_LISTED_MAX + 1, max: ENERGY_MAX })
}

/**
 * Whether an energy fits the task list's filter.
 * @param {number} energy Energy of the row.
 * @param {string} filter A listed energy, or ENERGY_LONGER.
 * @return {boolean}
 */
export function matchesEnergyFilter(energy, filter) {
	return filter === ENERGY_LONGER ? energy > ENERGY_LISTED_MAX : energy === Number(filter)
}

/**
 * Whole house-local days from today to a day key (negative = past).
 * @param {string} dayKey `Y-m-d`, or '' for none.
 * @param {string} timezone IANA timezone of the household.
 * @return {?number} null without a day
 */
function dayOffset(dayKey, timezone) {
	if (!dayKey) {
		return null
	}
	const todayKey = todayKeyInZone(timezone)
	return Math.round((Date.parse(`${dayKey}T00:00:00Z`) - Date.parse(`${todayKey}T00:00:00Z`)) / 86400000)
}

/**
 * Whole house-local days from today to the planned day (negative = past).
 * @param {object} roomTask Room task payload with its cycle block.
 * @param {string} timezone IANA timezone of the household.
 * @return {?number} null without a planned day
 */
function dueDayOffset(roomTask, timezone) {
	return dayOffset(plannedDayKey(roomTask, timezone), timezone)
}

/**
 * Due-date badge class; "today" is bucketed in the house timezone
 * (CS-PLAN-057).
 * @param {object} roomTask Room task payload.
 * @param {string} timezone IANA timezone of the household.
 * @return {string}
 */
export function dueClass(roomTask, timezone) {
	if (!roomTask.isActive) {
		return 'completed'
	}
	if (isOverdue(roomTask)) {
		return 'overdue'
	}
	// House-local day bucketing (CS-PLAN-057): today — or a past planned day
	// whose flex window is still open — is due, not overdue.
	const days = dueDayOffset(roomTask, timezone)
	return days !== null && days <= 0 ? 'today' : 'upcoming'
}

/**
 * Localized due-date text ("Overdue by …", "Due today", …) in house-local
 * days (CS-PLAN-084 D-084-08).
 * @param {object} roomTask Room task payload.
 * @param {string} timezone IANA timezone of the household.
 * @return {string}
 */
export function dueText(roomTask, timezone) {
	if (!roomTask.isActive) {
		return t('chorestrike', 'Completed')
	}
	// A sleeping task and a bonus task have no window (ADR-021 decisions 7 and 8).
	if (roomTask.cycle?.state === 'asleep') {
		return roomTask.cycle.wakeOn
			? t('chorestrike', 'Asleep until {date}', { date: formatDayKey(roomTask.cycle.wakeOn) })
			: t('chorestrike', 'Asleep')
	}
	if (roomTask.cycle?.state === 'bonus') {
		return t('chorestrike', 'Bonus task')
	}
	const days = dueDayOffset(roomTask, timezone)
	if (days === null) {
		return t('chorestrike', 'No due date')
	}
	if (isOverdue(roomTask)) {
		// Counted from the end of the window, not from the planned day.
		const overdueDays = Math.max(1, -(dayOffset(roomTask.cycle?.windowEndOn ?? '', timezone) ?? days))
		return overdueDays === 1
			? t('chorestrike', 'Overdue by 1 day')
			: t('chorestrike', 'Overdue by {days} days', { days: overdueDays })
	}
	if (days <= 0) {
		return t('chorestrike', 'Due today')
	}
	if (days === 1) {
		return t('chorestrike', 'Due tomorrow')
	}
	return t('chorestrike', 'Due in {days} days', { days })
}

/**
 * The groupings of the task list, for its switch.
 * @return {Array<{value: string, label: string}>}
 */
export function groupingOptions() {
	return [
		{ value: 'due', label: t('chorestrike', 'By due date') },
		{ value: 'room', label: t('chorestrike', 'By room') },
		{ value: 'person', label: t('chorestrike', 'By person') },
	]
}

/**
 * List order for enriched rows: the earliest end of a window first (the
 * server's `nextDue` projection; rows without a window last), then higher
 * priority first. Shared by TaskList and the paged TaskManager list so a
 * page always holds the next rows of the visible order.
 * @param {object} a Enriched row with roomTask and task.
 * @param {object} b Enriched row with roomTask and task.
 * @return {number}
 */
export function compareTaskItems(a, b) {
	const dueA = sortInstant(a.roomTask)
	const dueB = sortInstant(b.roomTask)
	if (dueA !== dueB) {
		return dueA < dueB ? -1 : 1
	}
	return (b.task.priority ?? 0) - (a.task.priority ?? 0)
}

/**
 * @param {object} roomTask Room task payload.
 * @return {number} The projected end of its window, Infinity without one.
 */
function sortInstant(roomTask) {
	const windowless = roomTask.cycle !== undefined && !roomTask.cycle?.windowEndOn
	return windowless || !roomTask.nextDue ? Infinity : new Date(roomTask.nextDue).getTime()
}
