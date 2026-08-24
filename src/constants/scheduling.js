/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { translate as t } from '@nextcloud/l10n'
import { resolveEnergyForDay } from '../utils/energyBudgets.js'

/** Minimum allowed daily energy value (backend-enforced bound). */
export const ENERGY_MIN = 1

/** Maximum allowed daily energy value (backend-enforced bound). */
export const ENERGY_MAX = 20

/**
 * Translated weekday display metadata (UI order, Monday-first).
 */
export const weekDays = Object.freeze([
	{ key: 'monday', name: t('chorestrike', 'Monday'), short: t('chorestrike', 'Mon') },
	{ key: 'tuesday', name: t('chorestrike', 'Tuesday'), short: t('chorestrike', 'Tue') },
	{ key: 'wednesday', name: t('chorestrike', 'Wednesday'), short: t('chorestrike', 'Wed') },
	{ key: 'thursday', name: t('chorestrike', 'Thursday'), short: t('chorestrike', 'Thu') },
	{ key: 'friday', name: t('chorestrike', 'Friday'), short: t('chorestrike', 'Fri') },
	{ key: 'saturday', name: t('chorestrike', 'Saturday'), short: t('chorestrike', 'Sat') },
	{ key: 'sunday', name: t('chorestrike', 'Sunday'), short: t('chorestrike', 'Sun') },
])

/**
 * Whether a member is available on a weekday. Missing data means available.
 *
 * @param {object} member Member with an optional weekdayAvailability map
 * @param {string} dayKey Weekday key
 * @return {boolean} Availability flag
 */
export function isMemberAvailable(member, dayKey) {
	return member.weekdayAvailability?.[dayKey] !== false
}

/**
 * A member's energy budget for a weekday with the canonical fallback.
 *
 * @param {object} member Member with an optional energyBudget map
 * @param {string} dayKey Weekday key
 * @return {number} Energy value for the day
 */
export function getMemberEnergy(member, dayKey) {
	return resolveEnergyForDay(member.energyBudget, dayKey)
}
