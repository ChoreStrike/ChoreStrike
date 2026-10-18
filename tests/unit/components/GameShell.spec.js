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

// A scripted DOM game (Spot Sprint's interface): a view snapshot per
// revision, a board component that reports taps, and a timed round.
const mockDomGame = {
	cols: 4,
	rows: 4,
	roundMs: 60000,
	createState: jest.fn(() => ({ score: 0, over: false, events: [], inputs: [], revision: 0, timeLeftMs: 60000 })),
	step: jest.fn((state, input) => {
		if (input) state.inputs.push(input)
		return state
	}),
	view: jest.fn((state) => ({ revision: state.revision })),
	Board: {
		props: ['view', 'cols', 'motion'],
		emits: ['tap'],
		template: `<div data-testid="dom-board" :data-revision="view.revision" @click="$emit('tap', 6)" />`,
	},
}
jest.mock('@/components/Minigames/games/spots/game.js', () => mockDomGame)

// An untimed DOM game (Sock Match's interface): no `roundMs`, and its
// registry entry names the end of a round.
const mockUntimedGame = {
	...mockDomGame,
	roundMs: undefined,
	createState: jest.fn(() => ({ score: 0, over: false, events: [], inputs: [], revision: 0 })),
}
jest.mock('@/components/Minigames/games/socks/game.js', () => mockUntimedGame)

// A scripted paddle game (Tile Breaker's interface): a canvas game whose
// state carries lives and whose events name points, not cells.
const mockPaddleGame = {
	cols: 16,
	rows: 20,
	eventUnit: 'point',
	createState: jest.fn(() => ({ score: 0, over: false, events: [], inputs: [], lives: 3 })),
	step: jest.fn((state, input) => {
		if (input) state.inputs.push(input)
		return state
	}),
	render: jest.fn(),
}
jest.mock('@/components/Minigames/games/tiles/game.js', () => mockPaddleGame)

const mockEffects = { pickup: jest.fn(), event: jest.fn(), newPersonalBest: jest.fn(), motionAllowed: jest.fn(() => false) }
jest.mock('@/composables/useGameEffects.js', () => ({ useGameEffects: () => mockEffects }))

// The board effects layer (WP-G): the shell's calls are the subject here.
const mockFx = {
	react: jest.fn(),
	score: jest.fn(),
	update: jest.fn(),
	draw: jest.fn(),
	offset: jest.fn(() => ({ x: 0, y: 0 })),
	clear: jest.fn(),
}
jest.mock('@/components/Minigames/effectsLayer.js', () => ({ createEffectsLayer: () => mockFx }))

jest.mock('@/utils/sound.js', () => ({ unlockAudio: jest.fn(), playSound: jest.fn(), setSoundEnabled: jest.fn() }))
jest.mock('@/utils/errorToast.js', () => ({ showErrorWithReference: jest.fn() }))

jest.mock('@/services/api.js', () => ({
	api: { get: jest.fn(), post: jest.fn() },
	fetchMyPreferences: jest.fn(),
	updateMyPreferences: jest.fn(),
}))

jest.mock('@/utils/logger', () => ({
	logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() },
}))

const { api, updateMyPreferences } = require('@/services/api.js')
const { logger } = require('@/utils/logger')
const { unlockAudio } = require('@/utils/sound.js')
const { showErrorWithReference } = require('@/utils/errorToast.js')

