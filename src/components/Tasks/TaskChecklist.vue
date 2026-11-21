<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="task-checklist" data-testid="task-checklist">
		<div class="checklist-header">
			<h4>{{ t('chorestrike', 'Sub-tasks') }}</h4>
			<span class="progress-badge">{{ completedCount }}/{{ items.length }}</span>
		</div>
		<ul class="checklist-items">
			<li v-for="item in sortedItems"
				:key="item.id"
				class="checklist-item"
				:data-testid="'checklist-item-' + item.id">
				<label class="item-checkbox" :class="{ 'is-completed': isCompleted(item.id) }">
					<input type="checkbox"
						:checked="isCompleted(item.id)"
						:disabled="readonly"
						@change="$emit('toggle', { itemId: item.id, roomTaskId })">
					<span class="item-title">{{ item.label }}</span>
				</label>
			</li>
		</ul>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'

/**
 * The sub-tasks of a task's current cycle, to tick (ADR-021 decision 3):
 * partly ticked work stays saved (D-089-02). The sub-tasks themselves are
 * edited in the one form only (CS-PLAN-091 WP-B6).
 */
const props = defineProps({
	/** The definition's sub-tasks. */
	items: { type: Array, required: true },
	/** The ticks of the current cycle: rows with `checklistItemId` and `isChecked`. */
	progress: { type: Array, default: () => [] },
	/** Done work shows its ticks without taking new ones. */
	readonly: { type: Boolean, default: false },
	roomTaskId: { type: Number, required: true },
})
defineEmits(['toggle'])

const completedIds = computed(() => props.progress.filter((row) => row.isChecked).map((row) => row.checklistItemId))
const isCompleted = (itemId) => completedIds.value.includes(itemId)
// A tick of a sub-task the form removed counts no more.
const completedCount = computed(() => props.items.filter((item) => isCompleted(item.id)).length)
const sortedItems = computed(() => [...props.items].sort((a, b) => (a.sortOrder ?? a.id) - (b.sortOrder ?? b.id)))
</script>

<style scoped>
.task-checklist {
	padding: 0.25rem 0;
}

.checklist-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.checklist-header h4 {
	margin: 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.progress-badge {
	font-size: 0.8rem;
	font-weight: 600;
}

.checklist-items {
	margin: 0;
	padding: 0;
	list-style: none;
}

.item-checkbox {
	display: flex;
	align-items: center;
	gap: 0.6rem;
	min-height: 44px;
	cursor: pointer;
}

.item-checkbox input {
	width: 20px;
	height: 20px;
	margin: 0;
	accent-color: var(--color-primary-element);
}

.item-checkbox.is-completed .item-title {
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
}
</style>
