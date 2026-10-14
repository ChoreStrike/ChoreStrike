<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div v-if="overloaded.length > 0" class="overcapacity-warning" data-testid="overcapacity-warning">
		<h3 class="warning-title">
			<span class="warning-icon">⚠</span>
			{{ t('chorestrike', 'Overloaded days ahead') }}
		</h3>
		<p class="warning-hint">
			{{ t('chorestrike', 'More chores are planned than energy is available. These tasks would be deferred first (lowest priority first):') }}
		</p>

		<div
			v-for="day in overloaded"
			:key="day.date"
			class="overcapacity-day"
			data-testid="overcapacity-day">
			<div class="day-summary">
				<span class="day-name">{{ formatDay(day) }}</span>
				<span
					class="energy-balance"
					:data-testid="`overcapacity-balance-${day.date}`">
					{{ day.planned }} / {{ day.capacity }} {{ t('chorestrike', 'energy') }}
				</span>
			</div>
			<ul class="deferred-list">
				<li
					v-for="task in day.deferred"
					:key="task.id"
					class="deferred-task"
					:data-testid="`deferred-task-${task.id}`">
					<span class="deferred-title">{{ task.title }}</span>
					<span class="deferred-meta">{{ priorityLabel(task.priority) }} · {{ task.energyCost }} {{ t('chorestrike', 'energy') }}</span>
				</li>
			</ul>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { useSchedulingStore } from '../../store/scheduling.js'

const WEEKDAY_LABELS = {
	sunday: t('chorestrike', 'Sunday'),
	monday: t('chorestrike', 'Monday'),
	tuesday: t('chorestrike', 'Tuesday'),
	wednesday: t('chorestrike', 'Wednesday'),
	thursday: t('chorestrike', 'Thursday'),
	friday: t('chorestrike', 'Friday'),
	saturday: t('chorestrike', 'Saturday'),
}

const PRIORITY_LABELS = {
	1: t('chorestrike', 'Low'),
	2: t('chorestrike', 'Normal'),
	3: t('chorestrike', 'High'),
}

export default {
	name: 'OvercapacityWarning',

	computed: {
		overloaded() {
			return useSchedulingStore().overcapacityDays
		},
	},

	methods: {
		t,

		formatDay(day) {
			const date = new Date(`${day.date}T00:00:00`)
			return `${WEEKDAY_LABELS[day.dayName] ?? day.dayName}, ${date.toLocaleDateString()}`
		},

		priorityLabel(priority) {
			return PRIORITY_LABELS[priority] ?? PRIORITY_LABELS[2]
		},
	},
}
</script>

<style scoped>
.overcapacity-warning {
	border: 1px solid var(--color-element-warning);
	background: var(--color-warning-hover, #fef9e7);
	border-radius: 8px;
	padding: 1rem 1.25rem;
}

.warning-title {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin: 0 0 0.35rem 0;
	font-size: 1rem;
	color: var(--color-warning-text);
}

.warning-icon {
	font-size: 1.1rem;
}

.warning-hint {
	margin: 0 0 0.75rem 0;
	font-size: 0.9rem;
	color: var(--color-main-text);
}

.overcapacity-day {
	padding: 0.5rem 0;
	border-top: 1px solid rgba(243, 156, 18, 0.4);
}

.day-summary {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 1rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.energy-balance {
	font-weight: 500;
	font-size: 0.85rem;
	color: var(--color-warning-text);
}

.deferred-list {
	list-style: none;
	margin: 0.35rem 0 0 0;
	padding: 0;
}

.deferred-task {
	display: flex;
	justify-content: space-between;
	gap: 1rem;
	padding: 0.25rem 0;
	font-size: 0.9rem;
}

.deferred-title {
	color: var(--color-main-text);
}

.deferred-meta {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}
</style>
