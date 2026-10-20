<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<!-- Bulk assignment bar (CS-PLAN-042, UX-008): one API call for the
	     whole selection instead of a per-item loop. Owners and members
	     only — children cannot assign (CS-PLAN-061 §3.4, D5). -->
	<div class="bulk-assign-bar" data-testid="bulk-assign-bar">
		<span class="bulk-selection-count">
			{{ t('chorestrike', '{count} selected', { count: selectedCount }) }}
		</span>
		<select
			v-model="targetUserId"
			class="bulk-target-select"
			data-testid="bulk-assign-target">
			<option value="" disabled>
				{{ t('chorestrike', 'Assign to member…') }}
			</option>
			<option v-for="member in members" :key="member.nextcloudUserId" :value="member.nextcloudUserId">
				{{ member.displayName }}
			</option>
		</select>
		<button
			class="bulk-assign-button"
			data-testid="bulk-assign-button"
			:disabled="!targetUserId || assigning"
			@click="$emit('assign', targetUserId)">
			{{ t('chorestrike', 'Assign') }}
		</button>
		<button
			class="bulk-clear-button"
			:aria-label="t('chorestrike', 'Clear selection')"
			:title="t('chorestrike', 'Clear selection')"
			@click="$emit('clear')">
			<Icon name="X" :size="14" />
		</button>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { ref, watch } from 'vue'
import { Icon } from '../Common/index.js'

/**
 * The bulk-assign selection bar of TaskList (CS-PLAN-067 §3.4). Pure
 * presentation: the selection set and the assign call stay owned by the
 * TaskList orchestrator; the bar holds only the local target-user draft
 * (cleared with the selection via the `cleared` prop reset).
 */
export default {
	name: 'TaskListBulkBar',

	components: {
		Icon,
	},

	props: {
		selectedCount: {
			type: Number,
			required: true,
		},
		members: {
			type: Array,
			default: () => [],
		},
		assigning: {
			type: Boolean,
			default: false,
		},
		/** Reset signal: bumped by the parent when the selection clears. */
		clearSignal: {
			type: Number,
			default: 0,
		},
	},

	emits: ['assign', 'clear'],

	setup(props) {
		const targetUserId = ref('')

		// Reset the local target draft whenever the parent clears the
		// selection (same UX as the pre-extraction single component).
		watch(() => props.clearSignal, () => {
			targetUserId.value = ''
		})

		return {
			targetUserId,
			t,
		}
	},
}
</script>

<style scoped>
.bulk-assign-bar {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	padding: 0.6rem 0.9rem;
	background: var(--color-primary-light);
	border: 1px solid var(--color-primary-element);
	border-radius: 8px;
}

.bulk-selection-count {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.bulk-target-select {
	min-height: 36px;
	padding: 0.3rem 0.5rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.85rem;
}

.bulk-assign-button {
	padding: 0.4rem 0.9rem;
	border: none;
	border-radius: 6px;
	background: var(--color-primary);
	color: var(--color-primary-text);
	cursor: pointer;
	font-size: 0.85rem;
	font-weight: 600;
}

.bulk-assign-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.bulk-clear-button {
	display: flex;
	align-items: center;
	justify-content: center;
	min-width: 44px;
	min-height: 44px;
	margin-inline-start: auto;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}

.bulk-clear-button:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}
</style>
