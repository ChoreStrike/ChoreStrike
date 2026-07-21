<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div v-if="visible" class="overdue-claim-overlay" data-testid="overdue-claim-dialog">
		<div class="overdue-claim-dialog">
			<h3>{{ t('chorestrike', 'Claim Overdue Task') }}</h3>
			<p>{{ t('chorestrike', 'This will be recorded as taken over from {assignee}.', { assignee: assigneeName }) }}</p>
			<div class="dialog-actions">
				<button class="secondary-button" @click="$emit('cancel')">
					{{ t('chorestrike', 'Cancel') }}
				</button>
				<button class="primary-button" data-testid="confirm-claim-button" @click="$emit('confirm')">
					{{ t('chorestrike', 'Claim & Complete') }}
				</button>
			</div>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'OverdueClaimDialog',

	props: {
		visible: {
			type: Boolean,
			default: false,
		},
		assigneeName: {
			type: String,
			default: '',
		},
	},

	emits: ['confirm', 'cancel'],

	setup() {
		return { t }
	},
}
</script>

<style scoped>
.overdue-claim-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 1000;
}

.overdue-claim-dialog {
	background: var(--color-main-background);
	border-radius: 12px;
	padding: 1.5rem;
	max-width: 400px;
	width: 90%;
	box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
}

.overdue-claim-dialog h3 {
	margin: 0 0 0.75rem 0;
	font-size: 1.1rem;
}

.overdue-claim-dialog p {
	margin: 0 0 1.25rem 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.95rem;
	line-height: 1.4;
}

.dialog-actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.75rem;
}

.primary-button,
.secondary-button {
	padding: 0.6rem 1rem;
	border: none;
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.9rem;
	font-weight: 600;
}

.primary-button {
	background: var(--color-primary);
	color: #fff;
}

.secondary-button {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}
</style>
