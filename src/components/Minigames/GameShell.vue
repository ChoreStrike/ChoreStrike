<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="game-shell-frame">
		<section ref="shellRef"
			class="game-shell"
			role="application"
			:aria-label="title"
			tabindex="0"
			data-testid="game-shell"
			:data-game-id="gameId"
			:data-phase="phase"
			@keydown="onKeyDown">
			<header class="game-shell-header">
				<div class="game-shell-stat">
					<span class="game-shell-stat-label">{{ t('chorestrike', 'Score') }}</span>
					<span class="game-shell-stat-value" data-testid="game-score">{{ score }}</span>
				</div>
				<div v-if="timeLeft !== null" class="game-shell-stat">
					<span class="game-shell-stat-label">{{ t('chorestrike', 'Time') }}</span>
					<span class="game-shell-stat-value" data-testid="game-time">{{ timeLeft }}</span>
				</div>
				<button v-if="phase === 'playing'"
					type="button"
					class="game-shell-button"
					data-testid="game-pause"
					@click="pauseGame">
					{{ t('chorestrike', 'Pause') }}
				</button>
				<div class="game-shell-stat">
					<span class="game-shell-stat-label">{{ t('chorestrike', 'Best') }}</span>
					<span class="game-shell-stat-value" data-testid="game-best">{{ bestScore }}</span>
				</div>
			</header>

			<div ref="stageRef" class="game-shell-stage">
				<div class="game-shell-board"
					:class="{ 'is-playing': phase === 'playing', 'is-unsized': board.width === 0 }"
					:style="board.width ? { width: `${board.width}px`, height: `${board.height}px` } : null"
					@click="focusShell"
					@touchstart.passive="onTouchStart"
					@touchend="onTouchEnd">
					<canvas v-if="!isDom"
						ref="canvasRef"
						class="game-shell-canvas"
						aria-hidden="true" />
					<component :is="game.Board"
						v-else-if="game && boardView"
						:view="boardView"
						:cols="game.cols"
						:motion="motion"
						@tap="onTileTap" />
					<GameShellOverlay v-if="phase !== 'playing'"
						ref="overlayRef"
						:phase="phase"
						:entry="entry"
						:score="score"
						:result="result"
						@start="startGame"
						@resume="resumeGame"
						@retry="loadGame"
						@leaderboard="showLeaderboard = true" />
				</div>
			</div>

			<GameShellDpad v-if="entry && entry.input === 'dpad'" @direction="queueInput" />

			<p class="cs-visually-hidden" aria-live="polite" data-testid="game-announcer">
				{{ announcement }}
			</p>
		</section>

		<!-- Outside the application region: the board dialog stays browsable. -->
		<GameLeaderboard v-if="showLeaderboard" :game-id="gameId" @close="showLeaderboard = false" />
	</div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useMinigameStore } from '../../store/minigame.js'
import { useGameLoop } from '../../composables/useGameLoop.js'
import { useGameEffects } from '../../composables/useGameEffects.js'
import { logger } from '../../utils/logger.js'
import { findGame } from './gameRegistry.js'
import { canvasPixelRatio, fitBoard, readBoardPalette } from './board.js'
import { keyAction, swipeDirection } from './gameInput.js'
import GameShellOverlay from './GameShellOverlay.vue'
import GameShellDpad from './GameShellDpad.vue'
import GameLeaderboard from './GameLeaderboard.vue'

/**
 * Shared frame of every arcade game (CS-PLAN-086 §3.4): score header,
 * overlays, keyboard on this element only (S1/S2), swipe and on-screen
 * controls, a responsive canvas with the pixel ratio capped at 2 (S4), a
 * labelled application region with a polite live region (S5), pause on
 * hide (S3) and the result save with the active play time (D-086-04).
 * The game itself is a lazy module: `{cols, rows, createState, step}` plus
 * `render` for a canvas game, or `view` and a `Board` component for a DOM
 * game (registry `renderer: 'dom'`, tap input; the board re-renders only
 * when `state.revision` changes). A game with `roundMs` shows its time left;
 * an entry may name its end (`overTitle`, `overAnnouncement`).
 */
const props = defineProps({
	gameId: {
		type: String,
		required: true,
	},
})

const SPARKLE_MS = 400
const MAX_QUEUED_INPUTS = 4
const BOARD_HEIGHT_SHARE = 0.6
const store = useMinigameStore()
const effects = useGameEffects()
const entry = findGame(props.gameId)
const isDom = entry?.renderer === 'dom'
const title = computed(() => (entry ? entry.name() : ''))
const bestScore = computed(() => store.slice(props.gameId).personalBest?.score ?? 0)

