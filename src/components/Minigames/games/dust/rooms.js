/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/**
 * Dust Runner's floor plans and grid helpers (CS-PLAN-086 §3.1). Each room
 * is a 15 × 15 floor plan of a household room, not a maze: walls around,
 * furniture inside, open floor between. Legend: `#` wall or furniture,
 * `.` floor with dust, `R` the robot's start, `D` the charging dock, `N` a
 * dust bunny's nest under a piece of furniture (bunnies only, one exit at
 * least). Every floor cell is reachable and has two open neighbours (no
 * dead ends); the rooms spec pins both.
 */

export const COLS = 15
export const ROWS = 15

/** Cell kinds of a parsed room. */
export const FLOOR = 0
export const BLOCKED = 1
export const NEST = 2

export const DIRECTIONS = Object.freeze({
	up: Object.freeze({ x: 0, y: -1 }),
	left: Object.freeze({ x: -1, y: 0 }),
	down: Object.freeze({ x: 0, y: 1 }),
	right: Object.freeze({ x: 1, y: 0 }),
})

/** Fixed order of every direction scan, so ties break the same way each time. */
export const DIRECTION_ORDER = Object.freeze(['up', 'left', 'down', 'right'])

export const OPPOSITE = Object.freeze({ up: 'down', down: 'up', left: 'right', right: 'left' })

// Staircase, console table, wardrobe, the bench (two nests), a plant, the
// shoe rack and a cabinet next to the dock.
const HALLWAY = [
	'###############',
	'#R............#',
	'#.............#',
	'#.###.###.##..#',
	'#.###.....##..#',
	'#.###.....##..#',
	'#.###.........#',
	'#.###..#NN#...#',
	'#.###.........#',
	'#...........#.#',
	'#.............#',
	'#.####.....#..#',
	'#.........D#..#',
	'#.............#',
	'###############',
]

// Counters along the walls, the island (a nest under it), the table with
// its chairs, the stove (a nest under it) and a nest under the corner cabinet.
const KITCHEN = [
	'###############',
	'####.....######',
	'#R...........##',
	'#.............#',
	'##..######....#',
	'##..##N###....#',
	'#.............#',
	'#..........#..#',
	'#.##.##....#..#',
	'#.##.##.......#',
	'#.......###...#',
	'#.##.##.###...#',
	'#.......#N#...#',
	'#N..D.........#',
	'###############',
]

// A bay window, two plants, the sofa (three nests under it), shelves, the
// coffee table, an armchair, a side table and the TV corner.
const LIVING_ROOM = [
	'###############',
	'######....#####',
	'#.............#',
	'#..#.......#..#',
	'#.............#',
	'#...######....#',
	'#.#.#NNN##.##.#',
	'#.#.....#..##.#',
	'#.#...........#',
	'#.#..####.....#',
	'#....####..##.#',
	'#.........D##.#',
	'#.##..........#',
	'#R..........###',
	'###############',
]

/** Hallway, kitchen, living room; after the last room it repeats. */
export const LAYOUTS = Object.freeze([HALLWAY, KITCHEN, LIVING_ROOM].map((rows) => Object.freeze(rows)))

/**
 * @param {number} x Column
 * @param {number} y Row
 * @return {number} Index into the room's cell arrays
 */
export function cellIndex(x, y) {
	return y * COLS + x
}

/**
 * @param {string[]} rows One layout from LAYOUTS
 * @return {{kinds: Uint8Array, dust: Uint8Array, dustCount: number, start: object, dock: object, nests: object[]}}
 */
export function parseRoom(rows) {
	const room = { kinds: new Uint8Array(COLS * ROWS), dust: new Uint8Array(COLS * ROWS), dustCount: 0, start: null, dock: null, nests: [] }
	for (let y = 0; y < ROWS; y++) {
		for (let x = 0; x < COLS; x++) {
			const mark = rows[y][x]
			const index = cellIndex(x, y)
			room.kinds[index] = mark === '#' ? BLOCKED : (mark === 'N' ? NEST : FLOOR)
			if (mark === '.') {
				room.dust[index] = 1
				room.dustCount++
			}
			if (mark === 'R') room.start = Object.freeze({ x, y })
			if (mark === 'D') room.dock = Object.freeze({ x, y })
			if (mark === 'N') room.nests.push(Object.freeze({ x, y }))
		}
	}
	return Object.freeze(room)
}

const ROOMS = Object.freeze(LAYOUTS.map(parseRoom))

/**
 * @param {number} index Rooms cleared so far
 * @return {object} The parsed room for that round (the last one repeats)
 */
export function roomAt(index) {
	return ROOMS[Math.min(index, ROOMS.length - 1)]
}

/**
 * @param {object} room Parsed room
 * @param {number} x Column
 * @param {number} y Row
 * @return {boolean} Whether the robot and roaming bunnies may enter the cell
 */
export function isFloor(room, x, y) {
	return x >= 0 && x < COLS && y >= 0 && y < ROWS && room.kinds[cellIndex(x, y)] === FLOOR
}

/**
 * Breadth-first floor distances from one cell (-1 where unreachable), into
 * preallocated arrays: the bunnies read them to chase or flee.
 *
 * @param {object} room Parsed room
 * @param {{x: number, y: number}} from Start cell
 * @param {Int16Array} distances Output, one entry per cell
 * @param {Int16Array} queue Scratch queue, one entry per cell
 */
export function fillDistances(room, from, distances, queue) {
	distances.fill(-1)
	let head = 0
	let tail = 0
	const origin = cellIndex(from.x, from.y)
	distances[origin] = 0
	queue[tail++] = origin
	while (head < tail) {
		const index = queue[head++]
		const x = index % COLS
		const y = (index - x) / COLS
		for (const direction of DIRECTION_ORDER) {
			const nx = x + DIRECTIONS[direction].x
			const ny = y + DIRECTIONS[direction].y
			if (!isFloor(room, nx, ny) || distances[cellIndex(nx, ny)] !== -1) continue
			distances[cellIndex(nx, ny)] = distances[index] + 1
			queue[tail++] = cellIndex(nx, ny)
		}
	}
}
