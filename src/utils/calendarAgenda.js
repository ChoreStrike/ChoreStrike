/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { namesViewer } from './taskDisplay.js'

/**
 * A suggestion entry as the agenda shows it.
 * @param {object} entry An entry of the suggestion read.
 * @return {object}
 */
function suggestedEntry(entry) {
	return {
		key: `s-${entry.roomTaskId}-${entry.windowEndOn}`,
		name: entry.taskName,
		room: entry.roomName,
		energy: entry.energy,
		windowStartOn: entry.windowStartOn,
		windowEndOn: entry.windowEndOn,
		overload: entry.overload === true,
		userIds: entry.userIds ?? [],
	}
}

/**
 * A due window of the range read beyond the horizon, where nothing is
 * placed: it shows on its planned day.
 * @param {object} item A scheduled row of the range read.
 * @return {object}
 */
function dueEntry(item) {
	return {
		key: `r-${item.roomTask.id}-${item.dueDate}`,
		name: item.task.name,
		room: item.room?.name ?? '',
		energy: item.task.energyCost ?? 0,
		windowStartOn: item.windowStartOn ?? item.dueDate,
		windowEndOn: item.windowEndOn ?? item.dueDate,
		overload: false,
		userIds: item.assignees ?? [],
	}
}

/**
 * Overdue work that counts against today.
 * @param {object} entry An overdue entry of the suggestion read.
 * @return {object}
 */
function overdueEntry(entry) {
	return {
		key: `o-${entry.roomTaskId}`,
		name: entry.taskName,
		room: entry.roomName,
		energy: entry.energy,
		userIds: entry.userIds ?? [],
	}
}

/**
 * The week of the calendar as a list of days (CS-PLAN-091 WP-C). Inside
 * the horizon a day shows what the live suggestion places on it and the
 * viewer's energy; a past day shows what was done; a day beyond the
 * horizon shows the windows that are due, without a suggestion. The
 * server names the horizon with each suggestion (`horizonEndOn`); until
 * one is read no day lies inside or beyond it. Pure: the reads stay in
 * the stores.
 *
 * @param {object} input The reads and the viewer's choices.
 * @param {string[]} input.days The house-local day keys to show, ascending.
 * @param {string} input.todayKey Today's house-local key.
 * @param {{days: object, overdue: object[], loads: object, horizonEndOn: ?string}} input.suggestion The store's suggestion.
 * @param {object} input.rangeByDay The range read by day (`scheduled`, `completed`).
 * @param {?string} input.userId The viewer's user id.
 * @param {boolean} input.mineOnly Show only what names the viewer.
 * @return {Array<object>} One entry per day.
 */
export function buildAgendaDays({ days, todayKey, suggestion, rangeByDay, userId, mineOnly }) {
	const horizonEndKey = suggestion.horizonEndOn ?? null
	const shown = (userIds) => !mineOnly || namesViewer(userIds ?? [], userId)
	return days.map((day) => {
		const beyond = horizonEndKey !== null && day > horizonEndKey
		const inHorizon = horizonEndKey !== null && day >= todayKey && !beyond
		const range = rangeByDay[day] ?? { scheduled: [], completed: [] }
		let entries = []
		if (inHorizon) {
			entries = (suggestion.days[day] ?? []).filter((entry) => shown(entry.userIds)).map(suggestedEntry)
		} else if (beyond) {
			entries = range.scheduled.filter((item) => shown(item.assignees)).map(dueEntry)
		}
		return {
			day,
			isToday: day === todayKey,
			isPast: day < todayKey,
			beyond,
			load: inHorizon && userId ? (suggestion.loads[day]?.[userId] ?? null) : null,
			overdue: day === todayKey ? suggestion.overdue.filter((entry) => shown(entry.userIds)).map(overdueEntry) : [],
			entries,
			completed: day <= todayKey
				? range.completed.map((item, index) => ({ key: `c-${index}`, name: item.taskName, room: item.roomName }))
				: [],
		}
	})
}
