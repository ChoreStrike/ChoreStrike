<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<span class="hold-button" :class="[`hold-button--${variant}`, $attrs.class]" :style="$attrs.style">
		<button
			v-bind="buttonAttrs"
			type="button"
			class="hold-button__control"
			:class="{ 'is-holding': phase === 'holding', 'is-confirmed': phase === 'confirmed', 'is-static': staticFill }"
			:style="{ '--hold-ms': `${holdMs}ms` }"
			:disabled="disabled || busy"
			:aria-busy="busy ? 'true' : null"
			:aria-describedby="hintId"
			:data-hold-state="phase"
			@pointerdown="onPointerDown"
			@pointermove="onPointerMove"
			@pointerup="onPointerUp"
			@pointerleave="abort"
			@pointercancel="abort"
			@keydown="onKeyDown"
			@keyup="onKeyUp"
			@blur="abort"
			@contextmenu.prevent
			@click.prevent.stop="onClick">
			<span class="hold-button__ring" aria-hidden="true">
				<svg viewBox="0 0 36 36" class="hold-button__ring-svg">
					<circle
						class="hold-button__ring-track"
						cx="18"
						cy="18"
						r="16" />
					<circle
						class="hold-button__ring-progress"
						cx="18"
						cy="18"
						r="16" />
				</svg>
				<Icon :name="icon" :size="iconSize" class="hold-button__icon" />
			</span>
			<span v-if="label" class="hold-button__label">{{ label }}</span>
		</button>
		<span :id="hintId" class="hidden-visually">{{ hintText }}</span>
		<span
			class="hold-button__hint"
			:class="{ 'is-visible': hintVisible }"
			:data-testid="hintTestId"
			role="status"
			aria-live="polite">{{ hintVisible ? hintText : '' }}</span>
	</span>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import Icon from './Icon.vue'

export const HOLD_MS = 600
const HINT_MS = 2500
// A confirmed press with no in-flight action (e.g. a dialog opened over the
// button, swallowing the release) returns to idle after this delay.
const SETTLE_MS = 1000
// Moving further than this while pressed is a scroll or drag, not a hold.
const MOVE_TOLERANCE_PX = 10
const HOLD_KEYS = ['Enter', ' ', 'Spacebar']

let hintCounter = 0

function prefersReducedMotion() {
	return typeof window !== 'undefined'
		&& typeof window.matchMedia === 'function'
		&& window.matchMedia('(prefers-reduced-motion: reduce)').matches === true
}

/**
 * Press-and-hold confirmation for frequent, consequential taps
 * (CS-PLAN-084 D-084-05): `confirm` fires only after the pointer or
 * Enter/Space has been held for `holdMs`. Releasing early, leaving the
 * button, scrolling or blurring cancels without emitting; a short tap
 * shows an inline "press and hold" hint instead of silently doing
 * nothing. A filling ring shows progress; `prefers-reduced-motion`
 * replaces it with a static state change.
 *
 * Non-class attributes (`data-testid`, `aria-label`, `title`) land on
 * the inner button; `class`/`style` stay on the wrapper.
 */
export default {
	name: 'HoldButton',

	components: { Icon },

	inheritAttrs: false,

	props: {
		label: { type: String, default: '' },
		icon: { type: String, default: 'CheckCircle' },
		iconSize: { type: Number, default: 20 },
		variant: {
			type: String,
			default: 'primary',
			validator: value => ['primary', 'icon'].includes(value),
		},
		disabled: { type: Boolean, default: false },
		// In-flight state of the confirmed action: blocks a second confirm.
		busy: { type: Boolean, default: false },
		holdMs: { type: Number, default: HOLD_MS },
	},

	emits: ['confirm'],

	data() {
		hintCounter += 1
		return {
			phase: 'idle',
			hintVisible: false,
			staticFill: false,
			hintId: `chorestrike-hold-hint-${hintCounter}`,
		}
	},

	computed: {
		buttonAttrs() {
			const { class: _class, style: _style, ...rest } = this.$attrs
			return rest
		},
		hintText() {
			return t('chorestrike', 'Press and hold to confirm')
		},
		hintTestId() {
			const testId = this.$attrs['data-testid']
			return testId ? `${testId}-hint` : null
		},
	},

	watch: {
		// A disabled button receives no release event: return to idle once
		// the confirmed action is no longer in flight (or re-enabled).
		busy(value) {
			if (!value) this.phase = 'idle'
		},
		disabled(value) {
			if (!value) this.phase = 'idle'
		},
	},

	beforeUnmount() {
		clearTimeout(this.holdTimer)
		clearTimeout(this.hintTimer)
		clearTimeout(this.settleTimer)
	},

	methods: {
		start() {
			if (this.disabled || this.busy || this.phase !== 'idle') return
			this.hideHint()
			this.staticFill = prefersReducedMotion()
			this.phase = 'holding'
			this.holdTimer = setTimeout(() => {
				this.phase = 'confirmed'
				this.$emit('confirm')
				clearTimeout(this.settleTimer)
				this.settleTimer = setTimeout(this.settle, SETTLE_MS)
			}, this.holdMs)
		},

		// Leave, scroll or blur: a running hold stops without confirming. A
		// confirmed press is untouched — disabling the focused busy button
		// blurs it, and a dialog opening over it ends the pointer's stay.
		abort() {
			if (this.phase !== 'holding') return
			clearTimeout(this.holdTimer)
			this.phase = 'idle'
		},

		// End a confirmed press unless its action is still in flight (the
		// busy/disabled watchers end it then).
		settle() {
			if (this.phase === 'confirmed' && !this.busy && !this.disabled) {
				this.phase = 'idle'
			}
		},

		// Released before the hold completed: explain instead of doing nothing.
		release() {
			const wasHolding = this.phase === 'holding'
			this.abort()
			this.settle()
			if (wasHolding) this.showHint()
			// The native click that follows this release is already handled.
			this.releaseHandled = true
		},

		showHint() {
			this.hintVisible = true
			clearTimeout(this.hintTimer)
			this.hintTimer = setTimeout(this.hideHint, HINT_MS)
		},

		hideHint() {
			clearTimeout(this.hintTimer)
			this.hintVisible = false
		},

		onPointerDown(event) {
			if (event.button !== undefined && event.button !== 0) return
			this.releaseHandled = false
			this.origin = { x: event.clientX ?? 0, y: event.clientY ?? 0 }
			this.start()
		},

		onPointerMove(event) {
			if (this.phase !== 'holding' || !this.origin) return
			const dx = (event.clientX ?? 0) - this.origin.x
			const dy = (event.clientY ?? 0) - this.origin.y
			if (Math.hypot(dx, dy) > MOVE_TOLERANCE_PX) this.abort()
		},

		onPointerUp() {
			this.release()
		},

		onKeyDown(event) {
			if (!HOLD_KEYS.includes(event.key)) return
			// Suppress the native keyboard activation: only the hold confirms.
			event.preventDefault()
			if (event.repeat) return
			this.releaseHandled = false
			this.start()
		},

		onKeyUp(event) {
			if (!HOLD_KEYS.includes(event.key)) return
			event.preventDefault()
			this.release()
		},

		// A click without a tracked press (e.g. an assistive-technology
		// activation) never confirms; it explains the gesture instead.
		onClick() {
			if (this.releaseHandled) {
				this.releaseHandled = false
				return
			}
			if (!this.disabled && !this.busy) this.showHint()
		},
	},
}
</script>

