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
