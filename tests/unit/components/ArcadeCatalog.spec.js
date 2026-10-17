/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach, afterEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ArcadeCatalog from '@/components/Minigames/ArcadeCatalog.vue'
import { GAMES } from '@/components/Minigames/gameRegistry.js'

jest.mock('@/services/api.js', () => ({
	api: { get: jest.fn(), post: jest.fn() },
}))

jest.mock('@/utils/logger', () => ({
	logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() },
}))

const { api } = require('@/services/api.js')

/**
 * CS-PLAN-086 §3.4: one card per registered game with its own best and
 * rank; a game opens in the shared Modal; the catalog loads no game code.
 */
describe('ArcadeCatalog', () => {
	let wrapper

	const mountCatalog = async () => {
		wrapper = mount(ArcadeCatalog, {
			attachTo: document.body,
			global: {
				plugins: [createPinia()],
				stubs: {
					GameShell: { props: ['gameId'], template: '<div data-testid="shell-stub" :data-game-id="gameId" />' },
					GameLeaderboard: { props: ['gameId'], template: '<div data-testid="board-stub" :data-game-id="gameId" />' },
				},
			},
		})
		await flushPromises()
		return wrapper
	}

	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
		api.get.mockImplementation(async (url) => (url.endsWith('/rank') ? { rank: 3 } : { score: 420 }))
	})

	afterEach(() => {
		wrapper?.unmount()
	})

	it('renders one card per registered game with best and rank, loading no game code', async () => {
		const loads = GAMES.map((game) => jest.spyOn(game, 'load'))
		await mountCatalog()

		for (const game of GAMES) {
			const card = wrapper.get(`[data-testid="arcade-card-${game.id}"]`)
			expect(card.text()).toContain(game.name())
			expect(card.text()).toContain(game.description())
			expect(wrapper.get(`[data-testid="arcade-best-${game.id}"]`).text()).toBe('420')
			expect(card.text()).toContain('#3')
		}
		expect(wrapper.text()).toContain('Crumb Snake')
		loads.forEach((spy) => {
			expect(spy).not.toHaveBeenCalled()
			spy.mockRestore()
		})
	})

	it('opens the game in a labelled dialog and closes it again', async () => {
		await mountCatalog()

		await wrapper.get('[data-testid="arcade-play-snake"]').trigger('click')

		const dialog = document.querySelector('[role="dialog"]')
		expect(dialog.getAttribute('aria-label')).toBe('Crumb Snake')
		expect(document.querySelector('[data-testid="shell-stub"]').dataset.gameId).toBe('snake')

		document.querySelector('[data-testid="modal-close"]').click()
		await flushPromises()
		expect(document.querySelector('[data-testid="shell-stub"]')).toBeNull()
	})

	it('opens the per-game leaderboard', async () => {
		await mountCatalog()
		await wrapper.get('[data-testid="arcade-leaderboard-snake"]').trigger('click')
		expect(wrapper.get('[data-testid="board-stub"]').attributes('data-game-id')).toBe('snake')
	})

	it('shows a neutral retry note when the scores cannot be loaded', async () => {
		api.get.mockRejectedValue(new Error('offline'))
		await mountCatalog()

		expect(wrapper.get('[data-testid="arcade-catalog-error"]').text()).toContain('Scores could not be loaded.')
		expect(wrapper.get('[data-testid="arcade-best-snake"]').text()).toBe('0')

		api.get.mockResolvedValue({ score: 5 })
		await wrapper.get('[data-testid="arcade-catalog-error"] button').trigger('click')
		await flushPromises()
		expect(wrapper.find('[data-testid="arcade-catalog-error"]').exists()).toBe(false)
	})

	it('keeps the load note off when a later save inside a game fails', async () => {
		await mountCatalog()
		const { useMinigameStore } = require('@/store/minigame.js')
		useMinigameStore().error = 'Too many score submissions today. Try again tomorrow.'
		await flushPromises()

		expect(wrapper.find('[data-testid="arcade-catalog-error"]').exists()).toBe(false)
	})

	it('never shows a Coin, wallet or boost surface', async () => {
		await mountCatalog()
		expect(wrapper.text()).not.toMatch(/coin|wallet|boost/i)
	})
})
