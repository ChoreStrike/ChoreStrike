<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="assignee-select" data-testid="assignee-select">
		<div class="assignee-chips">
			<label
				v-for="member in members"
				:key="member.nextcloudUserId"
				class="assignee-chip"
				:class="{ selected: isSelected(member.nextcloudUserId) }"
				:data-testid="`assignee-chip-${member.nextcloudUserId}`">
				<input
					type="checkbox"
					class="cs-visually-hidden"
					:checked="isSelected(member.nextcloudUserId)"
					@change="toggle(member.nextcloudUserId)">
				<span>{{ member.displayName }}</span>
			</label>
			<p v-if="members.length === 0" class="field-error">
				{{ t('chorestrike', 'No house members found') }}
			</p>
		</div>
		<p class="assignee-hint">
			{{ t('chorestrike', 'Unassigned tasks can be claimed by anyone in the house') }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

/**
 * Controlled chip multi-select for assignee user IDs (CS-PLAN-067 §3.4).
 * Fully reusable: members come in via the `members` prop — the component
 * performs NO eager member fetch of its own (a fetch per expanded row was
 * the flagged anti-pattern; the caller owns loading). Emits the full next
 * array per toggle; state ownership stays with the caller.
 */
export default {
	name: 'AssigneeChips',

	props: {
		modelValue: {
			type: Array,
			default: () => [],
		},

		/** Preloaded house members: [{ nextcloudUserId, displayName }, …]. */
		members: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:modelValue'],

	setup(props, { emit }) {
		const isSelected = (userId) => props.modelValue.includes(userId)

		const toggle = (userId) => {
			const next = isSelected(userId)
				? props.modelValue.filter((id) => id !== userId)
				: [...props.modelValue, userId]
			emit('update:modelValue', next)
		}

		return {
			isSelected,
			toggle,
			t,
		}
	},
}
</script>

<style scoped>
.assignee-chips {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
}

.assignee-chip {
	display: flex;
	align-items: center;
	gap: 0.3rem;
	padding: 0.4rem 0.75rem;
	min-height: 44px;
	border: 1px solid var(--color-border);
	border-radius: 22px;
	cursor: pointer;
	background: var(--color-main-background);
	font-size: 0.85rem;
	transition: all 0.2s;
}

.assignee-chip:hover {
	border-color: var(--color-primary-element);
}

.assignee-chip:focus-within {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.assignee-chip.selected {
	background: var(--color-primary-light);
	border-color: var(--color-primary-element);
	color: var(--color-primary-element-light-text);
	font-weight: 600;
}

.field-error {
	color: var(--color-error-text);
	font-size: 0.85rem;
	margin: 0.5rem 0 0 0;
}

.assignee-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	margin: 0.5rem 0 0 0;
}
</style>
