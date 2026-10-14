<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="cs-error-state" data-testid="error-state">
		<Icon name="AlertCircle" :size="40" class="error-icon" />
		<p class="error-message">
			{{ isOffline ? t('chorestrike', 'You appear to be offline') : message }}
		</p>
		<p v-if="isOffline" class="error-hint">
			{{ t('chorestrike', 'Reconnect and try again') }}
		</p>
		<button
			v-if="showRetry"
			type="button"
			class="retry-button"
			data-testid="error-retry"
			@click="$emit('retry')">
			{{ t('chorestrike', 'Try again') }}
		</button>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import Icon from './Icon.vue'

/**
 * Shared error state with retry action (CS-PLAN-044, FEAT-UX-031).
 * Offline detection uses `navigator.onLine` + the window online/offline
 * events — a browser capability available inside the Nextcloud shell, no
 * external service. While offline the component says so instead of showing
 * a generic failure; the actual retry outcome is decided by the request.
 */
export default {
	name: 'ErrorState',

	components: {
		Icon,
	},

	props: {
		message: {
			type: String,
			default: () => t('chorestrike', 'Something went wrong'),
		},
		showRetry: {
			type: Boolean,
			default: true,
		},
	},

	emits: ['retry'],

	data() {
		return {
			// Browser API; the rule flags Node 20's experimental navigator.
			// eslint-disable-next-line n/no-unsupported-features/node-builtins
			isOffline: typeof window !== 'undefined' && window.navigator.onLine === false,
			handleOffline: null,
			handleOnline: null,
		}
	},

	mounted() {
		this.handleOffline = () => {
			this.isOffline = true
		}
		this.handleOnline = () => {
			this.isOffline = false
		}
		window.addEventListener('offline', this.handleOffline)
		window.addEventListener('online', this.handleOnline)
	},

	beforeUnmount() {
		window.removeEventListener('offline', this.handleOffline)
		window.removeEventListener('online', this.handleOnline)
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.cs-error-state {
	text-align: center;
	padding: 3rem 1rem;
	color: var(--color-text-maxcontrast);
}

.error-icon {
	color: var(--color-error-text);
	margin-bottom: 0.5rem;
}

.error-message {
	margin: 0;
}

.error-hint {
	margin: 0.25rem 0 0 0;
	font-size: 0.9rem;
	opacity: 0.7;
}

.retry-button {
	margin-top: 1rem;
	min-height: 44px;
	padding: 0.6rem 1.2rem;
	border: none;
	border-radius: var(--cs-radius-sm, 6px);
	background: var(--color-primary);
	color: var(--color-primary-text);
	cursor: pointer;
	font-size: 0.9rem;
	font-weight: 600;
}

.retry-button:hover {
	background: var(--color-primary-hover);
}
</style>
