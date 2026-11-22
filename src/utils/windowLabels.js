/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/**
 * How Today and the calendar name a due window (CS-PLAN-091 WP-C): the
 * live suggestion decides the day, so a row names its window instead of a
 * planned day. Pure; the house-local keys come from the caller.
 */

import { translate as t } from '@nextcloud/l10n'
import { daysBetweenKeys, formatDayKey, formatWeekdayKey } from './dateKeys.js'

/**
 * How long a window stays open, for a row of Today: "only today" on its
 * last day, else the day it ends — the weekday within a week, the date
 * beyond. Empty without a window or once it has ended.
 * @param {?string} endKey The window's last house-local day.
 * @param {string} todayKey Today's house-local key.
 * @return {string}
 */
export function windowEndLabel(endKey, todayKey) {
	const days = daysBetweenKeys(todayKey, endKey)
	if (days === null || days < 0) {
		return ''
	}
	if (days === 0) {
		return t('chorestrike', 'only today')
	}
	return t('chorestrike', 'until {day}', { day: days < 7 ? formatWeekdayKey(endKey) : formatDayKey(endKey) })
}

/**
 * The days of a window, for an entry of the calendar: "only Tue" for a
 * single day, else its first and last day — weekdays while the window is
 * shorter than a week, dates beyond.
 * @param {string} startKey The window's first house-local day.
 * @param {string} endKey The window's last house-local day.
 * @return {string}
 */
export function windowRangeLabel(startKey, endKey) {
	const days = daysBetweenKeys(startKey, endKey)
	if (days === null) {
		return ''
	}
	if (days <= 0) {
		return t('chorestrike', 'only {day}', { day: formatWeekdayKey(endKey) })
	}
	const label = days < 7 ? formatWeekdayKey : formatDayKey
	return `${label(startKey)}–${label(endKey)}`
}
