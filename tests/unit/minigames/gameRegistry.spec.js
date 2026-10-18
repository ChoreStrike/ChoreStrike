/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { readFileSync } from 'fs'
import { resolve } from 'path'
import { GAMES, GAME_IDS, findGame } from '@/components/Minigames/gameRegistry.js'

const MINIGAMES = resolve(__dirname, '../../../src/components/Minigames')
const read = (path) => readFileSync(resolve(MINIGAMES, path), 'utf8')

/**
 * CS-PLAN-086 §3.5: the server ArcadeCatalog (ArcadeCatalogTest) and this
 * spec each pin the expected game ids — a new game fails both until both
 * sides know it. Every game is its own lazy chunk.
 */
describe('game registry', () => {
	it('pins the game ids', () => {
		expect(GAME_IDS).toEqual(['spots', 'socks', 'snake', 'tiles', 'dust'])
	})

	it('describes every game with translated copy, an icon and an input mode', () => {
		for (const game of GAMES) {
			for (const field of ['name', 'description', 'controls']) {
				expect(typeof game[field]()).toBe('string')
				expect(game[field]().length).toBeGreaterThan(0)
			}
			expect(game.icon).toBeTruthy()
			expect(game.accent).toMatch(/^var\(--cs-arcade-[a-z-]+\)$/)
			expect(['dpad', 'tap', 'paddle']).toContain(game.input)
			expect([undefined, 'dom']).toContain(game.renderer)
		}
	})

	it('lets a game name its end with an optional title and announcement', () => {
		const socks = findGame('socks')
		expect(socks.overTitle()).toBe('All pairs found!')
		expect(socks.overAnnouncement(640)).toBe('All pairs found. Score: 640')
		// Both or neither, so the overlay and the live region never disagree.
		for (const game of GAMES) {
			expect(typeof game.overTitle === 'function').toBe(typeof game.overAnnouncement === 'function')
		}
	})

	it('finds entries by id and answers null for unknown ids', () => {
		expect(findGame('snake').id).toBe('snake')
		expect(findGame('pacman')).toBeNull()
	})

	it('imports each game lazily as its own named chunk', () => {
		expect(read('gameRegistry.js')).not.toMatch(/from '\.\/games\/[^']+\/(game|logic|render)\.js'/)
		for (const id of GAME_IDS) {
			const entry = read(`games/${id}/index.js`)
			expect(entry).toContain(`import(/* webpackChunkName: "game-${id}" */ './game.js')`)
			expect(entry).not.toMatch(/^import .* from '\.\/(game|logic|render)\.js'/m)
		}
	})

	it('loads a module the shell can drive: a canvas renderer or a DOM view and board', async () => {
		for (const game of GAMES) {
			const module = await game.load()
			expect(module.cols).toBeGreaterThan(0)
			expect(module.rows).toBeGreaterThan(0)
			const renderers = game.renderer === 'dom' ? ['view'] : ['render']
			for (const fn of ['createState', 'step', ...renderers]) {
				expect(typeof module[fn]).toBe('function')
			}
			if (game.renderer === 'dom') {
				expect(module.Board).toBeTruthy()
			}
		}
	})
})
