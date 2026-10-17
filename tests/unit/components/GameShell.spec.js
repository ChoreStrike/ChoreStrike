/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach, afterEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import GameShell from '@/components/Minigames/GameShell.vue'
import { STEP_MS } from '@/composables/useGameLoop.js'
import { installAnimationFrames } from '../helpers/gameTestHelpers.js'

// A scripted game module: the test drives score, events and game over
// through the state, and `step` records every input it receives.
const mockGame = {
	cols: 20,
	rows: 20,
	createState: jest.fn(() => ({ score: 0, over: false, events: [], inputs: [] })),
	step: jest.fn((state, input) => {
		if (input) state.inputs.push(input)
		return state
	}),
	render: jest.fn(),
}
jest.mock('@/components/Minigames/games/snake/game.js', () => mockGame)

const mockEffects = { pickup: jest.fn(), newPersonalBest: jest.fn(), motionAllowed: jest.fn(() => false) }
jest.mock('@/composables/useGameEffects.js', () => ({ useGameEffects: () => mockEffects }))

jest.mock('@/services/api.js', () => ({
	api: { get: jest.fn(), post: jest.fn() },
}))

jest.mock('@/utils/logger', () => ({
	logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() },
}))

const { api } = require('@/services/api.js')
const { logger } = require('@/utils/logger')

/**
 * CS-PLAN-086 §3.4 GameShell: input on the shell element only (Snake
 * defects S1/S2), overlays, accessibility, auto-pause (S3) and the save
 * with the active play time (D-086-04).
 */
