/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/** House-local days the live suggestion looks ahead, today included (ADR-022 decision 1). */
export const SUGGESTION_HORIZON_DAYS = 14

/**
 * Whether an entry is the viewer's: one that names them, or one that names
 * nobody (overdue work nobody is responsible for, unassigned work beyond
 * the suggestion).
 * @param {string[]} userIds The people an entry names.
 * @param {?string} userId The viewer.
 * @return {boolean}
 */
function isMine(userIds, userId) {
	return !userId || userIds.length === 0 || userIds.includes(userId)
}

/**
 * The week of the calendar as a list of days (CS-PLAN-091 WP-C). Inside
 * the horizon a day shows what the live suggestion places on it and the
 * viewer's energy; a past day shows what was done; a day beyond the
 * horizon shows the windows that are due, without a suggestion. Pure: the
 * reads stay in the stores.
 *
 * @param {object} input The reads and the viewer's choices.
 * @param {string[]} input.days The house-local day keys to show, ascending.
 * @param {string} input.todayKey Today's house-local key.
 * @param {string} input.horizonEndKey The last day the suggestion covers.
 * @param {{days: object, overdue: object[], loads: object}} input.suggestion The store's suggestion.
 * @param {object} input.rangeByDay The range read by day (`scheduled`, `completed`).
 * @param {?string} input.userId The viewer's user id.
 * @param {boolean} input.mineOnly Show only what names the viewer.
 * @return {Array<object>} One entry per day.
 */
export function buildAgendaDays({ days, todayKey, horizonEndKey, suggestion, rangeByDay, userId, mineOnly }) {
	const shown = (userIds) => !mineOnly || isMine(userIds ?? [], userId)
	return days.map((day) => {
		const inHorizon = day >= todayKey && day <= horizonEndKey
		const range = rangeByDay[day] ?? { scheduled: [], completed: [] }
		const suggested = (suggestion.days[day] ?? []).filter((entry) => shown(entry.userIds)).map((entry) => ({
			key: `s-${entry.roomTaskId}-${entry.windowEndOn}`,
			name: entry.taskName,
			room: entry.roomName,
			energy: entry.energy,
			windowStartOn: entry.windowStartOn,
			windowEndOn: entry.windowEndOn,
			overload: entry.overload === true,
			userIds: entry.userIds ?? [],
		}))
		// Beyond the horizon nothing is placed: the windows show on their planned day.
		const due = range.scheduled.filter((item) => shown(item.assignees)).map((item) => ({
			key: `r-${item.roomTask.id}-${item.dueDate}`,
			name: item.task.name,
			room: item.room?.name ?? '',
			energy: item.task.energyCost ?? 0,
			windowStartOn: item.windowStartOn ?? item.dueDate,
			windowEndOn: item.windowEndOn ?? item.dueDate,
			overload: false,
			userIds: item.assignees ?? [],
		}))
		return {
			day,
			isToday: day === todayKey,
			isPast: day < todayKey,
			beyond: day > horizonEndKey,
			load: inHorizon && userId ? (suggestion.loads[day]?.[userId] ?? null) : null,
			overdue: day === todayKey
				? suggestion.overdue.filter((entry) => shown(entry.userIds)).map((entry) => ({
						key: `o-${entry.roomTaskId}`,
						name: entry.taskName,
						room: entry.roomName,
						energy: entry.energy,
						userIds: entry.userIds ?? [],
					}))
				: [],
			entries: inHorizon ? suggested : (day > horizonEndKey ? due : []),
			completed: day <= todayKey
				? range.completed.map((item, index) => ({ key: `c-${index}`, name: item.taskName, room: item.roomName }))
				: [],
		}
	})
}
