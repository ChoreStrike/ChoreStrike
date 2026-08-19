import { describe, it, expect, beforeEach, jest } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import axios from '@nextcloud/axios'
import Game2048 from '../../../src/components/Minigames/Game2048.vue'
import { useMinigameStore } from '../../../src/store/minigame.js'

describe('Game2048.vue', () => {
	let pinia
	let store

	beforeEach(async () => {
		pinia = createPinia()
		setActivePinia(pinia)
		store = useMinigameStore()
		jest.clearAllMocks()
		axios.mockResolvedValue({ data: { success: true, data: { score: 0 } } })
	})

	it('mounts and renders the board with two initial tiles', async () => {
		const wrapper = mount(Game2048, { global: { plugins: [pinia] } })
		await flushPromises()
		const filled = wrapper.findAll('.board-cell .cell-value')
		expect(filled.length).toBeGreaterThanOrEqual(2)
		expect(store.currentGame).toBe('2048')
	})

	it('does not show the game-over overlay in a fresh game', async () => {
		const wrapper = mount(Game2048, { global: { plugins: [pinia] } })
		await flushPromises()
		expect(wrapper.find('.game-over-overlay').exists()).toBe(false)
	})

	it('restarts the game when the restart button is clicked', async () => {
		const wrapper = mount(Game2048, { global: { plugins: [pinia] } })
		await flushPromises()
		await wrapper.find('[data-testid="game-2048-restart-button"]').trigger('click')
		expect(store.currentGame).toBe('2048')
	})
})