describe('GameShell', () => {
	let frames
	let wrappers
	let originalGetContext

	const currentState = () => mockGame.createState.mock.results.at(-1).value
	const phase = (wrapper) => wrapper.get('[data-testid="game-shell"]').attributes('data-phase')
	const announcement = (wrapper) => wrapper.get('[data-testid="game-announcer"]').text()

	const mountShell = async (gameId = 'snake') => {
		const wrapper = mount(GameShell, {
			props: { gameId },
			attachTo: document.body,
			global: { plugins: [createPinia()], stubs: { GameLeaderboard: { template: '<div data-testid="leaderboard-stub" />' } } },
		})
		wrappers.push(wrapper)
		await flushPromises()
		return wrapper
	}

	const start = async (wrapper) => {
		await wrapper.get('[data-testid="game-start"]').trigger('click')
		frames.frame(0)
	}

	/** Run `count` fixed steps, one frame each. */
	let clock = 0
	const advance = (count = 1) => {
		for (let i = 0; i < count; i++) {
			clock += STEP_MS + 0.001
			frames.frame(clock)
		}
	}

	const touch = (element, type, x, y) => {
		const event = new Event(type, { bubbles: true, cancelable: true })
		event.changedTouches = [{ clientX: x, clientY: y }]
		element.dispatchEvent(event)
		return event
	}

	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
		frames = installAnimationFrames()
		wrappers = []
		clock = 0
		originalGetContext = HTMLCanvasElement.prototype.getContext
		HTMLCanvasElement.prototype.getContext = jest.fn(() => ({ setTransform: jest.fn() }))
		api.get.mockResolvedValue(null)
		api.post.mockResolvedValue({ id: 1 })
	})

	afterEach(() => {
		wrappers.forEach((wrapper) => wrapper.unmount())
		frames.restore()
		HTMLCanvasElement.prototype.getContext = originalGetContext
		Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' })
	})

	it('loads the game lazily and offers the start overlay with an accessible frame', async () => {
		const wrapper = await mountShell()
		const shell = wrapper.get('[data-testid="game-shell"]')

		expect(phase(wrapper)).toBe('ready')
		expect(shell.attributes('role')).toBe('application')
		expect(shell.attributes('aria-label')).toBe('Crumb Snake')
		expect(shell.attributes('tabindex')).toBe('0')
		expect(wrapper.get('canvas').attributes('aria-hidden')).toBe('true')
		expect(wrapper.get('[data-testid="game-announcer"]').attributes('aria-live')).toBe('polite')
		expect(wrapper.get('[data-testid="game-start"]').text()).toBe('Start Game')
		expect(document.activeElement).toBe(wrapper.get('[data-testid="game-start"]').element)
		expect(mockGame.render).toHaveBeenCalled()
	})

	it('S1: handles a key press exactly once (one input, Space pauses without resuming)', async () => {
		const wrapper = await mountShell()
		await start(wrapper)
		const shell = wrapper.get('[data-testid="game-shell"]')

		await shell.trigger('keydown', { code: 'ArrowUp' })
		advance(3)
		expect(currentState().inputs).toEqual(['up'])

		await shell.trigger('keydown', { code: 'Space' })
		expect(phase(wrapper)).toBe('paused')
		expect(announcement(wrapper)).toBe('Paused')
	})

	it('S2: captures nothing page-wide — keys outside the shell are never prevented or played', async () => {
		const wrapper = await mountShell()
		const outside = new KeyboardEvent('keydown', { code: 'ArrowDown', bubbles: true, cancelable: true })
		document.body.dispatchEvent(outside)
		expect(outside.defaultPrevented).toBe(false)

		await start(wrapper)
		const during = new KeyboardEvent('keydown', { code: 'Space', bubbles: true, cancelable: true })
		document.body.dispatchEvent(during)
		advance()

		expect(during.defaultPrevented).toBe(false)
		expect(phase(wrapper)).toBe('playing')
		expect(currentState().inputs).toEqual([])
	})

	it('leaves arrow keys alone before a round starts', async () => {
		const wrapper = await mountShell()
		const event = new KeyboardEvent('keydown', { code: 'ArrowUp', bubbles: true, cancelable: true })
		wrapper.get('[data-testid="game-shell"]').element.dispatchEvent(event)
		expect(event.defaultPrevented).toBe(false)
	})

	it('lets a focused overlay button keep its own Space activation', async () => {
		const wrapper = await mountShell()
		await start(wrapper)
		await wrapper.get('[data-testid="game-pause"]').trigger('click')
		await flushPromises()
		expect(document.activeElement).toBe(wrapper.get('[data-testid="game-resume"]').element)

		await wrapper.get('[data-testid="game-resume"]').trigger('keydown', { code: 'Space' })
		expect(phase(wrapper)).toBe('paused')

		await wrapper.get('[data-testid="game-shell"]').trigger('keydown', { code: 'KeyP' })
		expect(phase(wrapper)).toBe('playing')
		expect(announcement(wrapper)).toBe('Resumed')
	})

	it('maps swipes and on-screen buttons to inputs and ignores taps', async () => {
		const wrapper = await mountShell()
		await start(wrapper)
		const board = wrapper.get('.game-shell-board').element

		touch(board, 'touchstart', 100, 100)
		const swipe = touch(board, 'touchend', 160, 105)
		touch(board, 'touchstart', 100, 100)
		touch(board, 'touchend', 104, 102)
		await wrapper.get('[aria-label="Up"]').trigger('click')
		advance(3)

		expect(swipe.defaultPrevented).toBe(true)
		expect(currentState().inputs).toEqual(['right', 'up'])
		expect(wrapper.findAll('[data-testid="game-dpad"] button')).toHaveLength(4)
	})

	it('forwards pickups to the opt-in effects and drains the events', async () => {
		const wrapper = await mountShell()
		await start(wrapper)
		currentState().events.push({ type: 'pickup', kind: 'bonus', x: 1, y: 2 }, { type: 'crash' })
		currentState().score = 30
		advance()
		await flushPromises()

		expect(mockEffects.pickup).toHaveBeenCalledWith('bonus')
		expect(currentState().events).toEqual([])
		expect(wrapper.get('[data-testid="game-score"]').text()).toBe('30')
	})

	it('saves the result with the active play time and celebrates a new best', async () => {
		const wrapper = await mountShell()
		await start(wrapper)
		advance(30)
		currentState().score = 120
		currentState().over = true
		advance()
		await flushPromises()

		expect(api.post).toHaveBeenCalledWith('/api/v1/minigames/snake/scores', { score: 120, durationMs: 517 })
		expect(phase(wrapper)).toBe('over')
		expect(wrapper.get('[data-testid="game-final-score"]').text()).toBe('Score: 120')
		expect(wrapper.find('[data-testid="game-new-best"]').exists()).toBe(true)
		expect(mockEffects.newPersonalBest).toHaveBeenCalledTimes(1)
		expect(announcement(wrapper)).toBe('New personal best: 120')
		expect(document.activeElement).toBe(wrapper.get('[data-testid="game-restart"]').element)
	})

	it('shows a neutral note when the save fails', async () => {
		api.post.mockRejectedValue(new Error('rate limited'))
		const wrapper = await mountShell()
		await start(wrapper)
		currentState().score = 10
		currentState().over = true
		advance()
		await flushPromises()

		expect(wrapper.find('[data-testid="game-save-failed"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="game-new-best"]').exists()).toBe(false)
		expect(announcement(wrapper)).toBe('Game over. Score: 10')
	})

	it('starts a fresh round from game over and opens the per-game leaderboard', async () => {
		const wrapper = await mountShell()
		await start(wrapper)
		currentState().over = true
		advance()
		await flushPromises()

		await wrapper.get('[data-testid="game-open-leaderboard"]').trigger('click')
		const board = wrapper.get('[data-testid="leaderboard-stub"]').element
		// Outside the application region, so a screen reader can browse it.
		expect(board.closest('[role="application"]')).toBeNull()

		await wrapper.get('[data-testid="game-restart"]').trigger('click')
		expect(phase(wrapper)).toBe('playing')
		expect(mockGame.createState).toHaveBeenCalledTimes(3)
		expect(wrapper.get('[data-testid="game-score"]').text()).toBe('0')
		expect(api.post).not.toHaveBeenCalled()
	})

	it('S3: pauses when the page is hidden', async () => {
		const wrapper = await mountShell()
		await start(wrapper)

		Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' })
		document.dispatchEvent(new Event('visibilitychange'))
		await flushPromises()

		expect(phase(wrapper)).toBe('paused')
		expect(wrapper.find('[data-testid="game-resume"]').exists()).toBe(true)
	})

	it('shows a failure state for an unknown game', async () => {
		const wrapper = await mountShell('pacman')

		expect(phase(wrapper)).toBe('failed')
		expect(logger.warn).toHaveBeenCalledWith('Unknown arcade game', { gameId: 'pacman' })
		await wrapper.get('[data-testid="game-retry"]').trigger('click')
		expect(phase(wrapper)).toBe('failed')
	})

	it('stops the loop when it unmounts', async () => {
		const wrapper = await mountShell()
		await start(wrapper)
		expect(frames.pending()).toBe(1)

		wrapper.unmount()
		wrappers = []

		expect(frames.pending()).toBe(0)
	})
})
