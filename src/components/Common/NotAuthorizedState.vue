<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="cs-not-authorized" data-testid="not-authorized-state">
		<Icon name="Lock" :size="40" class="lock-icon" />
		<p class="headline">
			{{ t('chorestrike', 'You are not a member of this household yet') }}
		</p>
		<p class="hint">
			{{ t('chorestrike', 'Ask the household owner to invite you. Removed and never-invited members see this same screen.') }}
		</p>
		<button
			class="retry-button"
			data-testid="not-authorized-retry"
			@click="$emit('retry')">
			{{ t('chorestrike', 'Try again') }}
		</button>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import Icon from './Icon.vue'

/**
 * App-wide authorization state (CS-PLAN-061 §3.9): rendered instead of the
 * navigation and views while the user store's membership state is 'denied'.
 * Removed vs. never-invited is deliberately indistinguishable
 * (anti-enumeration) — the copy states exactly that.
 */
export default {
	name: 'NotAuthorizedState',

	components: {
		Icon,
	},

	emits: ['retry'],

	methods: {
		t,
	},
}
</script>

<style scoped>
.cs-not-authorized {
	text-align: center;
	padding: 4rem 1rem;
	color: var(--color-text-maxcontrast);
}

.lock-icon {
	color: var(--color-warning);
	margin-bottom: 0.75rem;
}

.headline {
	margin: 0;
	font-size: 1.2rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.hint {
	margin: 0.5rem 0 0 0;
	font-size: 0.9rem;
	opacity: 0.75;
	max-width: 32rem;
	margin-left: auto;
	margin-right: auto;
}

.retry-button {
	margin-top: 1.25rem;
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