/** A server preferences payload (the store replaces its state with it). */
const serverPreferences = (overrides = {}) => ({
	version: 1,
	taskDigestEnabled: false,
	orgDigestEnabled: false,
	digestTime: '18:00',
	quietStart: null,
	quietEnd: null,
	soundEnabled: false,
	animationEnabled: false,
	arcadeVisible: true,
	catalogVisible: true,
	ranking: { state: 'none', generation: null, effectiveMonday: null },
	...overrides,
})

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
		mockEffects.motionAllowed.mockReturnValue(false)
		updateMyPreferences.mockResolvedValue(serverPreferences())
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
		expect(wrapper.get('[data-testid="game-over-title"]').text()).toBe('Game Over!')
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

	describe('DOM games (tap input, timed and untimed rounds)', () => {
		const domState = () => mockDomGame.createState.mock.results.at(-1).value

		it('renders the game board instead of a canvas and shows the time left', async () => {
			const wrapper = await mountShell('spots')

			expect(wrapper.find('canvas').exists()).toBe(false)
			expect(wrapper.find('[data-testid="dom-board"]').exists()).toBe(true)
			expect(wrapper.get('[data-testid="game-time"]').text()).toBe('60')
			expect(wrapper.find('[data-testid="game-dpad"]').exists()).toBe(false)

			await start(wrapper)
			domState().timeLeftMs = 41200
			advance()
			await flushPromises()
			expect(wrapper.get('[data-testid="game-time"]').text()).toBe('42')
		})

		it('re-reads the view only when the revision changes', async () => {
			const wrapper = await mountShell('spots')
			await start(wrapper)
			const reads = mockDomGame.view.mock.calls.length

			advance(3)
			expect(mockDomGame.view.mock.calls.length).toBe(reads)

			domState().revision = 4
			advance()
			await flushPromises()
			expect(mockDomGame.view.mock.calls.length).toBe(reads + 1)
			expect(wrapper.get('[data-testid="dom-board"]').attributes('data-revision')).toBe('4')
		})

		it('turns taps into tile inputs and Enter into an action, and ignores swipes', async () => {
			const wrapper = await mountShell('spots')
			await start(wrapper)
			const shell = wrapper.get('[data-testid="game-shell"]')

			await wrapper.get('[data-testid="dom-board"]').trigger('click')
			const enter = new KeyboardEvent('keydown', { code: 'Enter', bubbles: true, cancelable: true })
			shell.element.dispatchEvent(enter)
			const board = wrapper.get('.game-shell-board').element
			touch(board, 'touchstart', 100, 100)
			touch(board, 'touchend', 180, 100)
			advance(4)

			expect(enter.defaultPrevented).toBe(true)
			expect(domState().inputs).toEqual([{ cell: 6 }, 'act'])
		})

		it('shows no clock for an untimed game and names the end its entry gives', async () => {
			api.post.mockRejectedValue(new Error('offline'))
			const wrapper = await mountShell('socks')
			expect(wrapper.find('[data-testid="dom-board"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="game-time"]').exists()).toBe(false)

			await start(wrapper)
			const state = mockUntimedGame.createState.mock.results.at(-1).value
			state.score = 640
			state.over = true
			advance()
			await flushPromises()

			expect(wrapper.find('[data-testid="game-time"]').exists()).toBe(false)
			expect(wrapper.get('[data-testid="game-over-title"]').text()).toBe('All pairs found!')
			expect(wrapper.get('[data-testid="game-final-score"]').text()).toBe('Score: 640')
			expect(announcement(wrapper)).toBe('All pairs found. Score: 640')
		})

		it('leaves Enter to a focused button', async () => {
			const wrapper = await mountShell('spots')
			await start(wrapper)
			const pause = wrapper.get('[data-testid="game-pause"]')
			const enter = new KeyboardEvent('keydown', { code: 'Enter', bubbles: true, cancelable: true })

			pause.element.dispatchEvent(enter)
			advance()

			expect(enter.defaultPrevented).toBe(false)
			expect(domState().inputs).toEqual([])
		})
	})

	describe('paddle games (held keys, pointer, lives)', () => {
		const paddleState = () => mockPaddleGame.createState.mock.results.at(-1).value
		const key = async (wrapper, type, code) => {
			const event = new KeyboardEvent(type, { code, bubbles: true, cancelable: true })
			wrapper.get('[data-testid="game-shell"]').element.dispatchEvent(event)
			advance()
			await flushPromises()
			return event
		}
		const pointAt = (wrapper, clientX) => {
			const board = wrapper.get('.game-shell-board').element
			board.getBoundingClientRect = () => ({ left: 100, width: 200, top: 0, height: 250 })
			board.dispatchEvent(new MouseEvent('pointermove', { clientX, bubbles: true }))
		}

		it('shows the lives of the state and keeps them current; a game without lives shows none', async () => {
			const wrapper = await mountShell('tiles')
			expect(wrapper.get('[data-testid="game-lives"]').text()).toBe('3')

			await start(wrapper)
			paddleState().lives = 2
			advance()
			await flushPromises()
			expect(wrapper.get('[data-testid="game-lives"]').text()).toBe('2')

			const snake = await mountShell('snake')
			expect(snake.find('[data-testid="game-lives"]').exists()).toBe(false)
		})

		it('turns held arrow keys into steer changes, never key repeats', async () => {
			const wrapper = await mountShell('tiles')
			await start(wrapper)

			const left = await key(wrapper, 'keydown', 'ArrowLeft')
			await key(wrapper, 'keydown', 'ArrowLeft')
			await key(wrapper, 'keydown', 'ArrowRight')
			await key(wrapper, 'keyup', 'ArrowLeft')
			await key(wrapper, 'keyup', 'ArrowRight')
			const up = await key(wrapper, 'keydown', 'ArrowUp')

			expect(left.defaultPrevented).toBe(true)
			expect(up.defaultPrevented).toBe(true)
			expect(paddleState().inputs).toEqual([{ steer: -1 }, { steer: 0 }, { steer: 1 }, { steer: 0 }])
			expect(wrapper.find('[data-testid="game-dpad"]').exists()).toBe(false)
		})

		it('follows the pointer as a share of the board width; only the latest control waits', async () => {
			const wrapper = await mountShell('tiles')
			await start(wrapper)

			pointAt(wrapper, 150)
			advance()
			pointAt(wrapper, 250)
			wrapper.get('[data-testid="game-shell"]').element.dispatchEvent(new KeyboardEvent('keydown', { code: 'Enter', bubbles: true, cancelable: true }))
			pointAt(wrapper, 200)
			advance(3)

			expect(paddleState().inputs).toEqual([{ aim: 0.25 }, 'act', { aim: 0.5 }])
		})

		it('starts still after a pause, whatever keys were released meanwhile', async () => {
			const wrapper = await mountShell('tiles')
			await start(wrapper)
			await key(wrapper, 'keydown', 'ArrowRight')

			await key(wrapper, 'keydown', 'Space')
			expect(phase(wrapper)).toBe('paused')
			await key(wrapper, 'keyup', 'ArrowRight')
			await key(wrapper, 'keydown', 'KeyP')
			advance()

			expect(phase(wrapper)).toBe('playing')
			expect(paddleState().inputs).toEqual([{ steer: 1 }, { steer: 0 }])
		})

		it('ignores the pointer in other games, where arrow keys stay single moves', async () => {
			const wrapper = await mountShell('snake')
			await start(wrapper)

			pointAt(wrapper, 150)
			await key(wrapper, 'keydown', 'ArrowLeft')
			await key(wrapper, 'keyup', 'ArrowLeft')
			advance(2)

			expect(currentState().inputs).toEqual(['left'])
		})
	})

	it('never grows the board back for a width its own scrollbar took (no resize loop)', async () => {
		const viewportWidth = window.innerWidth
		let stageWidth = 400
		Object.defineProperty(HTMLElement.prototype, 'clientWidth', { configurable: true, get: () => stageWidth })
		try {
			const wrapper = await mountShell()
			const boardWidth = () => wrapper.get('.game-shell-board').element.style.width
			window.dispatchEvent(new Event('resize'))
			await flushPromises()
			expect(boardWidth()).toBe('400px')

			// The modal's scrollbar appears: the board shrinks one cell.
			stageWidth = 385
			window.dispatchEvent(new Event('resize'))
			await flushPromises()
			expect(boardWidth()).toBe('380px')

			// The scrollbar disappears again: no growth for the same viewport.
			stageWidth = 400
			window.dispatchEvent(new Event('resize'))
			await flushPromises()
			expect(boardWidth()).toBe('380px')

			// A real viewport change sizes the board afresh.
			window.innerWidth = viewportWidth + 100
			window.dispatchEvent(new Event('resize'))
			await flushPromises()
			expect(boardWidth()).toBe('400px')
		} finally {
			window.innerWidth = viewportWidth
			// The stub shadows Element.prototype.clientWidth; drop it again.
			delete HTMLElement.prototype.clientWidth
		}
	})

	describe('effects and switches (WP-G)', () => {
		it('plays the start sound, tells the effects about every event and keeps the board still without the motion opt-in', async () => {
			const wrapper = await mountShell()
			await start(wrapper)
			expect(mockEffects.event).toHaveBeenCalledWith('start')
			expect(mockFx.clear).toHaveBeenCalledTimes(1)

			currentState().events.push({ type: 'miss' }, { type: 'pickup', kind: 'normal', x: 1, y: 2 })
			currentState().score = 10
			advance()

			expect(mockEffects.event).toHaveBeenCalledWith('miss')
			expect(mockEffects.pickup).toHaveBeenCalledWith('normal')
			expect(mockFx.react).not.toHaveBeenCalled()
			expect(mockFx.score).not.toHaveBeenCalled()
			const view = mockGame.render.mock.calls.at(-1)[2]
			expect(view.motion).toBe(false)
			expect(typeof view.time).toBe('number')
			expect(view.cell).toBe(view.width / 20)
			expect(mockFx.draw).toHaveBeenCalled()
		})

		it('bursts, shakes and floats the score on the board while motion is allowed, at cell centers', async () => {
			mockEffects.motionAllowed.mockReturnValue(true)
			const wrapper = await mountShell()
			await start(wrapper)
			currentState().events.push({ type: 'pickup', kind: 'bonus', x: 1, y: 2 }, { type: 'miss' })
			mockGame.step.mockImplementationOnce((state) => {
				state.score = 30
				return state
			})
			advance()

			expect(mockFx.react).toHaveBeenCalledTimes(2)
			expect(mockFx.react.mock.calls[0]).toEqual([{ type: 'pickup', kind: 'bonus', x: 1, y: 2 }, 1.5, 2.5, expect.any(Object), { x: 10, y: 10 }])
			expect(mockFx.react.mock.calls[1][0]).toEqual({ type: 'miss' })
			expect(mockFx.score).toHaveBeenCalledWith(1.5, 2.5, 30, expect.any(Object))
			expect(mockGame.render.mock.calls.at(-1)[2].motion).toBe(true)
		})

		it('takes the event points of a paddle game as they are', async () => {
			mockEffects.motionAllowed.mockReturnValue(true)
			const wrapper = await mountShell('tiles')
			await start(wrapper)
			const state = mockPaddleGame.createState.mock.results.at(-1).value
			state.events.push({ type: 'pickup', kind: 'normal', x: 3, y: 4.4 })
			advance()

			expect(mockFx.react.mock.calls[0].slice(1, 3)).toEqual([3, 4.4])
		})

		it('shows both opt-ins off by default and writes the personal preference when switched', async () => {
			updateMyPreferences.mockResolvedValue(serverPreferences({ soundEnabled: true }))
			const wrapper = await mountShell()
			const sound = wrapper.get('[data-testid="game-sound-toggle"]')
			expect(sound.attributes('aria-pressed')).toBe('false')
			expect(wrapper.get('[data-testid="game-effects-toggle"]').attributes('aria-pressed')).toBe('false')

			await sound.trigger('click')
			await flushPromises()

			expect(unlockAudio).toHaveBeenCalledTimes(1)
			expect(updateMyPreferences).toHaveBeenCalledWith(expect.objectContaining({ soundEnabled: true, expectedVersion: 0 }))
			expect(wrapper.get('[data-testid="game-sound-toggle"]').attributes('aria-pressed')).toBe('true')
		})

		it('keeps the switches focusable during a save and ignores a second click meanwhile (R086G-01)', async () => {
			let finish
			updateMyPreferences.mockReturnValue(new Promise((resolve) => { finish = resolve }))
			const wrapper = await mountShell()
			const sound = wrapper.get('[data-testid="game-sound-toggle"]')
			sound.element.focus()

			await sound.trigger('click')
			await sound.trigger('click')
			await flushPromises()

			expect(updateMyPreferences).toHaveBeenCalledTimes(1)
			expect(sound.attributes('disabled')).toBeUndefined()
			expect(sound.classes()).toContain('is-saving')
			expect(document.activeElement).toBe(sound.element)

			finish(serverPreferences({ soundEnabled: true }))
			await flushPromises()
			expect(wrapper.get('[data-testid="game-sound-toggle"]').classes()).not.toContain('is-saving')
			expect(wrapper.get('[data-testid="game-sound-toggle"]').attributes('aria-pressed')).toBe('true')
		})

		it('switching effects on re-reads the motion gate for the board', async () => {
			updateMyPreferences.mockResolvedValue(serverPreferences({ animationEnabled: true }))
			const wrapper = await mountShell()
			mockEffects.motionAllowed.mockClear()

			await wrapper.get('[data-testid="game-effects-toggle"]').trigger('click')
			await flushPromises()

			expect(updateMyPreferences).toHaveBeenCalledWith(expect.objectContaining({ animationEnabled: true }))
			expect(unlockAudio).not.toHaveBeenCalled()
			expect(mockEffects.motionAllowed).toHaveBeenCalled()
			expect(wrapper.get('[data-testid="game-effects-toggle"]').attributes('aria-pressed')).toBe('true')
		})

		it('keeps the server state and shows a toast when the switch cannot be saved', async () => {
			updateMyPreferences.mockRejectedValue(new Error('offline'))
			const wrapper = await mountShell()

			await wrapper.get('[data-testid="game-sound-toggle"]').trigger('click')
			await flushPromises()

			expect(showErrorWithReference).toHaveBeenCalledWith('The setting could not be saved.', expect.any(Error))
			expect(wrapper.get('[data-testid="game-sound-toggle"]').attributes('aria-pressed')).toBe('false')
		})

		it('offers the switches on the start, pause and game-over overlays, never during play', async () => {
			const wrapper = await mountShell()
			expect(wrapper.find('[data-testid="game-effect-toggles"]').exists()).toBe(true)

			await start(wrapper)
			expect(wrapper.find('[data-testid="game-effect-toggles"]').exists()).toBe(false)

			await wrapper.get('[data-testid="game-pause"]').trigger('click')
			expect(wrapper.find('[data-testid="game-effect-toggles"]').exists()).toBe(true)
		})

		it('shows lives as hearts, keeps the number for assistive technology and colors the last seconds', async () => {
			const wrapper = await mountShell('tiles')
			expect(wrapper.get('[data-testid="game-lives"]').text()).toBe('3')
			expect(wrapper.get('[data-testid="game-lives"]').classes()).toContain('cs-visually-hidden')
			expect(wrapper.get('.game-shell-hearts').text()).toBe('♥♥♥')

			const spots = await mountShell('spots')
			await start(spots)
			expect(spots.find('.is-urgent').exists()).toBe(false)
			mockDomGame.createState.mock.results.at(-1).value.timeLeftMs = 4200
			advance()
			await flushPromises()
			expect(spots.get('[data-testid="game-time"]').text()).toBe('5')
			expect(spots.find('.is-urgent').exists()).toBe(true)
		})
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
