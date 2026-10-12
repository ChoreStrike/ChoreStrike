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
 * Whether the room task's occurrence is in the past (active tasks only).
 * @param {object} roomTask Room task payload with nextDue/isActive.
 * @return {boolean}
 */
export const isOverdue = (roomTask) => {
	if (!roomTask.isActive) return false
	return new Date(roomTask.nextDue) < new Date()
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
 * Due-date badge class; "today" is bucketed in the house timezone
 * (CS-PLAN-057).
 * @param {object} roomTask Room task payload.
 * @param {string} timezone IANA timezone of the household.
 * @return {string}
 */
export const dueClass = (roomTask, timezone) => {
	if (!roomTask.isActive) return 'completed'
	// House-local day bucketing (CS-PLAN-057) takes precedence over the raw
	// instant: a task due later TODAY is not overdue after its 00:00
	// timestamp has passed — the same "Due today" semantics dueText uses
	// (CS-PLAN-068 §3.4 date-boundary fix).
	if (dateKeyInZone(roomTask.nextDue, timezone) === todayKeyInZone(timezone)) return 'today'
	const due = new Date(roomTask.nextDue)
	const now = new Date()
	if (due < now) return 'overdue'
	return 'upcoming'
}

/**
 * Localized due-date text ("Overdue by …", "Due today", …).
 * @param {object} roomTask Room task payload.
 * @return {string}
 */
export const dueText = (roomTask) => {
	if (!roomTask.isActive) return t('chorestrike', 'Completed')
	const due = new Date(roomTask.nextDue)
	const now = new Date()
	const diffDays = Math.ceil((due - now) / (1000 * 60 * 60 * 24))
	if (diffDays < 0) return t('chorestrike', 'Overdue by {days} days', { days: Math.abs(diffDays) })
	if (diffDays === 0) return t('chorestrike', 'Due today')
	if (diffDays === 1) return t('chorestrike', 'Due tomorrow')
	return t('chorestrike', 'Due in {days} days', { days: diffDays })
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
