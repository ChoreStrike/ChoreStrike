/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/**
 * Pure grouping rules of the task list (CS-PLAN-090 WP-C): by due class, by
 * floor and room, or by person, each with its open and overdue counts. No
 * store access and no wording — the caller passes the structure and labels
 * what it shows.
 */

import { compareTaskItems, dueClass, isOverdue } from './taskDisplay.js'

/** The groupings a `/tasks?group=` link may name; the first is the default. */
export const GROUPINGS = ['due', 'room', 'person']

/** Due classes in list order. */
export const DUE_GROUPS = ['overdue', 'today', 'upcoming', 'completed']

/** Key of the group that holds rows nobody is assigned to. */
export const UNASSIGNED = ''

/**
 * The grouping a route query names (`due` when missing or unknown).
 * @param {unknown} value Raw `?group=` value.
 * @return {string}
 */
export function groupingFromQuery(value) {
	return GROUPINGS.includes(value) ? value : GROUPINGS[0]
}

/**
 * The open room of the list by room from `?room=` (null when none).
 * @param {object} query Route query.
 * @return {?number}
 */
export function openGroupFromQuery(query = {}) {
	const roomId = Number.parseInt(query.room, 10)
	return groupingFromQuery(query.group) === 'room' && Number.isInteger(roomId) ? roomId : null
}

/**
 * Open and overdue counts of a set of rows.
 * @param {object[]} items Enriched rows.
 * @return {{open: number, overdue: number}}
 */
function counts(items) {
	return {
		open: items.filter((item) => item.roomTask.isActive).length,
		overdue: items.filter((item) => isOverdue(item.roomTask)).length,
	}
}

/**
 * Rows in the order every list shows them.
 * @param {object[]} items Enriched rows.
 * @return {object[]}
 */
function sorted(items) {
	return [...items].sort(compareTaskItems)
}

/**
 * The due class of a row: missed, due today, later, or finished.
 * @param {object} item Enriched row.
 * @param {string} timezone IANA timezone of the household.
 * @return {string} One of DUE_GROUPS.
 */
export function dueGroupOf(item, timezone) {
	return dueClass(item.roomTask, timezone)
}

/**
 * Order of the list by due date: due class first, then the shared row order,
 * so the rows of one class stay together on a paged list.
 * @param {string} timezone IANA timezone of the household.
 * @return {function(object, object): number}
 */
export function compareByDueGroup(timezone) {
	return (a, b) => {
		const rank = DUE_GROUPS.indexOf(dueGroupOf(a, timezone)) - DUE_GROUPS.indexOf(dueGroupOf(b, timezone))
		return rank !== 0 ? rank : compareTaskItems(a, b)
	}
}

/**
 * Rows in the order of the list by due date, with each row's due class
 * worked out once: the comparator above repeats it on every comparison.
 * @param {object[]} items Enriched rows.
 * @param {string} timezone IANA timezone of the household.
 * @return {object[]} A new array in list order.
 */
export function sortByDueGroup(items, timezone) {
	return items
		.map((item) => ({ item, rank: DUE_GROUPS.indexOf(dueGroupOf(item, timezone)) }))
		.sort((a, b) => (a.rank - b.rank) || compareTaskItems(a.item, b.item))
		.map(({ item }) => item)
}

/**
 * Rows by due class, in list order; a class without rows is left out.
 * @param {object[]} items Enriched rows.
 * @param {string} timezone IANA timezone of the household.
 * @return {Array<{key: string, items: object[], open: number, overdue: number}>}
 */
export function groupByDue(items, timezone) {
	return DUE_GROUPS
		.map((key) => {
			const rows = sorted(items.filter((item) => dueGroupOf(item, timezone) === key))
			return { key, items: rows, ...counts(rows) }
		})
		.filter((group) => group.items.length > 0)
}

/**
 * Rows by floor and room. Every room given is a group, also one without
 * rows, so a task can be added to it. Rows of a room that is not given are
 * left out: the caller narrows the rooms by its floor and room filters.
 * @param {object[]} items Enriched rows.
 * @param {object[]} floors Floors in display order.
 * @param {object[]} rooms Rooms to show, in display order.
 * @return {Array<{floor: object, open: number, overdue: number, rooms: Array<{room: object, items: object[], open: number, overdue: number}>}>}
 */
export function groupByRoom(items, floors, rooms) {
	const byRoom = new Map()
	for (const item of items) {
		const list = byRoom.get(item.roomTask.roomId) ?? []
		list.push(item)
		byRoom.set(item.roomTask.roomId, list)
	}
	return floors
		.map((floor) => {
			const floorRooms = rooms
				.filter((room) => room.floorId === floor.id)
				.map((room) => {
					const rows = sorted(byRoom.get(room.id) ?? [])
					return { room, items: rows, ...counts(rows) }
				})
			return {
				floor,
				open: floorRooms.reduce((sum, group) => sum + group.open, 0),
				overdue: floorRooms.reduce((sum, group) => sum + group.overdue, 0),
				rooms: floorRooms,
			}
		})
		.filter((group) => group.rooms.length > 0)
}

/**
 * Rows by person: one group per member with rows, in member order, then the
 * unassigned rows. A row with several assignees appears under each of them;
 * an assignee who is no longer a member keeps a group under their identifier.
 * @param {object[]} items Enriched rows.
 * @param {object[]} members Household members with nextcloudUserId and displayName.
 * @return {Array<{key: string, name: string, items: object[], open: number, overdue: number}>}
 */
export function groupByPerson(items, members) {
	const names = new Map(members.map((member) => [member.nextcloudUserId, member.displayName || member.nextcloudUserId]))
	const byPerson = new Map([...names.keys()].map((userId) => [userId, []]))
	for (const item of items) {
		const assignees = item.roomTask.assignees ?? []
		for (const userId of assignees.length > 0 ? assignees : [UNASSIGNED]) {
			const list = byPerson.get(userId) ?? []
			list.push(item)
			byPerson.set(userId, list)
		}
	}
	const unassigned = byPerson.get(UNASSIGNED) ?? []
	byPerson.delete(UNASSIGNED)
	return [...byPerson.entries(), [UNASSIGNED, unassigned]]
		.filter(([, rows]) => rows.length > 0)
		.map(([key, rows]) => {
			const list = sorted(rows)
			return { key, name: names.get(key) ?? key, items: list, ...counts(list) }
		})
}