<style scoped>
.hold-button {
	position: relative;
	display: inline-flex;
	flex-shrink: 0;
}

.hold-button__control {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 0.4rem;
	min-inline-size: 44px;
	min-block-size: 44px;
	margin: 0;
	cursor: pointer;
	font-weight: 600;
	user-select: none;
	-webkit-user-select: none;
	-webkit-touch-callout: none;
	transition: background-color 0.2s, color 0.2s;
}

.hold-button--primary .hold-button__control {
	padding: 0.4rem 0.9rem 0.4rem 0.5rem;
	border: 2px solid var(--color-primary-element);
	border-radius: 22px;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-size: 1rem;
}

.hold-button--primary .hold-button__control:hover:not(:disabled) {
	background: var(--color-primary-element-hover);
}

.hold-button--icon .hold-button__control {
	inline-size: 44px;
	block-size: 44px;
	padding: 0;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: 50%;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.hold-button--icon .hold-button__control:hover:not(:disabled) {
	background: var(--color-background-hover);
}

.hold-button__control:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.hold-button__control[aria-busy='true'] {
	cursor: wait;
}

.hold-button__control:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.hold-button__ring {
	position: relative;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	inline-size: 32px;
	block-size: 32px;
}

.hold-button__ring-svg {
	position: absolute;
	inset: 0;
	inline-size: 100%;
	block-size: 100%;
	transform: rotate(-90deg);
}

.hold-button__ring-track,
.hold-button__ring-progress {
	fill: none;
	stroke-width: 3;
}

.hold-button__ring-track {
	stroke: transparent;
}

/* Circumference of r=16: 2πr ≈ 100.53. */
.hold-button__ring-progress {
	stroke: currentColor;
	stroke-linecap: round;
	stroke-dasharray: 100.53;
	stroke-dashoffset: 100.53;
	transition: none;
}

.is-holding .hold-button__ring-track,
.is-confirmed .hold-button__ring-track {
	stroke: currentColor;
	stroke-opacity: 0.3;
}

.is-holding .hold-button__ring-progress {
	stroke-dashoffset: 0;
	transition: stroke-dashoffset var(--hold-ms) linear;
}

.is-confirmed .hold-button__ring-progress {
	stroke-dashoffset: 0;
}

/* Reduced motion: a static full ring and pressed look instead of the fill. */
.is-static.is-holding .hold-button__ring-progress {
	transition: none;
}

.is-static.is-holding {
	box-shadow: inset 0 0 0 2px currentColor;
}

/* Above the button: cards clip their bottom edge (overflow: hidden). */
.hold-button__hint {
	position: absolute;
	inset-block-end: calc(100% + 4px);
	inset-inline-start: 0;
	z-index: 10;
	padding: 0.25rem 0.5rem;
	border-radius: 6px;
	background: var(--color-main-text);
	color: var(--color-main-background);
	font-size: 0.85rem;
	font-weight: normal;
	white-space: nowrap;
	pointer-events: none;
	visibility: hidden;
}

.hold-button--icon .hold-button__hint {
	inset-inline-start: auto;
	inset-inline-end: 0;
}

.hold-button__hint.is-visible {
	visibility: visible;
}
</style>