const shellRef = ref(null)
const stageRef = ref(null)
const canvasRef = ref(null)
const overlayRef = ref(null)
const phase = ref('loading')
const score = ref(0)
const result = ref(null)
const announcement = ref('')
const showLeaderboard = ref(false)
const board = ref({ width: 0, height: 0 })
const game = shallowRef(null)
const boardView = shallowRef(null)
const timeLeft = ref(null)
const motion = ref(false)

let state = null
let drawnRevision = -1
// The viewport the board was last sized for (see resize()).
let sizedViewport = ''
let context = null
let touchStart = null
let round = 0
let resizeObserver = null
const inputs = []
const sparkles = []
// Render inputs, rebuilt on layout only (no per-frame allocation).
const view = { width: 0, height: 0, palette: null, sparkles }

const loop = useGameLoop({ onStep, onRender: draw, onAutoPause: enterPause })

function onStep(dt) {
	game.value.step(state, inputs.shift() ?? null, dt)
	if (state.score !== score.value) score.value = state.score
	for (const event of state.events) {
		if (event.type !== 'pickup') continue
		effects.pickup(event.kind)
		// Canvas sparkles only; a DOM board shows its own wiped state.
		if (!isDom && effects.motionAllowed()) sparkles.push({ x: event.x, y: event.y, born: performance.now(), progress: 0 })
	}
	state.events.length = 0
	if (state.over) finishRound()
}

function draw() {
	if (!state) return
	if (game.value.roundMs) {
		const seconds = Math.ceil(state.timeLeftMs / 1000)
		if (seconds !== timeLeft.value) timeLeft.value = seconds
	}
	if (isDom) {
		if (state.revision !== drawnRevision) {
			drawnRevision = state.revision
			boardView.value = game.value.view(state)
		}
		return
	}
	if (!context) return
	const now = performance.now()
	for (let i = sparkles.length - 1; i >= 0; i--) {
		sparkles[i].progress = (now - sparkles[i].born) / SPARKLE_MS
		if (sparkles[i].progress >= 1) sparkles.splice(i, 1)
	}
	const ratio = canvasPixelRatio(window.devicePixelRatio)
	context.setTransform(ratio, 0, 0, ratio, 0, 0)
	game.value.render(context, state, view)
}

function resize() {
	if (!stageRef.value || !game.value) return
	const next = fitBoard(stageRef.value.clientWidth, window.innerHeight * BOARD_HEIGHT_SHARE, game.value.cols, game.value.rows)
	// A scrolling container's scrollbar that the board itself brings and
	// removes narrows the stage without a viewport change; growing back then
	// would loop (bigger board, scrollbar, smaller board, no scrollbar ...).
	// Shrinking always applies; growing only for a new viewport.
	const viewport = `${window.innerWidth}x${window.innerHeight}`
	if (viewport === sizedViewport && next.width > board.value.width) return
	sizedViewport = viewport
	board.value = next
	const canvas = canvasRef.value
	if (canvas) sizeCanvas(canvas)
	draw()
}

function sizeCanvas(canvas) {
	const ratio = canvasPixelRatio(window.devicePixelRatio)
	canvas.width = board.value.width * ratio
	canvas.height = board.value.height * ratio
	canvas.style.width = `${board.value.width}px`
	canvas.style.height = `${board.value.height}px`
	context = typeof canvas.getContext === 'function' ? canvas.getContext('2d') : null
	view.width = board.value.width
	view.height = board.value.height
	view.palette = readBoardPalette(shellRef.value)
}

/**
 * A fresh round state; the board re-reads its view on the next draw.
 */
function newState() {
	state = game.value.createState(Math.random)
	drawnRevision = -1
}

async function loadGame() {
	if (!entry) {
		logger.warn('Unknown arcade game', { gameId: props.gameId })
		phase.value = 'failed'
		return
	}
	phase.value = 'loading'
	try {
		game.value = await entry.load()
		newState()
		phase.value = 'ready'
		await nextTick()
		resize()
		focusOverlay()
	} catch (error) {
		logger.error('Failed to load arcade game', error, { gameId: props.gameId })
		phase.value = 'failed'
	}
}

function startGame() {
	if (!game.value || phase.value === 'playing') return
	newState()
	motion.value = effects.motionAllowed()
	inputs.length = 0
	sparkles.length = 0
	score.value = 0
	result.value = null
	if (!loop.start()) return
	round++
	phase.value = 'playing'
	announce(t('chorestrike', 'Game started'))
	focusShell()
	draw()
}

