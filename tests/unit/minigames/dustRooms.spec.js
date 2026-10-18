/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import {
	BLOCKED, COLS, DIRECTIONS, FLOOR, LAYOUTS, NEST, ROWS, cellIndex, fillDistances, isFloor, parseRoom, roomAt,
} from '@/components/Minigames/games/dust/rooms.js'

const ROOMS = LAYOUTS.map(parseRoom)

const floorNeighbours = (room, x, y) => Object.values(DIRECTIONS)
	.filter((delta) => isFloor(room, x + delta.x, y + delta.y)).length

const cells = function* () {
	for (let y = 0; y < ROWS; y++) {
		for (let x = 0; x < COLS; x++) yield { x, y }
	}
}

describe('Dust Runner rooms', () => {
	it('has three 15 × 15 floor plans walled in on every side', () => {
		expect(LAYOUTS).toHaveLength(3)
		for (const rows of LAYOUTS) {
			expect(rows).toHaveLength(ROWS)
			expect(rows.every((row) => row.length === COLS && /^[#.RDN]+$/.test(row))).toBe(true)
			for (const { x, y } of cells()) {
				if (x === 0 || y === 0 || x === COLS - 1 || y === ROWS - 1) expect(rows[y][x]).toBe('#')
			}
		}
	})

	it('gives every room one start, one dock, its nests and dust on the rest of the floor', () => {
		expect(ROOMS.map((room) => room.nests.length)).toEqual([2, 3, 3])
		expect(ROOMS.map((room) => room.dustCount)).toEqual([129, 120, 119])
		for (const room of ROOMS) {
			expect(room.kinds[cellIndex(room.start.x, room.start.y)]).toBe(FLOOR)
			expect(room.kinds[cellIndex(room.dock.x, room.dock.y)]).toBe(FLOOR)
			expect(room.dust[cellIndex(room.start.x, room.start.y)]).toBe(0)
			expect(room.dust[cellIndex(room.dock.x, room.dock.y)]).toBe(0)
			for (const nest of room.nests) {
				expect(room.kinds[cellIndex(nest.x, nest.y)]).toBe(NEST)
				expect(floorNeighbours(room, nest.x, nest.y)).toBeGreaterThan(0)
			}
			const floor = [...cells()].filter(({ x, y }) => room.kinds[cellIndex(x, y)] === FLOOR)
			expect(floor).toHaveLength(room.dustCount + 2)
		}
	})

	it('reaches every floor cell from the start, without a dead end anywhere', () => {
		for (const room of ROOMS) {
			const distances = new Int16Array(COLS * ROWS)
			fillDistances(room, room.start, distances, new Int16Array(COLS * ROWS))
			for (const { x, y } of cells()) {
				const kind = room.kinds[cellIndex(x, y)]
				if (kind !== FLOOR) {
					expect(distances[cellIndex(x, y)]).toBe(-1)
					continue
				}
				expect(distances[cellIndex(x, y)]).toBeGreaterThanOrEqual(0)
				expect(floorNeighbours(room, x, y)).toBeGreaterThanOrEqual(2)
			}
		}
	})

	it('measures floor distances around furniture', () => {
		const room = ROOMS[0]
		const distances = new Int16Array(COLS * ROWS)
		fillDistances(room, room.start, distances, new Int16Array(COLS * ROWS))

		expect(distances[cellIndex(13, 1)]).toBe(12)
		// Below the staircase: down the left wall, not through the stairs.
		expect(distances[cellIndex(3, 9)]).toBe(10)
		expect(distances[cellIndex(3, 4)]).toBe(-1)
	})

	it('opens the hallway with a row of dust to the right of the start', () => {
		const room = roomAt(0)
		expect(room.start).toEqual({ x: 1, y: 1 })
		for (let x = 2; x < COLS - 1; x++) expect(room.dust[cellIndex(x, 1)]).toBe(1)
		expect(room.kinds[cellIndex(COLS - 1, 1)]).toBe(BLOCKED)
	})

	it('repeats the last room after the living room', () => {
		expect(roomAt(2)).toBe(roomAt(5))
		expect(roomAt(0)).not.toBe(roomAt(1))
	})
})
