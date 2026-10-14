<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div
		class="calendar-day-cell"
		:class="{ 'has-tasks': totalCount > 0, 'is-today': isToday }"
		:data-testid="'calendar-day-' + day"
		@click="$emit('select-day', day)">
		<span class="day-label">{{ dayLabel }}</span>
		<span
			v-if="totalCount > 0"
			class="day-count"
			:data-testid="'calendar-day-count-' + day">
			{{ totalCount }}
		</span>
	</div>
</template>

<script>
import { todayKeyInZone } from '../../utils/dateKeys.js'
import { useHouseStore } from '../../store/house.js'

export default {
	name: 'CalendarDayCell',

	props: {
		day: {
			type: String,
			required: true,
		},
		data: {
			type: Object,
			default: () => ({ scheduled: [], completed: [] }),
		},
	},

	emits: ['select-day'],

	computed: {
		totalCount() {
			return this.data.scheduled.length + this.data.completed.length
		},

		dayLabel() {
			const [y, m, d] = this.day.split('-').map(Number)
			const date = new Date(y, m - 1, d)
			return date.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric' })
		},

		isToday() {
			// House-local "today" (CS-PLAN-057) — the grid keys are
			// house-local day keys.
			return this.day === todayKeyInZone(useHouseStore().timezone)
		},
	},
}
</script>

<style scoped>
.calendar-day-cell {
	padding: 8px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: 6px;
	cursor: pointer;
	min-height: 60px;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 4px;
	transition: background 0.15s;
}

.calendar-day-cell:hover {
	background: var(--color-background-hover, #f5f5f5);
}

.calendar-day-cell.is-today {
	border-color: var(--color-primary-element);
}

.calendar-day-cell.has-tasks {
	background: var(--color-primary-element-light, #e8f4fd);
}

.day-label {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast, #767676);
}

.day-count {
	font-weight: 700;
	font-size: 1.1em;
	color: var(--color-primary-element);
}
</style>
