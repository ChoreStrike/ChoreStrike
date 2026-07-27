import { describe, it, expect, beforeEach, jest } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'

const mockCurrentCoins = ref(100)
const mockPersonalBest = ref({ score: 42 })
const mockUserRank = ref(1)

const mockMinigameStore = {
	get currentCoins() { return mockCurrentCoins.value },
	set currentCoins(v) { mockCurrentCoins.value = v },
	get personalBest() { return mockPersonalBest.value },
	set personalBest(v) { mockPersonalBest.value = v },
	get userRank() { return mockUserRank.value },
	set userRank(v) { mockUserRank.value = v },
	fetchCoins: jest.fn(async () => null),
	fetchPersonalBest: jest.fn(async () => null),
	fetchUserRank: jest.fn(async () => null),
}
jest.mock('../../../src/store/minigame.js', () => ({
	useMinigameStore: () => mockMinigameStore,
	BOOST_DESCRIPTIONS: {
		slow_motion: { name: 'Slow Motion', icon: '🐌' },
	},
}))

import ArcadeSection from '../../../src/components/Dashboard/ArcadeSection.vue'

describe('ArcadeSection.vue (Phase 5 — 2048 + Memory)', () => {
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		mockCurrentCoins.value = 100
		mockPersonalBest.value = { score: 42 }
		mockUserRank.value = 1
		;[mockMinigameStore.fetchCoins, mockMinigameStore.fetchPersonalBest, mockMinigameStore.fetchUserRank]
			.forEach((fn) => fn.mockClear())
	})

	it('renders all three game cards', () => {
		const wrapper = mount(ArcadeSection, {
			global: { plugins: [pinia] },
			stubs: {
				Modal: { template: '<div class="modal-stub"><slot /></div>' },
				SnakeGame: { template: '<div class="snake-stub" />' },
				Game2048: { template: '<div class="g2048-stub" />' },
				MemoryGame: { template: '<div class="memory-stub" />' },
				SnakeLeaderboard: { template: '<div class="lb-stub" />' },
			},
		})

		expect(wrapper.find('[data-testid="arcade-card-snake"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="arcade-card-2048"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="arcade-card-memory"]').exists()).toBe(true)
	})

	it('renders coins display with testids', () => {
		const wrapper = mount(ArcadeSection, {
			global: { plugins: [pinia] },
			stubs: {
				Modal: { template: '<div><slot /></div>' },
				SnakeGame: { template: '<div />' },
				Game2048: { template: '<div />' },
				MemoryGame: { template: '<div />' },
				SnakeLeaderboard: { template: '<div />' },
			},
		})

		expect(wrapper.find('[data-testid="coins-display"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="coins-value"]').exists()).toBe(true)
	})

	it('renders localised titles for 2048 and Memory', () => {
		const wrapper = mount(ArcadeSection, {
			global: { plugins: [pinia] },
			stubs: {
				Modal: { template: '<div><slot /></div>' },
				SnakeGame: { template: '<div />' },
				Game2048: { template: '<div />' },
				MemoryGame: { template: '<div />' },
				SnakeLeaderboard: { template: '<div />' },
			},
		})

		const text = wrapper.text()
		expect(text).toMatch(/2048/)
		expect(text).toMatch(/Memory/)
	})
})
