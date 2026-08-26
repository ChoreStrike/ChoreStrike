<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<Modal :show="true" :aria-label="title" @close="handleCancel">
		<div class="confirm-dialog" data-testid="confirm-dialog">
			<div class="confirm-icon">
				<Icon name="AlertTriangle" :size="40" />
			</div>
			<h4>{{ title }}</h4>
			<p v-if="description">
				{{ description }}
			</p>
			<div class="confirm-actions">
				<button
					class="secondary-button"
					data-testid="confirm-dialog-cancel"
					@click="handleCancel">
					{{ cancelLabel }}
				</button>
				<button
					class="danger-button"
					data-testid="confirm-dialog-confirm"
					:disabled="loading"
					@click="$emit('confirm')">
					{{ confirmLabel }}
				</button>
			</div>
		</div>
	</Modal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import Icon from './Icon.vue'
import Modal from './Modal.vue'

/**
 * Shared styled confirmation dialog for destructive actions. Replaces the
 * native `confirm()` pattern (CS-PLAN-041); visual language follows the
 * MemberList remove-confirm introduced with CS-PLAN-038.
 */
export default {
	name: 'ConfirmDialog',

	components: {
		Icon,
		Modal,
	},

	props: {
		/** Headline question, e.g. "Delete floor?" */
		title: {
			type: String,
			required: true,
		},
		/** Optional consequence hint below the title */
		description: {
			type: String,
			default: '',
		},
		confirmLabel: {
			type: String,
			default: () => t('chorestrike', 'Delete'),
		},
		cancelLabel: {
			type: String,
			default: () => t('chorestrike', 'Cancel'),
		},
		/** Disables the confirm button while the action is in flight */
		loading: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['confirm', 'cancel'],

	methods: {
		handleCancel() {
			this.$emit('cancel')
		},
	},
}
</script>

<style scoped>
.confirm-dialog {
	padding: 1.5rem;
	text-align: center;
}

.confirm-icon {
	color: var(--color-error);
	margin-bottom: 0.5rem;
}

.confirm-dialog h4 {
	margin: 0 0 0.5rem 0;
	font-size: 1.1rem;
}

.confirm-dialog p {
	margin: 0 0 1rem 0;
	color: var(--color-text-maxcontrast);
}

.confirm-actions {
	display: flex;
	justify-content: center;
	gap: 0.75rem;
}

.secondary-button {
	min-height: 44px;
	padding: 0.5rem 1rem;
	background: transparent;
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.9rem;
}

.danger-button {
	min-height: 44px;
	padding: 0.5rem 1rem;
	background: var(--color-error);
	color: #fff;
	border: none;
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.9rem;
	font-weight: 500;
}

.danger-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}
</style>
