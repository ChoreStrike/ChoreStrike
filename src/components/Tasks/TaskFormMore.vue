<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<details class="form-more" data-testid="task-form-more">
		<summary>{{ t('chorestrike', 'More') }}</summary>
		<label class="field">
			<span>{{ t('chorestrike', 'Description') }}</span>
			<textarea :value="modelValue.description"
				rows="2"
				data-testid="task-description-input"
				:placeholder="t('chorestrike', 'Optional description…')"
				@input="update({ description: $event.target.value })" />
		</label>
		<label class="field">
			<span>{{ t('chorestrike', 'Instructions') }}</span>
			<textarea :value="modelValue.instructions"
				rows="3"
				maxlength="2000"
				data-testid="task-instructions-input"
				:placeholder="t('chorestrike', 'Optional step-by-step instructions…')"
				@input="update({ instructions: $event.target.value })" />
		</label>
		<div class="field-row">
			<label class="field">
				<span>{{ t('chorestrike', 'Priority') }}</span>
				<select :value="modelValue.priority"
					data-testid="task-priority-select"
					@change="update({ priority: Number($event.target.value) })">
					<option :value="3">{{ t('chorestrike', 'High') }}</option>
					<option :value="2">{{ t('chorestrike', 'Normal') }}</option>
					<option :value="1">{{ t('chorestrike', 'Low') }}</option>
				</select>
			</label>
			<label class="field">
				<span>{{ t('chorestrike', 'Category') }}</span>
				<select :value="modelValue.category"
					data-testid="task-category"
					@change="update({ category: $event.target.value })">
					<option v-for="choice in categories" :key="choice.value" :value="choice.value">
						{{ choice.label }}
					</option>
				</select>
			</label>
		</div>
		<label class="field">
			<span>{{ t('chorestrike', 'Visibility') }}</span>
			<select :value="modelValue.visibilityType"
				data-testid="task-visibility-select"
				@change="update({ visibilityType: $event.target.value })">
				<option :value="VISIBILITY_PUBLIC">{{ t('chorestrike', 'Public') }}</option>
				<option :value="VISIBILITY_PRIVATE">{{ t('chorestrike', 'Private') }}</option>
				<option v-if="isOwner || modelValue.visibilityType === VISIBILITY_ADMIN_ONLY" :value="VISIBILITY_ADMIN_ONLY" :disabled="!isOwner">
					{{ t('chorestrike', 'Admins only') }}
				</option>
			</select>
		</label>
		<p class="hint" data-testid="task-visibility-hint">
			{{ visibilityHint }}
		</p>
	</details>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'
import { VISIBILITY_ADMIN_ONLY, VISIBILITY_PRIVATE, VISIBILITY_PUBLIC } from '../../constants/taskVisibility.js'

/**
 * The folded part of the one form (D-091-85): description, instructions,
 * priority, category and visibility. The owner holds the value.
 */
const props = defineProps({
	/** The form's details. */
	modelValue: { type: Object, required: true },
	/** `[{ value, label }]` of the household's categories. */
	categories: { type: Array, required: true },
	/** Only the owner sets or lifts admin-only visibility. */
	isOwner: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const visibilityHint = computed(() => ({
	// The real matrix (CS-PLAN-061 §3.2, D2): private tasks are visible to
	// their creator and the assigned members.
	[VISIBILITY_PRIVATE]: t('chorestrike', 'Only you and the assigned members can see and complete this task. Assigned members can also edit it.'),
	[VISIBILITY_ADMIN_ONLY]: t('chorestrike', 'Only house owners can see and complete this task'),
})[props.modelValue.visibilityType] ?? t('chorestrike', 'All house members can see and complete this task'))

/**
 * Emit the details with some values changed.
 * @param {object} patch The changed values.
 */
function update(patch) {
	emit('update:modelValue', { ...props.modelValue, ...patch })
}
</script>

<style scoped>
.form-more {
	border-top: 1px solid var(--color-border);
	padding-top: 0.5rem;
}

.form-more summary {
	min-height: 44px;
	display: flex;
	align-items: center;
	font-weight: 600;
	cursor: pointer;
}

.field {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	margin-bottom: 0.75rem;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.field textarea,
.field select {
	min-height: 44px;
	color: var(--color-main-text);
	font-size: 0.95rem;
}

.field-row {
	display: flex;
	flex-wrap: wrap;
	gap: 0.75rem;
}

.field-row .field {
	flex: 1 1 12rem;
}

.hint {
	margin: -0.25rem 0 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}
</style>
