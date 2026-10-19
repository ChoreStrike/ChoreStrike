/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { DIRECTIONS, OPPOSITE, moveCursor, tappedCell } from '@/components/Minigames/games/grid.js'

/**
 * CS-PLAN-086 R086H-04: the grid helpers the game rules share.
 */
describe('game grid helpers', () => {
	it('names one cell step and the opposite of every direction', () => {
		expect(Object.keys(DIRECTIONS)).toEqual(['up', 'down', 'left', 'right'])
		for (const [direction, step] of Object.entries(DIRECTIONS)) {
			const back = DIRECTIONS[OPPOSITE[direction]]
			expect(Math.abs(step.x) + Math.abs(step.y)).toBe(1)
			expect([step.x + back.x, step.y + back.y]).toEqual([0, 0])
		}
		expect(Object.isFrozen(DIRECTIONS.up)).toBe(true)
	})

	it('accepts a tap only on a cell of the board', () => {
		expect(tappedCell({ cell: 0 }, 16)).toBe(0)
		expect(tappedCell({ cell: 15 }, 16)).toBe(15)
		expect(tappedCell({ cell: 16 }, 16)).toBe(-1)
		expect(tappedCell({ cell: -1 }, 16)).toBe(-1)
		expect(tappedCell({ cell: 1.5 }, 16)).toBe(-1)
		expect(tappedCell(null, 16)).toBe(-1)
		expect(tappedCell('act', 16)).toBe(-1)
	})

	it('moves the cursor within the board and bumps the revision only on a move', () => {
		const state = { cursor: 0, revision: 0 }
		moveCursor(state, 4, 4, 'left')
		moveCursor(state, 4, 4, 'up')
		expect(state).toEqual({ cursor: 0, revision: 0 })

		moveCursor(state, 4, 4, 'right')
		moveCursor(state, 4, 4, 'down')
		expect(state).toEqual({ cursor: 5, revision: 2 })

		state.cursor = 15
		moveCursor(state, 4, 4, 'right')
		moveCursor(state, 4, 4, 'down')
		expect(state).toEqual({ cursor: 15, revision: 2 })
	})
})
