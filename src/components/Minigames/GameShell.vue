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
			@keydown="onKeyDown"
			@keyup="onKeyUp">
			<GameShellHeader :score="score"
				:timeLeft="timeLeft"
				:lives="lives"
				:best="bestScore"
				:playing="phase === 'playing'"
				:motion="motion"
				@pause="pauseGame" />

			<div ref="stageRef" class="game-shell-stage">
				<div class="game-shell-board"
					:class="{ 'is-playing': phase === 'playing', 'is-unsized': board.width === 0 }"
					:style="board.width ? { width: `${board.width}px`, height: `${board.height}px` } : null"
					@click="onBoardClick"
					@pointerdown="onPointerAim"
					@pointermove="onPointerAim"
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
						:sound="preferences.soundEnabled === true"
						:effects="preferences.animationEnabled === true"
						:saving="preferences.saving"
						@start="startGame"
						@resume="resumeGame"
						@retry="loadGame"
						@leaderboard="showLeaderboard = true"
						@toggle="toggleEffect" />
				</div>
			</div>

			<GameShellDpad v-if="entry && entry.input === 'dpad'" @direction="queueInput" />

			<p class="cs-visually-hidden" aria-live="polite" data-testid="game-announcer">
				{{ announcement }}
			</p>
		</section>

		<!-- Outside the application region: the board dialog stays browsable. -->
		<GameLeaderboard v-if="showLeaderboard" :gameId="gameId" @close="showLeaderboard = false" />
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import GameLeaderboard from './GameLeaderboard.vue'
import GameShellDpad from './GameShellDpad.vue'
import GameShellHeader from './GameShellHeader.vue'
import GameShellOverlay from './GameShellOverlay.vue'
import { useGameEffects } from '../../composables/useGameEffects.js'
import { useGameLoop } from '../../composables/useGameLoop.js'
import { useMinigameStore } from '../../store/minigame.js'
import { usePreferencesStore } from '../../store/preferences.js'
import { showErrorWithReference } from '../../utils/errorToast.js'
import { logger } from '../../utils/logger.js'
import { unlockAudio } from '../../utils/sound.js'
import { canvasPixelRatio, fitBoard, readBoardPalette } from './board.js'
import { createEffectsLayer } from './effectsLayer.js'
import { createSteerKeys, isPaddleControl, keyAction, swipeDirection } from './gameInput.js'
import { findGame } from './gameRegistry.js'

/**
 * Shared frame of every arcade game (CS-PLAN-086 §3.4): score header,
 * overlays, keyboard on this element only (S1/S2), swipe and on-screen
 * controls, a responsive canvas with the pixel ratio capped at 2 (S4), a
 * labelled application region with a polite live region (S5), pause on
 * hide (S3) and the result save with the active play time (D-086-04).
 * The game itself is a lazy module: `{cols, rows, createState, step}` plus
 * `render` for a canvas game, or `view` and a `Board` component for a DOM
 * game (registry `renderer: 'dom'`, tap input; the board re-renders only
 * when `state.revision` changes). A game with `roundMs` shows its time left,
 * a state with `lives` its lives; an entry may name its end (`overTitle`,
 * `overAnnouncement`). Input modes: `dpad` (directions, swipes, on-screen
 * pad), `tap` (tapped tiles) and `paddle` (held arrow keys and the pointer
 * position as controls, of which only the latest is queued).
 *
 * Effects (WP-G): every game event reaches `useGameEffects` (sound,
 * vibration); on a canvas board the effects layer adds particles, score
 * popups, a shake and a flash while the motion opt-in allows it. A game's
 * events name cells (`x`, `y` as the cell's top-left) unless its module
 * exports `eventUnit: 'point'`. The overlay's Sound and Effects switches
 * write the same personal preferences as the Settings.
 */
const props = defineProps({
	gameId: {
		type: String,
		required: true,
	},
})

const MAX_QUEUED_INPUTS = 4
const BOARD_HEIGHT_SHARE = 0.6
const store = useMinigameStore()
const preferences = usePreferencesStore()
const effects = useGameEffects()
const fx = createEffectsLayer()
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
const lives = ref(null)
const motion = ref(false)

let state = null
let drawnRevision = -1
// The viewport the board was last sized for (see resize()).
let sizedViewport = ''
let context = null
let touchStart = null
let round = 0
let resizeObserver = null
// Half a cell for games whose events name cells, nothing for points.
let eventOffset = 0.5
const center = { x: 0, y: 0 }
const inputs = []
const steerKeys = createSteerKeys()
// Render inputs, rebuilt on layout only (no per-frame allocation).
const view = { width: 0, height: 0, cell: 0, palette: null, time: 0, motion: false }

