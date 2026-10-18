/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { mount } from '@vue/test-utils'
import Board from '@/components/Minigames/games/spots/Board.vue'
import { COLS, createState, view } from '@/components/Minigames/games/spots/logic.js'

const boardView = () => {
	const state = createState(() => 0.5)
	state.tiles[0] = { kind: 'sock', age: 0, life: 2000 }
	state.tiles[3] = { kind: 'golden', age: 0, life: 2600 }
	state.tiles[10] = { kind: 'stain', age: 2000, life: 2600 }
	return view(state)
}

/**
 * Spot Sprint's DOM floor: one tile per cell with its kind, the cursor
 * outline, shape-only items and the tapped tile reported to the shell.
 */
describe('Spot Sprint board', () => {
	const mountBoard = (props = {}) => mount(Board, { props: { view: boardView(), cols: COLS, ...props } })

	it('renders every tile with its kind and marks the cursor', () => {
		const wrapper = mountBoard()
		const tiles = wrapper.findAll('[data-cell]')

		expect(tiles).toHaveLength(16)
		expect(tiles.map((tile) => tile.attributes('data-kind'))).toEqual([
			'sock', 'empty', 'empty', 'golden',
			'empty', 'stain', 'empty', 'empty',
			'empty', 'empty', 'drying', 'empty',
			'empty', 'empty', 'empty', 'empty',
		])
		expect(tiles[5].classes()).toContain('is-cursor')
		expect(tiles[5].find('svg').exists()).toBe(true)
		expect(tiles[1].find('svg').exists()).toBe(false)
	})

	it('is decorative for assistive technology and carries no text', () => {
		const wrapper = mountBoard()
		expect(wrapper.attributes('aria-hidden')).toBe('true')
		expect(wrapper.text()).toBe('')
	})

	it('reports the tapped tile, also from inside its shape', async () => {
		const wrapper = mountBoard()

		await wrapper.findAll('[data-cell]')[3].trigger('pointerdown')
		await wrapper.findAll('[data-cell]')[5].find('path').trigger('pointerdown')
		await wrapper.trigger('pointerdown')

		expect(wrapper.emitted('tap')).toEqual([[3], [5]])
	})

	it('draws each item from a body shape with its own detail, and sends up bubbles from a wiped tile', () => {
		const doubled = { ...boardView(), tiles: ['wiped', ...boardView().tiles.slice(1)] }
		const tiles = mountBoard({ view: doubled }).findAll('[data-cell]')

		// Bubbles from the wiped tile; the sponge alone, the stain's sheen, the drying stain's crack.
		expect(tiles[0].findAll('.spots-bubble')).toHaveLength(3)
		expect(tiles[3].findAll('path')).toHaveLength(1)
		expect(tiles[5].findAll('path')).toHaveLength(2)
		expect(tiles[10].findAll('path')).toHaveLength(2)
		expect(tiles[5].find('.spots-detail').attributes('d')).not.toBe(tiles[10].find('.spots-detail').attributes('d'))
	})

	it('shows the golden frame while the points are doubled and drops the sparkle without motion', () => {
		const doubled = { ...boardView(), double: true, tiles: ['wiped', ...boardView().tiles.slice(1)] }
		const wrapper = mountBoard({ view: doubled, motion: false })

		expect(wrapper.classes()).toEqual(expect.arrayContaining(['is-double', 'is-calm']))
		expect(wrapper.findAll('[data-cell]')[0].attributes('data-kind')).toBe('wiped')
	})
})
