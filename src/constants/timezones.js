/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/**
 * Curated IANA timezone subset for the house-settings select (CS-PLAN-057).
 *
 * The full `\DateTimeZone::listIdentifiers()` list is ~420 entries —
 * unusable as a dropdown. This subset covers the common regions; the
 * backend validates against the FULL closed set, so a household needing an
 * exotic identifier keeps working (values round-trip), and the curated list
 * is a UI affordance, not a limit.
 */

/** @type {Array<{group: string, zones: string[]}>} */
export const TIMEZONE_GROUPS = [
	{
		group: 'UTC',
		zones: ['UTC'],
	},
	{
		group: 'Europe',
		zones: [
			'Europe/Berlin',
			'Europe/Vienna',
			'Europe/Zurich',
			'Europe/London',
			'Europe/Dublin',
			'Europe/Paris',
			'Europe/Madrid',
			'Europe/Rome',
			'Europe/Amsterdam',
			'Europe/Brussels',
			'Europe/Warsaw',
			'Europe/Prague',
			'Europe/Stockholm',
			'Europe/Oslo',
			'Europe/Copenhagen',
			'Europe/Helsinki',
			'Europe/Athens',
			'Europe/Istanbul',
			'Europe/Lisbon',
			'Europe/Kyiv',
			'Europe/Moscow',
		],
	},
	{
		group: 'Americas',
		zones: [
			'America/New_York',
			'America/Toronto',
			'America/Chicago',
			'America/Denver',
			'America/Phoenix',
			'America/Los_Angeles',
			'America/Vancouver',
			'America/Anchorage',
			'Pacific/Honolulu',
			'America/Mexico_City',
			'America/Bogota',
			'America/Lima',
			'America/Santiago',
			'America/Sao_Paulo',
			'America/Argentina/Buenos_Aires',
		],
	},
	{
		group: 'Asia',
		zones: [
			'Asia/Jerusalem',
			'Asia/Dubai',
			'Asia/Kolkata',
			'Asia/Bangkok',
			'Asia/Singapore',
			'Asia/Hong_Kong',
			'Asia/Shanghai',
			'Asia/Taipei',
			'Asia/Seoul',
			'Asia/Tokyo',
		],
	},
	{
		group: 'Australia & Pacific',
		zones: [
			'Australia/Perth',
			'Australia/Adelaide',
			'Australia/Brisbane',
			'Australia/Sydney',
			'Pacific/Auckland',
			'Pacific/Fiji',
		],
	},
	{
		group: 'Africa',
		zones: [
			'Africa/Cairo',
			'Africa/Johannesburg',
			'Africa/Lagos',
			'Africa/Nairobi',
			'Africa/Casablanca',
		],
	},
]

/** Flat list of every curated zone id. */
export const CURATED_TIMEZONES = TIMEZONE_GROUPS.flatMap((group) => group.zones)