const loop = useGameLoop({ onStep, onRender: draw, onAutoPause: enterPause })

function onStep(dt) {
	const before = state.score
	game.value.step(state, inputs.shift() ?? null, dt)
	if (state.score !== score.value) {
		score.value = state.score
	}
	let origin = null
	for (const event of state.events) {
		handleEvent(event)
		if (origin === null && (event.type === 'pickup' || event.type === 'penalty')) {
			origin = event
		}
	}
	state.events.length = 0
	if (origin && state.score !== before && boardEffects()) {
		fx.score(origin.x + eventOffset, origin.y + eventOffset, state.score - before, view.palette)
	}
	if (state.over) {
		finishRound()
	}
}

/**
 * Sound and vibration for every event; board effects on a canvas while
 * motion is allowed.
 *
 * @param {{type: string, kind?: string, x?: number, y?: number}} event Game event
 */
function handleEvent(event) {
	if (event.type === 'pickup') {
		effects.pickup(event.kind)
	} else {
		effects.event(event.type)
	}
	if (boardEffects()) {
		fx.react(event, event.x + eventOffset, event.y + eventOffset, view.palette, center)
	}
}

function boardEffects() {
	return !isDom && motion.value && view.palette !== null
}

function draw() {
	if (!state) {
		return
	}
	syncHeader()
	if (isDom) {
		if (state.revision !== drawnRevision) {
			drawnRevision = state.revision
			boardView.value = game.value.view(state)
		}
		return
	}
	if (!context) {
		return
	}
	const now = performance.now()
	view.time = now
	const ratio = canvasPixelRatio(window.devicePixelRatio)
	const shake = fx.offset(now, view.cell)
	if (shake.x !== 0 || shake.y !== 0) {
		// The board moves inside its frame: keep the exposed edge floor-colored.
		context.setTransform(ratio, 0, 0, ratio, 0, 0)
		context.fillStyle = view.palette.floor
		context.fillRect(0, 0, view.width, view.height)
	}
	context.setTransform(ratio, 0, 0, ratio, shake.x * ratio, shake.y * ratio)
	game.value.render(context, state, view)
	fx.update(now)
	fx.draw(context, view, now)
}

function syncHeader() {
	if (game.value.roundMs) {
		const seconds = Math.ceil(state.timeLeftMs / 1000)
		if (seconds !== timeLeft.value) {
			timeLeft.value = seconds
		}
	}
	if (state.lives !== undefined && state.lives !== lives.value) {
		lives.value = state.lives
	}
}

