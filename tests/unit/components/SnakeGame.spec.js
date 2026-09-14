/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * SnakeGame component suite (CR-053-22, CS-PLAN-068 §3.6): grid rendering,
 * start/pause/game-over flow with the real minigame store and deterministic
 * (fake) timers, keyboard input mapping and score-based coin payout.
 */

import { describe, it, expect, beforeEach, afterEach, jest } from '@jest/globals'
import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import axios from '@nextcloud/axios'
import SnakeGame from '../../../src/components/Minigames/SnakeGame.vue'
import { useMinigameStore } from '../../../src/store/minigame.js'
import { logger } from '@/utils/logger.js'
jest.mock('@/utils/logger.js', () => ({ logger: { error: jest.fn(), warn: jest.fn(), debug: jest.fn() } }))

const OK = (data) => ({ data: { success: true, data } })

describe('SnakeGame', () => {
	enableAutoUnmount(afterEach)
	let pinia
	let store

	beforeEach(async () => {
		pinia = createPinia()
		setActivePinia(pinia)
		store = useMinigameStore()
		jest.clearAllMocks()
		// Food at (2, 2) is off the wall/direction test paths.
		jest.spyOn(Math, 'random').mockReturnValue(0.1)
		// initialize(): coins, catalog, leaderboard, personal best, rank.
		axios.mockResolvedValue(OK({ currentCoins: 55, magnet: 30, ghost_mode: 20, slow_motion: 15, shrink: 10, no_obstacles: 25, rank: 3, score: 120 }))
	})

	afterEach(() => {
		jest.restoreAllMocks()
		jest.useRealTimers()
	})

	it('mounts with the start overlay, full grid and initialized data', async () => {
		const wrapper = mount(SnakeGame, { global: { plugins: [pinia] } })
		await flushPromises()

		expect(wrapper.find('[data-testid="snake-game"]').exists()).toBe(true)
		expect(wrapper.find('.start-overlay').exists()).toBe(true)
		expect(wrapper.find('.game-over-overlay').exists()).toBe(false)
		// 20 × 20 grid cells.
		expect(wrapper.findAll('.grid-cell')).toHaveLength(400)
		expect(wrapper.find('.coins-value').text()).toBe('55')
		// Personal best from the initialize payload.
		expect(wrapper.find('.best-value').text()).toBe('120')
	})

	it('starts the loop on Start Game and pauses/resumes with Space', async () => {
		jest.useFakeTimers()
		const wrapper = mount(SnakeGame, { global: { plugins: [pinia] } })
		await flushPromises()

		await wrapper.find('.start-overlay .btn-primary').trigger('click')
		expect(store.isPlaying).toBe(true)
		expect(wrapper.find('.start-overlay').exists()).toBe(false)

		await wrapper.find('.game-board').trigger('keydown', { code: 'Space' })
		expect(store.isPaused).toBe(true)
		expect(wrapper.find('.pause-overlay').exists()).toBe(true)
		const head = () => wrapper.findAll('.grid-cell').findIndex(cell => cell.classes().includes('snake-head'))
		jest.advanceTimersByTime(900)
		await wrapper.vm.$nextTick()
		expect(head()).toBe(210)

		await wrapper.find('.game-board').trigger('keydown', { code: 'Space' })
		expect(store.isPaused).toBe(false)
		jest.advanceTimersByTime(150)
		await wrapper.vm.$nextTick()
		expect(head()).toBe(211)
	})

	it('advances the snake on the game loop and ends the game at the wall', async () => {
		jest.useFakeTimers()
		const wrapper = mount(SnakeGame, { global: { plugins: [pinia] } })
		await flushPromises()

		await wrapper.find('.start-overlay .btn-primary').trigger('click')
		// Moving right from the center for the full board width hits the
		// wall — no food eaten, so the score stays 0 and NO score save is
		// attempted (the store refuses zero-score saves).
		const postCallsBefore = axios.mock.calls.filter(([{ method }]) => method === 'post').length
		for (let i = 0; i < 25; i++) {
			jest.advanceTimersByTime(160)
			await Promise.resolve()
		}
		expect(store.gameOver).toBe(true)
		expect(wrapper.find('.game-over-overlay').exists()).toBe(true)
		const postCallsAfter = axios.mock.calls.filter(([{ method }]) => method === 'post').length
		expect(postCallsAfter).toBe(postCallsBefore)
	})

	it('maps arrow keys to direction changes and ignores reversals', async () => {
		jest.useFakeTimers()
		const wrapper = mount(SnakeGame, { global: { plugins: [pinia] } })
		await flushPromises()

		await wrapper.find('.start-overlay .btn-primary').trigger('click')
		const head = () => wrapper.findAll('.grid-cell').findIndex(cell => cell.classes().includes('snake-head'))
		await wrapper.find('.game-board').trigger('keydown', { code: 'ArrowUp' })
		jest.advanceTimersByTime(150)
		await wrapper.vm.$nextTick()
		expect(head()).toBe(190)
		await wrapper.find('.game-board').trigger('keydown', { code: 'ArrowDown' })
		jest.advanceTimersByTime(150)
		await wrapper.vm.$nextTick()
		expect(head()).toBe(170)
		expect(store.isPlaying).toBe(true)
	})

	it('saves the score on game over when points were earned', async () => {
		jest.useFakeTimers()
		const wrapper = mount(SnakeGame, { global: { plugins: [pinia] } })
		await flushPromises()

		await wrapper.find('.start-overlay .btn-primary').trigger('click')
		store.score = 120

		jest.advanceTimersByTime(160 * 25)
		await flushPromises()

		expect(store.gameOver).toBe(true)
		const saveCall = axios.mock.calls
			.map(([{ method, url }]) => ({ method, url }))
			.find(({ method, url }) => method === 'post' && url.includes('/minigames/snake/scores'))
		expect(saveCall).toBeTruthy()
	})

	it('opens and closes the boost shop from the start overlay', async () => {
		const wrapper = mount(SnakeGame, { global: { plugins: [pinia] } })
		await flushPromises()

		await wrapper.find('.start-buttons .btn-secondary').trigger('click')
		expect(store.showBoostShop).toBe(true)
		await wrapper.find('.boost-shop .shop-buttons .btn-secondary').trigger('click')
		expect(store.showBoostShop).toBe(false)
	})

	it('clears its running loop on unmount', async () => {
		jest.useFakeTimers()
		const wrapper = mount(SnakeGame, { global: { plugins: [pinia] } })
		await flushPromises()
		await wrapper.find('.start-overlay .btn-primary').trigger('click')
		expect(jest.getTimerCount()).toBeGreaterThan(0)
		wrapper.unmount()
		expect(jest.getTimerCount()).toBe(0)
	})

	it('keeps play available after initialization fails without an unhandled rejection', async () => {
		axios.mockRejectedValue(new Error('offline'))
		const wrapper = mount(SnakeGame, { global: { plugins: [pinia] } })
		await flushPromises()
		expect(store.loading).toBe(false)
		expect(store.boostCatalog).toBeNull()
		expect(wrapper.find('.start-overlay').exists()).toBe(true)
		expect(logger.error).toHaveBeenCalled()
	})

	it('keeps game-over state and records a rejected score save without crediting coins', async () => {
		jest.useFakeTimers()
		const wrapper = mount(SnakeGame, { global: { plugins: [pinia] } })
		await flushPromises()
		await wrapper.find('.start-overlay .btn-primary').trigger('click')
		store.score = 120
		axios.mockRejectedValue(new Error('offline'))
		jest.advanceTimersByTime(4000)
		await flushPromises()
		expect(store.gameOver).toBe(true)
		expect(store.currentCoins).toBe(55)
		expect(wrapper.find('.coins-earned').exists()).toBe(false)
		expect(logger.error).toHaveBeenCalled()
	})

	it('deterministically eats path food rather than assuming random play earns zero', async () => {
		jest.useFakeTimers()
		const wrapper = mount(SnakeGame, { global: { plugins: [pinia] } })
		await flushPromises()
		const coordinates = [0.56, 0.51, 0.1, 0.1, 0.15, 0.15]
		Math.random.mockImplementation(() => coordinates.shift() ?? 0.2)
		await wrapper.find('.start-overlay .btn-primary').trigger('click')
		jest.advanceTimersByTime(150)
		await wrapper.vm.$nextTick()
		expect(store.score).toBe(10)
		expect(wrapper.findAll('.snake-head, .snake-body')).toHaveLength(2)
	})
})
