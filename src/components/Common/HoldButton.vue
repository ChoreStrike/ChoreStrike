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
import { HOLD_MS, useHoldPress } from '../../composables/useHoldPress.js'

export { HOLD_MS }

let hintCounter = 0

/**
 * Press-and-hold confirmation for frequent, consequential taps
 * (CS-PLAN-084 D-084-05): `confirm` fires only after the pointer or
 * Enter/Space has been held for `holdMs`. Releasing early, leaving the
 * button, scrolling or blurring cancels without emitting; a short tap
 * shows an inline "press and hold" hint instead of silently doing
 * nothing. A filling ring shows progress; `prefers-reduced-motion`
 * replaces it with a static state change. The timers and the press state
 * live in the `useHoldPress` composable (CR-084-04).
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
			validator: (value) => ['primary', 'icon'].includes(value),
		},

		disabled: { type: Boolean, default: false },
		// In-flight state of the confirmed action: blocks a second confirm.
		busy: { type: Boolean, default: false },
		holdMs: { type: Number, default: HOLD_MS },
	},

	emits: ['confirm'],

	setup(props, { emit }) {
		return useHoldPress({
			holdMs: () => props.holdMs,
			disabled: () => props.disabled,
			busy: () => props.busy,
			onConfirm: () => emit('confirm'),
		})
	},

	data() {
		hintCounter += 1
		return {
			hintId: `chorestrike-hold-hint-${hintCounter}`,
		}
	},

	computed: {
		buttonAttrs() {
			const rest = { ...this.$attrs }
			delete rest.class
			delete rest.style
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
