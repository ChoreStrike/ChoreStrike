/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { mount } from '@vue/test-utils'
import Board from '@/components/Minigames/games/socks/Board.vue'
import { COLS, PATTERNS } from '@/components/Minigames/games/socks/logic.js'

/** A board snapshot: socks 0/1 matched, 2 up, 3/4 a wrong guess, the rest down. */
const boardView = () => ({
	cards: [
		{ face: 'matched', pattern: 'star' },
		{ face: 'matched', pattern: 'star' },
		{ face: 'up', pattern: 'heart' },
		{ face: 'miss', pattern: 'dots' },
		{ face: 'miss', pattern: 'plus' },
		...new Array(11).fill(null).map(() => ({ face: 'down', pattern: null })),
	],
	cursor: 2,
})

/**
 * Sock Match's DOM basket: one card per cell with its face, patterns only
 * on turned socks, shape-distinct patterns, the cursor outline and the
 * tapped card reported to the shell.
 */
describe('Sock Match board', () => {
	const mountBoard = (props = {}) => mount(Board, { props: { view: boardView(), cols: COLS, ...props } })

	it('renders every card with its face and shows a pattern only on turned socks', () => {
		const wrapper = mountBoard()
		const cards = wrapper.findAll('[data-cell]')

		expect(cards).toHaveLength(16)
		expect(cards.slice(0, 6).map((card) => card.attributes('data-face'))).toEqual(['matched', 'matched', 'up', 'miss', 'miss', 'down'])
		expect(cards.slice(0, 6).map((card) => card.attributes('data-pattern'))).toEqual(['star', 'star', 'heart', 'dots', 'plus', undefined])
		expect(cards[2].classes()).toContain('is-cursor')
		expect(cards[5].find('.socks-basket').exists()).toBe(true)
		expect(cards[5].find('.socks-sock').exists()).toBe(false)
		expect(cards[2].find('.socks-sock').exists()).toBe(true)
	})

	it('marks found pairs with a check', () => {
		const cards = mountBoard().findAll('[data-cell]')

		expect(cards[0].find('.socks-check').exists()).toBe(true)
		expect(cards[2].find('.socks-check').exists()).toBe(false)
	})

	it('colors every turned sock through its pattern class and leaves face-down socks plain', () => {
		const cards = mountBoard().findAll('[data-cell]')

		expect(cards[0].classes()).toContain('is-star')
		expect(cards[2].classes()).toContain('is-heart')
		expect(cards[5].classes().filter((name) => name.startsWith('is-'))).toEqual(['is-down'])
	})

	it('tells every pattern apart by its shape, not by color', () => {
		const view = { cards: PATTERNS.map((pattern) => ({ face: 'up', pattern })), cursor: 0 }
		const emblems = mountBoard({ view }).findAll('.socks-emblem').map((path) => path.attributes('d'))

		expect(emblems).toHaveLength(PATTERNS.length)
		expect(new Set(emblems).size).toBe(PATTERNS.length)
	})

	it('is decorative for assistive technology and carries no text', () => {
		const wrapper = mountBoard()

		expect(wrapper.attributes('aria-hidden')).toBe('true')
		expect(wrapper.text()).toBe('')
	})

	it('reports the tapped card, also from inside its shape', async () => {
		const wrapper = mountBoard()

		await wrapper.findAll('[data-cell]')[7].trigger('pointerdown')
		await wrapper.findAll('[data-cell]')[2].find('path').trigger('pointerdown')
		await wrapper.trigger('pointerdown')

		expect(wrapper.emitted('tap')).toEqual([[7], [2]])
	})

	it('drops the turn and pair animations without motion', () => {
		expect(mountBoard({ motion: false }).classes()).toContain('is-calm')
		expect(mountBoard({ motion: true }).classes()).not.toContain('is-calm')
	})
})
