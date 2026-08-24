/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/**
 * Canonical weekday keys in UI order (Monday-start, matching the data model).
 */
export const WEEKDAY_KEYS = Object.freeze([
	'monday',
	'tuesday',
	'wednesday',
	'thursday',
	'friday',
	'saturday',
	'sunday',
])

/**
 * Mirrors SchedulingService::getDefaultEnergyBudget — mon-fri 10, sat/sun 15.
 * Single source for every client-side energy fallback (TECH-DRY-001).
 */
export const DEFAULT_ENERGY_BUDGET = Object.freeze({
	monday: 10,
	tuesday: 10,
	wednesday: 10,
	thursday: 10,
	friday: 10,
	saturday: 15,
	sunday: 15,
})

/**
 * Fresh mutable copy of the default energy budget (store/component state).
 *
 * @return {object} Budget map keyed by weekday
 */
export function createDefaultEnergyBudget() {
	return { ...DEFAULT_ENERGY_BUDGET }
}

/**
 * Resolve one day's energy from a budget map with the canonical fallback.
 *
 * Unknown days fall back to 10 to match the historical flat default.
 *
 * @param {object|undefined} budget Budget map keyed by weekday
 * @param {string} dayKey Weekday key
 * @return {number} Energy value for the day
 */
export function resolveEnergyForDay(budget, dayKey) {
	return budget?.[dayKey] ?? DEFAULT_ENERGY_BUDGET[dayKey] ?? 10
}
