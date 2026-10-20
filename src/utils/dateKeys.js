/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/**
 * House-timezone day-key helpers (CS-PLAN-057).
 *
 * Single day-key convention for the whole frontend: a calendar day key is
 * the `YYYY-MM-DD` of an instant **in the household's timezone** — the same
 * bucketing the backend performs (CalendarService). The former UTC-slice
 * (`.slice(0, 10)`) and browser-local (`localDateKey`) conventions are both
 * superseded by this module.
 */

const DAY_KEY_PATTERN = /^\d{4}-\d{2}-\d{2}$/

/**
 * Cache of Intl.DateTimeFormat instances keyed by timezone. Formatting is
 * on the hot path (store getters re-run on every state change), and
 * DateTimeFormat construction is expensive.
 *
 * @type {Map<string, Intl.DateTimeFormat>}
 */
const formatters = new Map()

/**
 * Get (and cache) the `en-CA` formatter for a timezone. `en-CA` yields
 * ISO-shaped `YYYY-MM-DD` output.
 *
 * @param {string} timeZone IANA timezone id
 * @return {Intl.DateTimeFormat}
 */
function formatterFor(timeZone) {
	let formatter = formatters.get(timeZone)
	if (!formatter) {
		formatter = new Intl.DateTimeFormat('en-CA', {
			timeZone,
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
		})
		formatters.set(timeZone, formatter)
	}
	return formatter
}

/**
 * `YYYY-MM-DD` day key of an instant in the given timezone.
 *
 * @param {string|number|Date} dateIso Instant to bucket (anything the
 * `Date` constructor accepts: ISO string, epoch millis, Date).
 * @param {string} timeZone IANA timezone id of the household.
 * @return {string} House-local `YYYY-MM-DD` key, or `''` for invalid input.
 */
export function dateKeyInZone(dateIso, timeZone) {
	if (dateIso === null || dateIso === undefined || dateIso === '') {
		return ''
	}
	const date = dateIso instanceof Date ? dateIso : new Date(dateIso)
	if (Number.isNaN(date.getTime())) {
		return ''
	}
	const zone = timeZone || 'UTC'
	try {
		return formatterFor(zone).format(date)
	} catch {
		// Unknown timezone id — fall back to UTC.
		return formatterFor('UTC').format(date)
	}
}

/**
 * Today's day key in the given timezone.
 *
 * @param {string} timeZone IANA timezone id of the household.
 * @return {string} House-local `YYYY-MM-DD` key of "now".
 */
export function todayKeyInZone(timeZone) {
	return dateKeyInZone(new Date(), timeZone)
}

/**
 * Shift a `YYYY-MM-DD` key by whole calendar days (DST-free: pure calendar
 * arithmetic on the key's date parts, never wall-clock instants).
 *
 * Used for "next 7 days" windows whose keys must align with the
 * house-local calendar keys rather than the browser's.
 *
 * @param {string} dateKey House-local `YYYY-MM-DD` key.
 * @param {number} days Days to add (negative allowed).
 * @return {string} Shifted `YYYY-MM-DD` key, or `''` for invalid input.
 */
export function addDaysToKey(dateKey, days) {
	if (!DAY_KEY_PATTERN.test(dateKey || '')) {
		return ''
	}
	const [year, month, day] = dateKey.split('-').map(Number)
	const shifted = new Date(Date.UTC(year, month - 1, day + days))
	return `${shifted.getUTCFullYear()}-${String(shifted.getUTCMonth() + 1).padStart(2, '0')}-${String(shifted.getUTCDate()).padStart(2, '0')}`
}

/**
 * Weekday key (`sunday`..`saturday`) of a `YYYY-MM-DD` key.
 *
 * @param {string} dateKey House-local `YYYY-MM-DD` key.
 * @return {string|null} Lowercase weekday name, or null for invalid input.
 */
export function weekdayOfKey(dateKey) {
	if (!DAY_KEY_PATTERN.test(dateKey || '')) {
		return null
	}
	const [year, month, day] = dateKey.split('-').map(Number)
	const names = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']
	return names[new Date(Date.UTC(year, month - 1, day)).getUTCDay()]
}
