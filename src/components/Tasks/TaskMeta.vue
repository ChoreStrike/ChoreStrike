<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="task-facts">
		<p v-if="location" class="task-location" data-testid="task-location">
			{{ location }}
		</p>
		<div class="task-meta">
			<span class="meta-item energy" data-testid="task-energy">
				<Icon name="Zap" :size="16" /> {{ energy }}
			</span>
			<span class="meta-item frequency" data-testid="task-recurrence">
				<Icon name="RotateCcw" :size="16" /> {{ recurrence }}
			</span>
			<span class="meta-item assigned" data-testid="task-assignees">
				<Icon name="Users" :size="16" /> {{ assignees }}
			</span>
		</div>
	</div>
</template>

<script setup>
import { Icon } from '../Common/index.js'

/**
 * The facts of a task below its title, the same on My Day, Tasks and in a
 * room (FEAT-UX-047). Pure presentation: the callers resolve the labels with
 * the helpers in utils/taskDisplay.js.
 */
defineProps({
	energy: { type: Number, default: 0 },
	recurrence: { type: String, default: '' },
	location: { type: String, default: '' },
	assignees: { type: String, default: '' },
})
</script>

<style scoped>
/* The main text colour: an overdue row is too dark for the muted one. */
.task-location {
	margin: 0.1rem 0 0;
	font-size: 0.85rem;
	color: var(--color-main-text);
}

.task-meta {
	display: flex;
	flex-wrap: wrap;
	gap: 0.25rem 0.75rem;
	margin-top: 0.25rem;
	font-size: 0.85rem;
	color: var(--color-main-text);
}

.meta-item {
	display: inline-flex;
	align-items: center;
	gap: 0.25rem;
	overflow-wrap: anywhere;
}

.meta-item.energy {
	color: var(--cs-energy);
	font-weight: 600;
}
</style>