function pauseGame() {
	if (loop.pause()) enterPause()
}

function enterPause() {
	phase.value = 'paused'
	announce(t('chorestrike', 'Paused'))
	focusOverlay()
}

function resumeGame() {
	if (!loop.resume()) return
	phase.value = 'playing'
	announce(t('chorestrike', 'Resumed'))
	focusShell()
}

async function finishRound() {
	loop.stop()
	const finished = round
	const finalScore = state.score
	phase.value = 'over'
	announce(entry.overAnnouncement
		? entry.overAnnouncement(finalScore)
		: t('chorestrike', 'Game over. Score: {score}', { score: finalScore }))
	draw()
	focusOverlay()
	const roundResult = await store.finishGame(props.gameId, finalScore, loop.elapsedMs())
	if (finished !== round) return
	result.value = roundResult
	if (roundResult.newBest) {
		effects.newPersonalBest()
		announce(t('chorestrike', 'New personal best: {score}', { score: finalScore }))
	}
}

/**
 * @param {string|{cell: number}} input A direction, 'act' or a tapped tile
 */
function queueInput(input) {
	if (phase.value === 'playing' && inputs.length < MAX_QUEUED_INPUTS) inputs.push(input)
}

function onTileTap(cell) {
	queueInput({ cell })
}

function onKeyDown(event) {
	const action = keyAction(event.code)
	if (!action) return
	if (action.type === 'direction') {
		if (phase.value !== 'playing') return
		event.preventDefault()
		queueInput(action.direction)
		return
	}
	// A focused button keeps its own Enter/Space activation (no double handling).
	if (action.type === 'act') {
		if (phase.value !== 'playing' || event.target !== shellRef.value) return
		event.preventDefault()
		queueInput('act')
		return
	}
	if (event.code === 'Space' && event.target !== shellRef.value) return
	if (phase.value === 'playing') {
		event.preventDefault()
		pauseGame()
	} else if (phase.value === 'paused') {
		event.preventDefault()
		resumeGame()
	}
}

function onTouchStart(event) {
	const touch = event.changedTouches[0]
	touchStart = { x: touch.clientX, y: touch.clientY }
}

function onTouchEnd(event) {
	const start = touchStart
	touchStart = null
	// Swipes steer D-pad games; a tap game reads its taps from the board.
	if (!start || phase.value !== 'playing' || entry.input !== 'dpad') return
	const touch = event.changedTouches[0]
	const direction = swipeDirection(touch.clientX - start.x, touch.clientY - start.y)
	if (!direction) return
	event.preventDefault()
	queueInput(direction)
}

function announce(message) {
	announcement.value = message
}

function focusShell() {
	shellRef.value?.focus({ preventScroll: true })
}

async function focusOverlay() {
	await nextTick()
	overlayRef.value?.focusPrimary()
}

onMounted(() => {
	if (typeof ResizeObserver === 'function') {
		resizeObserver = new ResizeObserver(() => resize())
		resizeObserver.observe(stageRef.value)
	} else {
		window.addEventListener('resize', resize)
	}
	store.fetchPersonalBest(props.gameId)
	loadGame()
})

onBeforeUnmount(() => {
	resizeObserver?.disconnect()
	window.removeEventListener('resize', resize)
	loop.dispose()
})
</script>

<style scoped>
.game-shell {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0.75rem;
	padding: 0.5rem;
	outline: none;
}

.game-shell:focus-visible {
	box-shadow: 0 0 0 2px var(--color-primary-element);
	border-radius: var(--border-radius-large, 10px);
}

.game-shell-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 1rem;
	width: 100%;
	max-width: 480px;
	padding: 0.5rem 1rem;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large, 10px);
}

.game-shell-stat {
	display: flex;
	flex-direction: column;
	align-items: center;
}

.game-shell-stat-label {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.game-shell-stat-value {
	font-size: 1.5rem;
	font-weight: bold;
	color: var(--color-main-text);
}

.game-shell-stage {
	display: flex;
	justify-content: center;
	width: 100%;
	max-width: 480px;
}

.game-shell-board {
	position: relative;
	border: 2px solid var(--color-border-maxcontrast, var(--color-border));
	border-radius: 4px;
	box-sizing: content-box;
}

.game-shell-board.is-playing {
	touch-action: none;
}

.game-shell-board.is-unsized {
	width: min(100%, 240px);
	min-height: 240px;
}

.game-shell-canvas {
	display: block;
}

.game-shell-button {
	min-height: 44px;
	padding: 0 1rem;
	border: 1px solid var(--color-border-maxcontrast, var(--color-border));
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}
</style>
