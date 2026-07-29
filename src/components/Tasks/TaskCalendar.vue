<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="task-calendar" :class="'mode-' + mode" data-testid="task-calendar">
		<div v-if="mode === 'week'" class="week-grid">
			<CalendarDayCell
				v-for="day in days"
				:key="day"
				:day="day"
				:data="tasksByDay[day] || { scheduled: [], completed: [] }"
				@select-day="$emit('select-day', $event)" />
		</div>
		<div v-else class="month-grid">
			<CalendarDayCell
				v-for="day in days"
				:key="day"
				:day="day"
				:data="tasksByDay[day] || { scheduled: [], completed: [] }"
				@select-day="$emit('select-day', $event)" />
		</div>
	</div>
</template>

<script>
import CalendarDayCell from './CalendarDayCell.vue'

export default {
	name: 'TaskCalendar',

	components: {
		CalendarDayCell,
	},

	props: {
		mode: {
			type: String,
			default: 'week',
			validator: (v) => ['week', 'month'].includes(v),
		},
		days: {
			type: Array,
			required: true,
		},
		tasksByDay: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['select-day'],
}
</script>

<style scoped>
.week-grid {
	display: grid;
	grid-template-columns: repeat(7, 1fr);
	gap: 8px;
}

.month-grid {
	display: grid;
	grid-template-columns: repeat(7, 1fr);
	gap: 6px;
}
</style>
