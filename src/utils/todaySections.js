/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
import { cycleOf, namesViewer } from './taskDisplay.js'

/**
 * Whether a row is today's work: the live suggestion placed it on today
 * (ADR-022). The daily read marks every other open row as `openWindow`.
 * @param {?object} item Enriched row of the daily read.
 * @return {boolean}
 */
export function isSuggestedToday(item) {
	return item ? !item.openWindow : false
}

/**
 * What "My tasks" shows on Today. `isMine`: work that is assigned to the
 * viewer or to nobody. `isSuggested`: work that today's suggestion places
 * on the viewer; a row that names nobody falls back to the assignment.
 * Before the viewer is known both show everything rather than an empty
 * list.
 * @param {?string} userId The viewer's user id.
 * @return {{isMine: (item: object) => boolean, isSuggested: (item: object) => boolean}}
 */
export function viewerFilters(userId) {
	const isMine = (item) => namesViewer(item.assignees ?? item.roomTask.assignees ?? [], userId)
	return {
		isMine,
		isSuggested: (item) => (userId && Array.isArray(item.suggestedFor) ? item.suggestedFor.includes(userId) : isMine(item)),
	}
}

/**
 * The three task sections of Today, from the delivered daily and overdue
 * reads (CS-PLAN-066 §3.3: selectors of the store slices, never copies).
 * Server visibility, eligibility and child limits stay authoritative; this
 * only applies the viewer's filter.
 *
 * - `overdue`: active rows whose window ended.
 * - `due`: rows the suggestion placed on today for the people the filter
 *   shows; a completed row stays for its queued undo.
 * - `possible`: the other rows whose window is open — placed on another
 *   day (BUG-UX-005, server flag `openWindow`), or on today for someone
 *   the filter does not show.
 *
 * A held row is one whose completion is in flight or just succeeded. It
 * keeps its place in its section, so the row shows its feedback before the
 * reads move it (D-090-30), and it is shown nowhere else meanwhile.
 *
 * @param {object} input The reads and the viewer's choices.
 * @param {object[]} input.daily Enriched rows of the daily read.
 * @param {object[]} input.overdue Enriched rows of the overdue read.
 * @param {(item: object) => boolean} input.isVisible The viewer's filter for work that is not today's.
 * @param {(item: object) => boolean} [input.isSuggested] Whether today's suggestion of a row is one the filter shows.
 * @param {Array<{section: string, index: number, item: object}>} [input.held] Rows to keep in place.
 * @return {{overdue: object[], due: object[], possible: object[]}}
 */
export function buildTodaySections({ daily, overdue, isVisible, isSuggested = () => true, held = [] }) {
	// A row whose cycle moved on after its completion is no longer today's, before the reads say so.
	const stillOpen = (item) => !item.roomTask.isActive || cycleOf(item)?.state === 'open'
	const dueToday = (item) => isSuggestedToday(item) && isSuggested(item) && stillOpen(item)
	const dueTodayIds = new Set(daily.filter(dueToday).map((item) => item.roomTask.id))
	const heldIds = new Set(held.map((entry) => entry.item.roomTask.id))
	const notHeld = (rows) => rows.filter((item) => !heldIds.has(item.roomTask.id))

	const sections = {
		overdue: notHeld(overdue.filter((item) => item.roomTask.isActive && !dueTodayIds.has(item.roomTask.id) && isVisible(item))),
		due: notHeld(daily.filter(dueToday)),
		possible: notHeld(daily.filter((item) => !dueToday(item) && isVisible(item) && stillOpen(item))),
	}
	// Ascending, so an earlier insert never shifts a later one's place.
	for (const entry of [...held].sort((a, b) => a.index - b.index)) {
		const rows = sections[entry.section]
		rows.splice(Math.min(entry.index, rows.length), 0, entry.item)
	}
	return sections
}
