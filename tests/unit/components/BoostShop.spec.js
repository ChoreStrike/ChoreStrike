/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * BoostShop component suite (CR-053-22, CS-PLAN-068 §3.6, UX-013): prices
 * come ONLY from the authoritative catalog — without it the shop shows the
 * error state and purchases stay disabled instead of guessing.
 */

import { describe, it, expect, beforeEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import axios from '@nextcloud/axios'
import BoostShop from '../../../src/components/Minigames/BoostShop.vue'
import { useMinigameStore } from '../../../src/store/minigame.js'

// Error-path tests below reject the catalog fetch on purpose — silence the
// expected store logger output (mocking guideline, see checklist.spec.js).
jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

const CATALOG = { magnet: 30, ghost_mode: 20, slow_motion: 15, shrink: 10, no_obstacles: 25 }

describe('BoostShop', () => {
	let pinia
	let store

	beforeEach(async () => {
		pinia = createPinia()
		setActivePinia(pinia)
		store = useMinigameStore()
		jest.clearAllMocks()
		axios.mockResolvedValue({ data: { success: true, data: CATALOG } })
	})

	it('renders the error state and disables purchases without a catalog', async () => {
		axios.mockRejectedValue(new Error('catalog unavailable'))
		const wrapper = mount(BoostShop, { global: { plugins: [pinia] } })
		await flushPromises()

		expect(wrapper.find('[data-testid="boost-catalog-error"]').exists()).toBe(true)
		expect(wrapper.findAll('.boost-card')).toHaveLength(0)
		expect(wrapper.find('.btn-primary').attributes('disabled')).toBeDefined()
	})

	it('offers a retry after a failed catalog load', async () => {
		axios.mockRejectedValueOnce(new Error('boom'))
			.mockResolvedValue({ data: { success: true, data: CATALOG } })
		const wrapper = mount(BoostShop, { global: { plugins: [pinia] } })
		await flushPromises()
		expect(wrapper.find('[data-testid="boost-catalog-error"]').exists()).toBe(true)

		await wrapper.find('[data-testid="boost-catalog-retry"]').trigger('click')
		await flushPromises()
		expect(wrapper.find('[data-testid="boost-catalog-error"]').exists()).toBe(false)
		expect(wrapper.findAll('.boost-card')).toHaveLength(5)
	})

	it('lists catalog boosts sorted by cost with the wallet balance', async () => {
		store.currentCoins = 100
		const wrapper = mount(BoostShop, { global: { plugins: [pinia] } })
		await flushPromises()

		const costs = wrapper.findAll('.boost-card .cost-value').map(node => Number(node.text()))
		expect(costs).toEqual([10, 15, 20, 25, 30])
		expect(wrapper.find('.coins-value').text()).toBe('100')
	})

	it('tracks selection, total and remaining coins, and purchases', async () => {
		store.currentCoins = 100
		axios.mockImplementation(async ({ method, url }) => {
			if (method === 'post' && url === '/apps/chorestrike/api/v1/minigames/boosts/magnet/purchase') {
				return { data: { success: true, data: { success: true, remainingCoins: 70 } } }
			}
			return { data: { success: true, data: CATALOG } }
		})
		const wrapper = mount(BoostShop, { global: { plugins: [pinia] } })
		await flushPromises()

		const magnetCard = wrapper.findAll('.boost-card').find(card => card.text().includes('Magnet'))
		await magnetCard.trigger('click')
		expect(magnetCard.classes()).toContain('selected')
		expect(wrapper.find('.total-cost .cost-value').text()).toBe('30')
		expect(wrapper.find('.remaining-coins span:nth-child(2)').text()).toBe('70')

		await wrapper.find('.btn-primary').trigger('click')
		await flushPromises()
		expect(store.currentCoins).toBe(70)
		expect(store.purchasedBoosts).toEqual(['magnet'])
		// The purchase is confirmed and the shop closes itself.
		expect(wrapper.emitted('purchase')[0]).toEqual(['magnet'])
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('marks unaffordable boosts and refuses selecting them', async () => {
		store.currentCoins = 12
		const wrapper = mount(BoostShop, { global: { plugins: [pinia] } })
		await flushPromises()

		const cards = wrapper.findAll('.boost-card')
		// 12 coins: only the 10-cost shrink is affordable.
		const affordable = cards.find(card => card.classes().includes('affordable'))
		const unaffordable = cards.find(card => card.classes().includes('unaffordable'))
		expect(affordable).toBeTruthy()
		expect(unaffordable).toBeTruthy()

		await unaffordable.trigger('click')
		expect(store.purchasedBoosts).toEqual([])
		expect(wrapper.find('.btn-primary').attributes('disabled')).toBeDefined()
	})

	it('deselects a selected boost and drops it from the total', async () => {
		store.currentCoins = 100
		store.purchasedBoosts = ['magnet']
		const wrapper = mount(BoostShop, { global: { plugins: [pinia] } })
		await flushPromises()

		const magnetCard = wrapper.findAll('.boost-card').find(card => card.text().includes('Magnet'))
		await magnetCard.trigger('click')
		expect(store.purchasedBoosts).toEqual([])
		expect(wrapper.find('.total-cost .cost-value').text()).toBe('0')
	})
})
