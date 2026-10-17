/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { getCurrentScope, onScopeDispose, ref } from 'vue'

/** One fixed simulation step (60 steps per second of game time). */
export const STEP_MS = 1000 / 60

/**
 * Catch-up bound per frame: after a long frame the game slows down instead
 * of jumping, so game time can lag real time but never skips ahead.
 */
const MAX_STEPS_PER_FRAME = 5

/**
 * Fixed-timestep `requestAnimationFrame` loop for the arcade (CS-PLAN-086
 * §3.4, fixes Snake defect S3).
 *
 * - `start()` is a no-op while running or paused (no double start).
 * - Hiding the page (`visibilitychange`) or leaving the window (`blur`)
 *   pauses the loop and calls `onAutoPause`; nothing advances while hidden.
 * - `elapsedMs()` is the stepped game time of the round — the active play
 *   time sent with a score (D-086-04); pauses never count.
 * - The loop cleans up its frame and listeners when the owning component's
 *   scope is disposed.
 *
 * @param {object} options Loop callbacks
 * @param {function(number): void} options.onStep Advances the game by one fixed step (ms)
 * @param {function(): void} [options.onRender] Draws the current state once per frame
 * @param {function(): void} [options.onAutoPause] Called after hiding or blur paused the loop
 * @param {number} [options.stepMs] Fixed step length in milliseconds
 * @return {{status: import('vue').Ref<string>, start: function(): boolean, pause: function(): boolean, resume: function(): boolean, stop: function(): void, dispose: function(): void, elapsedMs: function(): number}}
 */
export function useGameLoop({ onStep, onRender = () => {}, onAutoPause = () => {}, stepMs = STEP_MS }) {
	// idle | running | paused | stopped
	const status = ref('idle')
	let frameId = null
	let lastTime = null
	let accumulator = 0
	let elapsed = 0

	function frame(now) {
		frameId = null
		if (status.value !== 'running') return
		const delta = lastTime === null ? 0 : now - lastTime
		lastTime = now
		accumulator += Math.max(delta, 0)
		let steps = 0
		while (accumulator >= stepMs && steps < MAX_STEPS_PER_FRAME && status.value === 'running') {
			accumulator -= stepMs
			elapsed += stepMs
			steps++
			onStep(stepMs)
		}
		if (accumulator >= stepMs) {
			// Drop the backlog: slow down rather than skip ahead.
			accumulator = 0
		}
		onRender()
		if (status.value === 'running') {
			frameId = requestAnimationFrame(frame)
		}
	}

	function schedule() {
		lastTime = null
		frameId = requestAnimationFrame(frame)
	}

	function cancel() {
		if (frameId !== null) {
			cancelAnimationFrame(frameId)
			frameId = null
		}
	}

	function start() {
		if (status.value === 'running' || status.value === 'paused') return false
		accumulator = 0
		elapsed = 0
		status.value = 'running'
		schedule()
		return true
	}

	function pause() {
		if (status.value !== 'running') return false
		cancel()
		status.value = 'paused'
		return true
	}

	function resume() {
		if (status.value !== 'paused') return false
		status.value = 'running'
		schedule()
		return true
	}

	function stop() {
		cancel()
		if (status.value !== 'idle') status.value = 'stopped'
	}

	function autoPause() {
		if (pause()) onAutoPause()
	}

	function onVisibilityChange() {
		if (document.visibilityState === 'hidden') autoPause()
	}

	document.addEventListener('visibilitychange', onVisibilityChange)
	window.addEventListener('blur', autoPause)

	function dispose() {
		stop()
		document.removeEventListener('visibilitychange', onVisibilityChange)
		window.removeEventListener('blur', autoPause)
	}

	if (getCurrentScope()) {
		onScopeDispose(dispose)
	}

	return {
		status,
		start,
		pause,
		resume,
		stop,
		dispose,
		elapsedMs: () => Math.round(elapsed),
	}
}
