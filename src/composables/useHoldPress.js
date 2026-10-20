/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
import { onBeforeUnmount, ref, toValue, watch } from 'vue'

export const HOLD_MS = 600
const HINT_MS = 2500
// A confirmed press with no in-flight action (e.g. a dialog opened over the
// button, swallowing the release) returns to idle after this delay.
const SETTLE_MS = 1000
// Moving further than this while pressed is a scroll or drag, not a hold.
const MOVE_TOLERANCE_PX = 10
const HOLD_KEYS = ['Enter', ' ', 'Spacebar']

function prefersReducedMotion() {
	return typeof window !== 'undefined'
		&& typeof window.matchMedia === 'function'
		&& window.matchMedia('(prefers-reduced-motion: reduce)').matches === true
}

/**
 * Press-and-hold state machine behind `HoldButton` (CS-PLAN-084 D-084-05,
 * CR-084-04): `onConfirm` fires only after the pointer or Enter/Space has
 * been held for `holdMs`. Releasing early, leaving the element, scrolling or
 * blurring cancels without confirming; a short tap shows the hint instead of
 * silently doing nothing.
 *
 * `holdMs`, `disabled` and `busy` are refs or getters, read when needed, so
 * a prop change during a hold is honored.
 *
 * @param {object} options Hold configuration.
 * @param {number | import('vue').Ref<number> | (() => number)} options.holdMs Hold duration in milliseconds.
 * @param {boolean | import('vue').Ref<boolean> | (() => boolean)} options.disabled Hold is not allowed.
 * @param {boolean | import('vue').Ref<boolean> | (() => boolean)} options.busy The confirmed action is in flight.
 * @param {() => void} options.onConfirm Called once per completed hold.
 * @return {object} The reactive state (`phase`, `hintVisible`, `staticFill`) and the event handlers.
 */
export function useHoldPress({ holdMs, disabled, busy, onConfirm }) {
	const phase = ref('idle')
	const hintVisible = ref(false)
	const staticFill = ref(false)
	let holdTimer = null
	let hintTimer = null
	let settleTimer = null
	let origin = null
	let releaseHandled = false

	const blocked = () => toValue(disabled) || toValue(busy)

	function start() {
		if (blocked() || phase.value !== 'idle') {
			return
		}
		hideHint()
		staticFill.value = prefersReducedMotion()
		phase.value = 'holding'
		holdTimer = setTimeout(() => {
			// Never confirm a button that became disabled or busy mid-hold.
			if (blocked()) {
				phase.value = 'idle'
				return
			}
			phase.value = 'confirmed'
			onConfirm()
			clearTimeout(settleTimer)
			settleTimer = setTimeout(settle, SETTLE_MS)
		}, toValue(holdMs))
	}

	// Leave, scroll or blur: a running hold stops without confirming. A
	// confirmed press is untouched — disabling the focused busy button
	// blurs it, and a dialog opening over it ends the pointer's stay.
	function abort() {
		if (phase.value !== 'holding') {
			return
		}
		clearTimeout(holdTimer)
		phase.value = 'idle'
	}

	// End a confirmed press unless its action is still in flight (the
	// busy/disabled watchers end it then).
	function settle() {
		if (phase.value === 'confirmed' && !blocked()) {
			phase.value = 'idle'
		}
	}

	// Released before the hold completed: explain instead of doing nothing.
	function release() {
		const wasHolding = phase.value === 'holding'
		abort()
		settle()
		if (wasHolding) {
			showHint()
		}
		// The native click that follows this release is already handled.
		releaseHandled = true
	}

	function showHint() {
		hintVisible.value = true
		clearTimeout(hintTimer)
		hintTimer = setTimeout(hideHint, HINT_MS)
	}

	function hideHint() {
		clearTimeout(hintTimer)
		hintVisible.value = false
	}

	function onPointerDown(event) {
		if (event.button !== undefined && event.button !== 0) {
			return
		}
		releaseHandled = false
		origin = { x: event.clientX ?? 0, y: event.clientY ?? 0 }
		start()
	}

	function onPointerMove(event) {
		if (phase.value !== 'holding' || !origin) {
			return
		}
		const dx = (event.clientX ?? 0) - origin.x
		const dy = (event.clientY ?? 0) - origin.y
		if (Math.hypot(dx, dy) > MOVE_TOLERANCE_PX) {
			abort()
		}
	}

	function onPointerUp() {
		release()
	}

	function onKeyDown(event) {
		if (!HOLD_KEYS.includes(event.key)) {
			return
		}
		// Suppress the native keyboard activation: only the hold confirms.
		event.preventDefault()
		if (event.repeat) {
			return
		}
		releaseHandled = false
		start()
	}

	function onKeyUp(event) {
		if (!HOLD_KEYS.includes(event.key)) {
			return
		}
		event.preventDefault()
		release()
	}

	// A click without a tracked press (e.g. an assistive-technology
	// activation) never confirms; it explains the gesture instead.
	function onClick() {
		if (releaseHandled) {
			releaseHandled = false
			return
		}
		if (!blocked()) {
			showHint()
		}
	}

	// A disabled button receives no release event: a running hold stops when
	// the button becomes disabled or busy, and the button returns to idle once
	// the confirmed action is no longer in flight (or re-enabled).
	for (const flag of [busy, disabled]) {
		watch(() => toValue(flag), (value) => {
			if (value) {
				abort()
			} else {
				phase.value = 'idle'
			}
		})
	}

	onBeforeUnmount(() => {
		clearTimeout(holdTimer)
		clearTimeout(hintTimer)
		clearTimeout(settleTimer)
	})

	return {
		phase,
		hintVisible,
		staticFill,
		abort,
		onPointerDown,
		onPointerMove,
		onPointerUp,
		onKeyDown,
		onKeyUp,
		onClick,
	}
}
