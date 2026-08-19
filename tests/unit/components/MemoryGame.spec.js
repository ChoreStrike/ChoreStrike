import { describe, it, expect, beforeEach, jest } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import axios from '@nextcloud/axios'
import MemoryGame from '../../../src/components/Minigames/MemoryGame.vue'
import { useMinigameStore } from '../../../src/store/minigame.js'

describe('MemoryGame.vue', () => {
	let pinia
	let store

	beforeEach(async () => {
		pinia = createPinia()
		setActivePinia(pinia)
		store = useMinigameStore()
		jest.clearAllMocks()
		axios.mockResolvedValue({ data: { success: true, data: { score: 0 } } })
	})

	it('mounts and renders 16 cards', async () => {
		const wrapper = mount(MemoryGame, { global: { plugins: [pinia] } })
		await flushPromises()
		expect(wrapper.findAll('[data-testid="memory-board"] .memory-card').length).toBe(16)
	})

	it('flips the first card and increments moves after the second pick', async () => {
		const wrapper = mount(MemoryGame, { global: { plugins: [pinia] } })
		await flushPromises()
		const cards = wrapper.findAll('.memory-card')
		await cards[0].trigger('click')
		expect(cards[0].classes()).toContain('flipped')
		expect(wrapper.find('[data-testid="memory-moves"]').text()).toBe('0')
		await cards[1].trigger('click')
		expect(wrapper.find('[data-testid="memory-moves"]').text()).toBe('1')
	})

	it('restarts the game when the restart button is clicked', async () => {
		const wrapper = mount(MemoryGame, { global: { plugins: [pinia] } })
		await flushPromises()
		await wrapper.find('[data-testid="memory-restart-button"]').trigger('click')
		expect(store.currentGame).toBe('memory')
	})
})
