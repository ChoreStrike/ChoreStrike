/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
import { dateKeyInZone, todayKeyInZone } from './dateKeys.js'
import { taskEnergy } from './taskDisplay.js'

/**
 * Whether a room task's next due day is today in the house's time zone.
 * @param {?object} roomTask Room task payload.
 * @param {string} timezone IANA zone of the household.
 * @return {boolean}
 */
export function isDueToday(roomTask, timezone) {
	if (!roomTask?.nextDue) {
		return false
	}
	return dateKeyInZone(roomTask.nextDue, timezone) === todayKeyInZone(timezone)
}

/**
 * The three task sections of Today, from the delivered daily and overdue
 * reads (CS-PLAN-066 §3.3: selectors of the store slices, never copies).
 * Server visibility, eligibility and child limits stay authoritative; this
 * only applies the house-local day and the viewer's filter.
 *
 * - `overdue`: active rows with an unfulfilled miss that are not due today.
 * - `due`: rows due today; a completed row stays for its queued undo.
 * - `possible`: rows whose flex window is open although they are planned
 *   for another day (BUG-UX-005, server flag `openWindow`).
 *
 * A held row is one whose completion is in flight or just succeeded. It
 * keeps its place in its section, so the row shows its feedback before the
 * reads move it (D-090-30), and it is shown nowhere else meanwhile.
 *
 * @param {object} input The reads and the viewer's choices.
 * @param {object[]} input.daily Enriched rows of the daily read.
 * @param {object[]} input.overdue Enriched rows of the overdue read.
 * @param {string} input.timezone IANA zone of the household.
 * @param {(item: object) => boolean} input.isVisible The viewer's filter.
 * @param {Array<{section: string, index: number, item: object}>} [input.held] Rows to keep in place.
 * @return {{overdue: object[], due: object[], possible: object[]}}
 */
export function buildTodaySections({ daily, overdue, timezone, isVisible, held = [] }) {
	const dueToday = (item) => isDueToday(item.roomTask, timezone)
	const dueTodayIds = new Set(daily
		.filter((item) => !item.openWindow && item.roomTask.isActive && dueToday(item))
		.map((item) => item.roomTask.id))
	const heldIds = new Set(held.map((entry) => entry.item.roomTask.id))
	const shown = (rows) => rows.filter((item) => !heldIds.has(item.roomTask.id) && isVisible(item))

	const sections = {
		overdue: shown(overdue.filter((item) => item.roomTask.isActive && !dueTodayIds.has(item.roomTask.id))),
		due: shown(daily.filter((item) => !item.openWindow && (!item.roomTask.isActive || dueToday(item)))),
		possible: shown(daily.filter((item) => item.openWindow
			&& (!item.roomTask.isActive || item.occurrence?.classification === 'agreed'))),
	}
	// Ascending, so an earlier insert never shifts a later one's place.
	for (const entry of [...held].sort((a, b) => a.index - b.index)) {
		const rows = sections[entry.section]
		rows.splice(Math.min(entry.index, rows.length), 0, entry.item)
	}
	return sections
}

/**
 * Energy of the work that is still open today: the active rows that are
 * due today or overdue. Rows that are only possible today are not planned
 * for today and do not count.
 * @param {{overdue: object[], due: object[]}} sections Sections of one viewer.
 * @return {number}
 */
export function openEnergy(sections) {
	return [...sections.overdue, ...sections.due]
		.filter((item) => item.roomTask.isActive)
		.reduce((sum, item) => sum + taskEnergy(item), 0)
}
