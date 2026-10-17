/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/**
 * Pure display helpers for the TaskList row surface (CS-PLAN-067 §3.4):
 * priority/frequency/due-date presentation and flex-window bounds, shared
 * by TaskListItem and its unit tests. No store access — context (e.g. the
 * house timezone) is passed in by the caller.
 */

import { translate as t } from '@nextcloud/l10n'
import { dateKeyInZone, todayKeyInZone } from './dateKeys.js'

/**
 * Visible assignment names, including the unassigned state.
 * @param {string[]} userIds Assigned user identifiers.
 * @param {object[]} members Loaded household members.
 * @return {string}
 */
export const assignmentLabel = (userIds = [], members = []) => {
	const ids = userIds ?? []
	if (ids.length === 0) return t('chorestrike', 'Unassigned')
	const namesById = new Map(members.map(member => [member.nextcloudUserId, member.displayName]))
	const names = ids.map(id => namesById.get(id) || id)
	return t('chorestrike', 'Assigned to: {names}', { names: names.join(', ') })
}

/**
 * Whether the room task's current work is missed (CS-PLAN-084 D-084-08):
 * the server's occurrence classification is authoritative (`late` = the
 * window ended in the house timezone); rows without an occurrence block
 * fall back to the server-computed status. A task due today is never
 * overdue before the house-local day is over.
 * @param {object} roomTask Room task payload with isActive/occurrence/status.
 * @return {boolean}
 */
export const isOverdue = (roomTask) => {
	if (!roomTask?.isActive) return false
	if (roomTask.occurrence) return roomTask.occurrence.classification === 'late'
	return roomTask.status === 'overdue'
}

/**
 * Priority badge class for the stored 1..3 priority.
 * @param {number} priority Stored task priority.
 * @return {string}
 */
export const priorityClass = (priority) => {
	return { 1: 'low', 2: 'medium', 3: 'high' }[priority] || 'medium'
}

/**
 * Localized priority label for the stored 1..3 priority.
 * @param {number} priority Stored task priority.
 * @return {string}
 */
export const priorityLabel = (priority) => {
	return { 1: t('chorestrike', 'Low'), 2: t('chorestrike', 'Normal'), 3: t('chorestrike', 'High') }[priority]
}

/**
 * Localized recurrence label ("Every {n} weeks" for n > 1).
 * @param {object} task Task definition with frequencyType/frequencyValue.
 * @return {string}
 */
export const frequencyLabel = (task) => {
	const labels = {
		daily: t('chorestrike', 'Daily'),
		weekly: task.frequencyValue === 1 ? t('chorestrike', 'Weekly') : t('chorestrike', 'Every {n} weeks', { n: task.frequencyValue }),
		monthly: task.frequencyValue === 1 ? t('chorestrike', 'Monthly') : t('chorestrike', 'Every {n} months', { n: task.frequencyValue }),
		custom: t('chorestrike', 'Custom'),
	}
	return labels[task.frequencyType] || task.frequencyType
}

/**
 * Whole house-local days from today to the planned due day (negative = past).
 * @param {object} roomTask Room task payload with nextDue.
 * @param {string} timezone IANA timezone of the household.
 * @return {?number} null without a due date
 */
const dueDayOffset = (roomTask, timezone) => {
	const dueKey = dateKeyInZone(roomTask.nextDue, timezone)
	if (dueKey === '') return null
	const todayKey = todayKeyInZone(timezone)
	return Math.round((Date.parse(`${dueKey}T00:00:00Z`) - Date.parse(`${todayKey}T00:00:00Z`)) / 86400000)
}

/**
 * Due-date badge class; "today" is bucketed in the house timezone
 * (CS-PLAN-057).
 * @param {object} roomTask Room task payload.
 * @param {string} timezone IANA timezone of the household.
 * @return {string}
 */
export const dueClass = (roomTask, timezone) => {
	if (!roomTask.isActive) return 'completed'
	if (isOverdue(roomTask)) return 'overdue'
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
export const dueText = (roomTask, timezone) => {
	if (!roomTask.isActive) return t('chorestrike', 'Completed')
	const days = dueDayOffset(roomTask, timezone)
	if (days === null) return t('chorestrike', 'No due date')
	if (isOverdue(roomTask)) {
		const overdueDays = Math.max(1, -days)
		return overdueDays === 1
			? t('chorestrike', 'Overdue by 1 day')
			: t('chorestrike', 'Overdue by {days} days', { days: overdueDays })
	}
	if (days <= 0) return t('chorestrike', 'Due today')
	if (days === 1) return t('chorestrike', 'Due tomorrow')
	return t('chorestrike', 'Due in {days} days', { days })
}

/**
 * Flex window bounds for the FlexWindowDisplay (earliest/ideal/latest).
 * @param {object} item Enriched row with roomTask and task.
 * @return {{earliest: ?string, ideal: ?string, latest: ?string}} ISO strings or null
 */
export const flexWindowKeys = (item) => {
	const ideal = item.roomTask.idealDueDate || item.roomTask.nextDue
	if (!ideal || item.task.flexDays <= 0) {
		return { earliest: null, ideal: ideal || null, latest: null }
	}

	const earliest = new Date(ideal)
	earliest.setDate(earliest.getDate() - item.task.flexDays)
	const latest = new Date(ideal)
	latest.setDate(latest.getDate() + item.task.flexDays)

	return {
		earliest: earliest.toISOString(),
		ideal,
		latest: latest.toISOString(),
	}
}

/**
 * List order for enriched rows: earliest due date first (rows without a due
 * date last), then higher priority first. Shared by TaskList and the paged
 * TaskManager list so a page always holds the next rows of the visible order.
 * @param {object} a Enriched row with roomTask and task.
 * @param {object} b Enriched row with roomTask and task.
 * @return {number}
 */
export const compareTaskItems = (a, b) => {
	const dueA = a.roomTask.nextDue ? new Date(a.roomTask.nextDue).getTime() : Infinity
	const dueB = b.roomTask.nextDue ? new Date(b.roomTask.nextDue).getTime() : Infinity
	if (dueA !== dueB) return dueA < dueB ? -1 : 1
	return (b.task.priority ?? 0) - (a.task.priority ?? 0)
}
