<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<!-- Rendered at <body>: inside the app content's stacking context
	     Nextcloud's navigation toggle stayed above the overlay (CS-PLAN-087
	     RF-087-09). -->
	<Teleport to="body">
		<div v-if="show"
			class="modal-overlay"
			v-bind="$attrs"
			@click.self="$emit('close')">
			<div
				ref="container"
				class="modal-container"
				data-testid="modal"
				role="dialog"
				aria-modal="true"
				:aria-label="ariaLabel"
				tabindex="-1"
				@click.stop>
				<button class="close-button"
					data-testid="modal-close"
					:aria-label="t('chorestrike', 'Close')"
					@click="$emit('close')">
					×
				</button>
				<slot name="header" />
				<slot />
				<slot name="footer" />
			</div>
		</div>
	</Teleport>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

/**
 * Module-level stack of open modal instances. Only the top-most instance
 * reacts to ESC / traps Tab, and the body scroll lock stays active until the
 * last modal closed (CS-PLAN-044).
 */
const openModals = []
let savedOverflow = null

const FOCUSABLE_SELECTOR = [
	'a[href]',
	'button:not([disabled])',
	'input:not([disabled]):not([type="hidden"])',
	'select:not([disabled])',
	'textarea:not([disabled])',
	'[tabindex]:not([tabindex="-1"])',
].join(', ')

export default {
	name: 'Modal',
	// A teleport root cannot inherit attributes: callers' test ids and
	// classes stay on the overlay, as before the teleport.
	inheritAttrs: false,
	props: {
		show: {
			type: Boolean,
			default: true,
		},

		/**
		 * Accessible name for the dialog. Callers should pass one (usually
		 * the dialog heading); without it the dialog has no accessible name.
		 */
		ariaLabel: {
			type: String,
			default: undefined,
		},
	},

	emits: ['close'],
	watch: {
		show(value) {
			if (value) {
				this.registerModal()
			} else {
				this.unregisterModal()
			}
		},
	},

	mounted() {
		if (this.show) {
			this.registerModal()
		}
	},

	beforeUnmount() {
		this.unregisterModal()
	},

	methods: {
		t,

		registerModal() {
			if (openModals.includes(this)) {
				return
			}
			this.previouslyFocused = document.activeElement
			openModals.push(this)
			document.addEventListener('keydown', this.onKeydown, true)
			this.lockScroll()
			this.$nextTick(() => {
				if (this.isTopMost()) {
					this.$refs.container?.focus()
				}
			})
		},

		unregisterModal() {
			const index = openModals.indexOf(this)
			if (index === -1) {
				return
			}
			openModals.splice(index, 1)
			document.removeEventListener('keydown', this.onKeydown, true)
			if (openModals.length === 0) {
				this.unlockScroll()
			} else {
				openModals[openModals.length - 1].$refs.container?.focus()
			}
			if (this.previouslyFocused?.isConnected) {
				this.previouslyFocused.focus()
				this.previouslyFocused = null
			}
		},

		isTopMost() {
			return openModals[openModals.length - 1] === this
		},

		lockScroll() {
			if (savedOverflow !== null) {
				return
			}
			savedOverflow = document.body.style.overflow
			document.body.style.overflow = 'hidden'
		},

		unlockScroll() {
			if (savedOverflow === null) {
				return
			}
			document.body.style.overflow = savedOverflow
			savedOverflow = null
		},

		onKeydown(event) {
			if (!this.isTopMost()) {
				return
			}

			if (event.key === 'Escape') {
				event.stopPropagation()
				this.$emit('close')
				return
			}

			if (event.key !== 'Tab') {
				return
			}

			const focusables = Array.from(this.$refs.container?.querySelectorAll(FOCUSABLE_SELECTOR) || [],)
			if (focusables.length === 0) {
				return
			}

			const first = focusables[0]
			const last = focusables[focusables.length - 1]
			const current = document.activeElement

			if (event.shiftKey && (current === first || current === this.$refs.container)) {
				event.preventDefault()
				last.focus()
			} else if (!event.shiftKey && current === last) {
				event.preventDefault()
				first.focus()
			}
		},
	},
}
</script>

<style scoped>
.modal-overlay {
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.45);
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 1rem;
	z-index: 1000;
}

.modal-container {
	position: relative;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 10px;
	box-shadow: 0 12px 32px rgba(0, 0, 0, 0.2);
	max-width: min(640px, 96vw);
	width: 100%;
	max-height: 90vh;
	overflow: auto;
}

.modal-container:focus {
	outline: none;
}

.close-button {
	position: absolute;
	top: 0.5rem;
	inset-inline-end: 0.5rem;
	min-width: 44px;
	min-height: 44px;
	display: flex;
	align-items: center;
	justify-content: center;
	border: none;
	background: transparent;
	font-size: 1.5rem;
	line-height: 1;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
}

.close-button:hover {
	color: var(--color-main-text);
}
</style>