function resize() {
	if (!stageRef.value || !game.value) {
		return
	}
	const next = fitBoard(stageRef.value.clientWidth, window.innerHeight * BOARD_HEIGHT_SHARE, game.value.cols, game.value.rows)
	// A scrolling container's scrollbar that the board itself brings and
	// removes narrows the stage without a viewport change; growing back then
	// would loop (bigger board, scrollbar, smaller board, no scrollbar ...).
	// Shrinking always applies; growing only for a new viewport.
	const viewport = `${window.innerWidth}x${window.innerHeight}`
	if (viewport === sizedViewport && next.width > board.value.width) {
		return
	}
	sizedViewport = viewport
	board.value = next
	const canvas = canvasRef.value
	if (canvas) {
		sizeCanvas(canvas)
	}
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
	view.cell = board.value.width / game.value.cols
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
		eventOffset = game.value.eventUnit === 'point' ? 0 : 0.5
		center.x = game.value.cols / 2
		center.y = game.value.rows / 2
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

/** Re-read the motion opt-in (a toggle, a resume, a new round). */
function refreshMotion() {
	motion.value = effects.motionAllowed()
	view.motion = motion.value
}

function startGame() {
	if (!game.value || phase.value === 'playing') {
		return
	}
	newState()
	refreshMotion()
	inputs.length = 0
	steerKeys.clear()
	fx.clear()
	score.value = 0
	result.value = null
	if (!loop.start()) {
		return
	}
	round++
	phase.value = 'playing'
	effects.event('start')
	announce(t('chorestrike', 'Game started'))
	focusShell()
	draw()
}

function pauseGame() {
	if (loop.pause()) {
		enterPause()
	}
}

function enterPause() {
	phase.value = 'paused'
	announce(t('chorestrike', 'Paused'))
	focusOverlay()
}

function resumeGame() {
	if (!loop.resume()) {
		return
	}
	phase.value = 'playing'
	refreshMotion()
	// Keys released during the pause sent no release: a paddle starts still.
	steerKeys.clear()
	if (entry.input === 'paddle') {
		queueInput({ steer: 0 })
	}
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
	if (finished !== round) {
		return
	}
	result.value = roundResult
	if (roundResult.newBest) {
		effects.newPersonalBest()
		announce(t('chorestrike', 'New personal best: {score}', { score: finalScore }))
	}
}

/**
 * The overlay's Sound and Effects switches write the personal opt-ins
 * (the same preferences as the Settings); a click during a running save
 * is ignored; switching sound on inside the click unlocks audio for the
 * mobile autoplay policy.
 *
 * @param {string} kind 'sound' or 'effects'
 */
async function toggleEffect(kind) {
	if (preferences.saving) {
		return
	}
	const field = kind === 'sound' ? 'soundEnabled' : 'animationEnabled'
	const value = preferences[field] !== true
	if (field === 'soundEnabled' && value) {
		unlockAudio()
	}
	try {
		await preferences.savePreferences({ [field]: value })
	} catch (error) {
		// The store logged the failure; the switch keeps the server's state.
		showErrorWithReference(t('chorestrike', 'The setting could not be saved.'), error)
	}
	refreshMotion()
}

/**
 * @param {string|{cell: number}|{steer: number}|{aim: number}} input A direction, 'act', a tapped tile or a paddle control
 */
function queueInput(input) {
	if (phase.value !== 'playing') {
		return
	}
	if (isPaddleControl(input)) {
		const queued = inputs.findIndex(isPaddleControl)
		if (queued >= 0) {
			inputs.splice(queued, 1)
		}
		inputs.push(input)
	} else if (inputs.length < MAX_QUEUED_INPUTS) {
		inputs.push(input)
	}
}

function onTileTap(cell) {
	queueInput({ cell })
}

function onKeyDown(event) {
	const action = keyAction(event.code)
	if (!action) {
		return
	}
	if (action.type === 'direction') {
		if (phase.value !== 'playing') {
			return
		}
		event.preventDefault()
		if (entry.input === 'paddle') {
			steer(steerKeys.press(event.code, action.direction))
		} else {
			queueInput(action.direction)
		}
		return
	}
	// A focused button keeps its own Enter/Space activation (no double handling).
	if (action.type === 'act') {
		if (phase.value !== 'playing' || event.target !== shellRef.value) {
			return
		}
		event.preventDefault()
		queueInput('act')
		return
	}
	if (event.code === 'Space' && event.target !== shellRef.value) {
		return
	}
	// A held key toggles once: its repeats would flip pause and resume.
	if (event.repeat) {
		return
	}
	if (phase.value === 'playing') {
		event.preventDefault()
		pauseGame()
	} else if (phase.value === 'paused') {
		event.preventDefault()
		resumeGame()
	}
}

function onKeyUp(event) {
	if (entry?.input === 'paddle') {
		steer(steerKeys.release(event.code))
	}
}

/**
 * @param {number|null} value New steer value of the held keys, null when unchanged
 */
function steer(value) {
	if (value !== null) {
		queueInput({ steer: value })
	}
}

/**
 * A paddle follows the pointer: a moving mouse, or a finger dragging or
 * tapping on the board (whose `touch-action: none` keeps the page still).
 *
 * @param {PointerEvent} event Pointer event on the board
 */
function onPointerAim(event) {
	if (phase.value !== 'playing' || entry.input !== 'paddle') {
		return
	}
	const rect = event.currentTarget.getBoundingClientRect()
	if (rect.width > 0) {
		queueInput({ aim: (event.clientX - rect.left) / rect.width })
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
	if (!start || phase.value !== 'playing' || entry.input !== 'dpad') {
		return
	}
	const touch = event.changedTouches[0]
	const direction = swipeDirection(touch.clientX - start.x, touch.clientY - start.y)
	if (!direction) {
		return
	}
	event.preventDefault()
	queueInput(direction)
}

function announce(message) {
	announcement.value = message
}

/**
 * A click on the board gives the shell the keyboard; an overlay button
 * keeps its own focus (Start and Resume hand it on themselves, a switch
 * must not lose it).
 *
 * @param {MouseEvent} event Click on the board
 */
function onBoardClick(event) {
	if (event.target.closest('button')) {
		return
	}
	focusShell()
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
	// A save still running for a closed game must not celebrate afterwards.
	round++
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
	overflow: hidden;
	background: var(--cs-arcade-floor, var(--color-main-background));
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
</style>
