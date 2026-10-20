<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="cs-empty-state" data-testid="empty-state">
		<div class="empty-icon">
			<Icon :name="icon" :size="48" />
		</div>
		<p class="empty-message">
			{{ message }}
		</p>
		<p v-if="hint" class="empty-hint">
			{{ hint }}
		</p>
		<div v-if="$slots.action" class="empty-action">
			<slot name="action" />
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import Icon from './Icon.vue'

/**
 * Shared empty state (CS-PLAN-044, FEAT-UX-031). Rendered when a view or
 * list has zero items after a successful load. An optional action slot
 * offers the next step (e.g. a create button).
 */
export default {
	name: 'EmptyState',

	components: {
		Icon,
	},

	props: {
		/** Icon name from the local icon registry */
		icon: {
			type: String,
			default: 'List',
		},

		message: {
			type: String,
			default: () => t('chorestrike', 'Nothing here yet'),
		},

		hint: {
			type: String,
			default: '',
		},
	},
}
</script>

<style scoped>
.cs-empty-state {
	text-align: center;
	padding: 3rem 2rem;
	color: var(--color-text-maxcontrast);
}

.empty-icon {
	display: flex;
	justify-content: center;
	margin-bottom: 0.5rem;
}

.empty-message {
	margin: 0;
	font-size: 1rem;
}

.empty-hint {
	margin: 0.25rem 0 0 0;
	font-size: 0.9rem;
	opacity: 0.7;
}

.empty-action {
	margin-top: 1rem;
}

.empty-action :deep(button) {
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
</style>
