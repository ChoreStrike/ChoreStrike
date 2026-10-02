/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/**
 * Exact minor-unit helpers (CS-PLAN-070 WP3 §3.6, decision D8/C3b).
 *
 * All API money/energy values are signed integers in MINOR units —
 * `100 energy minor = 1 energy`, `1000 coin minor = 10 Coins` (the frozen
 * rate). No float arithmetic ever touches a stored value; formatting is
 * the only conversion point.
 */

/** Minor units per whole energy unit. */
export const ENERGY_MINOR_PER_UNIT = 100

/** Minor Coin units per whole Coin (100 coin minor = 1 Coin). */
export const COIN_MINOR_PER_UNIT = 100

/** Minor Coin units per whole energy unit (frozen rate: 10 Coins). */
export const COIN_MINOR_PER_ENERGY = 1000

/**
 * Format a minor-unit energy value as a human decimal string.
 * `450` → `"4.5"`, `400` → `"4"` (trailing zeros trimmed).
 * @param {number} minor Exact energy minor units.
 * @return {string} Decimal display string.
 */
export function formatEnergyMinor(minor) {
	return formatMinorWithScale(minor, ENERGY_MINOR_PER_UNIT)
}

/**
 * Format a minor-unit Coin value as a human decimal string.
 * `2500` → `"25"`, `1250` → `"12.5"`.
 * @param {number} minor Exact coin minor units.
 * @return {string} Decimal display string.
 */
export function formatCoinMinor(minor) {
	return formatMinorWithScale(minor, COIN_MINOR_PER_UNIT)
}

/**
 * Format a generic minor-unit value with the given scale (100 for energy
 * minor per unit).
 * @param {number} minor Exact minor units.
 * @return {string} Decimal display string.
 */
export function formatMinor(minor) {
	return formatMinorWithScale(minor, ENERGY_MINOR_PER_UNIT)
}

/**
 * Strict decimal parser for Coin prices (CS-PLAN-070 WP8 §3.3, decision
 * D7/C3a): converts authored decimal text to exact integer minor units
 * without ever touching float arithmetic.
 *
 * Accepted grammar (whole prices and at most two fractional digits):
 *   `"12"` → 1200, `"12.5"` → 1250, `"0.99"` → 99, `"12,50"` → 1250
 * Rejected: mixed or repeated separators, grouping (`1.234,56`), leading
 * or trailing separators (`,5`, `12.`), signs, exponents (`1e3`, `1E2`),
 * more than two fraction digits, zero, negatives and values whose minor
 * units exceed `Number.MAX_SAFE_INTEGER`.
 *
 * @param {string} text Authored decimal price text (draft strings only).
 * @return {number} Exact integer minor units (always positive).
 * @throws {Error} With a stable English reason when the input is invalid.
 */
export function parseCoinMinor(text) {
	const fail = (reason) => {
		throw new Error(`Invalid coin price: ${reason}`)
	}
	if (typeof text !== 'string') {
		fail('input must be a string')
	}
	const trimmed = text.trim()
	if (trimmed === '') {
		fail('empty input')
	}
	if (/[eE]/.test(trimmed)) {
		fail('exponents are not allowed')
	}
	if (/[+-]/.test(trimmed)) {
		fail('signs are not allowed')
	}
	const dotCount = (trimmed.match(/\./g) ?? []).length
	const commaCount = (trimmed.match(/,/g) ?? []).length
	if (dotCount + commaCount > 1) {
		fail('only one decimal separator is allowed')
	}
	const separator = dotCount === 1 ? '.' : (commaCount === 1 ? ',' : null)
	const [wholePart, fractionPart] = separator ? trimmed.split(separator) : [trimmed, '']
	if (!/^\d+$/.test(wholePart)) {
		fail('the whole part must be digits only')
	}
	if (separator !== null && !/^\d{1,2}$/.test(fractionPart)) {
		fail('at most two decimal digits are allowed')
	}
	if (wholePart.length > 1 && wholePart.startsWith('0')) {
		fail('leading zeros are not allowed')
	}
	const whole = Number.parseInt(wholePart, 10)
	const fraction = fractionPart === '' ? 0 : Number.parseInt(fractionPart.padEnd(2, '0'), 10)
	const minor = whole * COIN_MINOR_PER_UNIT + fraction
	if (!Number.isSafeInteger(minor)) {
		fail('the price is too large')
	}
	if (minor <= 0) {
		fail('the price must be greater than zero')
	}
	return minor
}

/**
 * Integer-only decimal formatting: `(value - remainder) / scale` for the
 * whole part, remainder-based fraction digits — never `value / scale`
 * float division.
 * @param {number} minor Exact minor units.
 * @param {number} scale Minor units per whole unit.
 * @return {string}
 */
function formatMinorWithScale(minor, scale) {
	if (!Number.isFinite(minor)) return '0'
	const sign = minor < 0 ? '-' : ''
	const value = Math.abs(Math.trunc(minor))
	const whole = Math.trunc(value / scale)
	const fraction = value % scale
	if (fraction === 0) {
		return `${sign}${whole}`
	}
	let digits = String(fraction)
	while (digits.length < String(scale - 1).length) {
		digits = `0${digits}`
	}
	while (digits.endsWith('0')) {
		digits = digits.slice(0, -1)
	}
	return `${sign}${whole}.${digits}`
}
