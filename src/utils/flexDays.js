/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/**
 * The flex-window cap of a task rule (FEAT-UX-039, CS-PLAN-088 D-088-12):
 * at most half the recurrence period, rounded up. Mirrors
 * `lib/Service/FlexDaysPolicy.php`; the server stays the authority.
 */

/** Hard sanity bound of any flex value (days). */
export const FLEX_DAYS_SANITY_MAX = 366

/** Bound of a one-time task, which has no period. */
export const FLEX_DAYS_ONE_TIME_MAX = 7

/** Early completion of a fixed schedule is bounded by the 7-day agreement horizon. */
export const FIXED_SCHEDULE_EARLY_DAYS = 7

const UNIT_DAYS = { daily: 1, weekly: 7, monthly: 30 }
const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']

/**
 * The smallest circular gap between weekdays within one cycle.
 * @param {string[]} weekdays Weekday names.
 * @param {number} cycleDays Cycle length in days.
 * @return {number}
 */
function smallestWeekdayGap(weekdays, cycleDays) {
	const offsets = [...new Set(weekdays.map((day) => WEEKDAYS.indexOf(String(day).toLowerCase())).filter((offset) => offset >= 0))]
		.sort((a, b) => a - b)
	if (offsets.length < 2) {
		return cycleDays
	}
	let gap = offsets[0] + cycleDays - offsets[offsets.length - 1]
	for (let i = 1; i < offsets.length; i++) {
		gap = Math.min(gap, offsets[i] - offsets[i - 1])
	}
	return gap
}

/**
 * The recurrence period in days.
 * @param {object} rule Rule inputs.
 * @param {string} rule.frequencyType daily | weekly | monthly | custom.
 * @param {number} rule.frequencyValue Interval count.
 * @param {string} [rule.schedulingMode] static weekdays shape the period.
 * @param {string[]} [rule.weekdays] Weekdays of a fixed weekly rule.
 * @return {number}
 */
export function flexPeriodDays({ frequencyType, frequencyValue, schedulingMode, weekdays = [] }) {
	if (frequencyType === 'custom') {
		return 7
	}
	const period = (UNIT_DAYS[frequencyType] ?? 7) * Math.max(1, Number.parseInt(frequencyValue, 10) || 1)
	if (frequencyType !== 'weekly' || schedulingMode !== 'static') {
		return period
	}
	return smallestWeekdayGap(weekdays, period)
}

/**
 * The largest flex value for the rule inputs.
 * @param {object} rule Rule inputs (see flexPeriodDays) plus `recurrenceMode`.
 * @return {number}
 */
export function maxFlexDays(rule) {
	if (rule.recurrenceMode === 'once') {
		return FLEX_DAYS_ONE_TIME_MAX
	}
	return Math.min(FLEX_DAYS_SANITY_MAX, Math.ceil(flexPeriodDays(rule) / 2))
}
