<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="calendar-view" data-testid="calendar-view">
		<header class="calendar-header">
			<h1>{{ t('chorestrike', 'Calendar') }}</h1>
			<div class="calendar-controls">
				<div class="calendar-mode-toggle" data-testid="calendar-mode-toggle">
					<button
						class="toggle-btn"
						:class="{ active: mode === 'week' }"
						data-testid="calendar-mode-week"
						@click="mode = 'week'">
						{{ t('chorestrike', 'Week') }}
					</button>
					<button
						class="toggle-btn"
						:class="{ active: mode === 'month' }"
						data-testid="calendar-mode-month"
						@click="mode = 'month'">
						{{ t('chorestrike', 'Month') }}
					</button>
				</div>
				<div class="calendar-nav">
					<button
						data-testid="calendar-prev"
						:aria-label="t('chorestrike', 'Previous')"
						@click="navigate(-1)">
						&lt;
					</button>
					<span data-testid="calendar-range-label" class="range-label">{{ rangeLabel }}</span>
					<button
						data-testid="calendar-next"
						:aria-label="t('chorestrike', 'Next')"
						@click="navigate(1)">
						&gt;
					</button>
				</div>
			</div>
		</header>

		<div v-if="loading" class="calendar-loading">
			<div class="skeleton skeleton-grid" />
		</div>

		<div v-else-if="error" class="calendar-error" data-testid="calendar-error">
			<p>{{ error }}</p>
		</div>

		<TaskCalendar
			v-else
			:mode="mode"
			:days="visibleDays"
			:tasks-by-day="roomTasksStore.rangeTasksByDay"
			@select-day="selectedDay = $event" />

		<div v-if="selectedDay" class="day-detail" data-testid="calendar-day-list">
			<h3>{{ formatDate(selectedDay) }}</h3>
			<ul class="day-task-list">
				<li
					v-for="item in dayDetail.scheduled"
					:key="'s-' + item.roomTask.id + '-' + item.dueDate"
					class="task-item"
					:class="{ overdue: isOverdue(item) }">
					<span class="task-name">{{ item.task.name }}</span>
					<span class="task-room">{{ item.room.name }}</span>
					<span
						v-if="isRecurring(item)"
						class="task-repeat"
						:title="t('chorestrike', 'Repeats')">↻</span>
				</li>
				<li
					v-for="(item, idx) in dayDetail.completed"
					:key="'c-' + idx"
					class="task-item completed">
					<!-- Redacted rows (tasks invisible to the viewer) carry a
					     null taskName and render the shared fallback. -->
					<span class="task-name">{{ item.taskName || t('chorestrike', 'Unknown task') }}</span>
					<span class="task-room">{{ item.roomName }}</span>
				</li>
			</ul>
			<button class="close-detail" @click="selectedDay = null">
				{{ t('chorestrike', 'Close') }}
			</button>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { useRoomTasksStore } from '../store/roomTasks.js'
import { useHouseStore } from '../store/house.js'
import { addDaysToKey, dateKeyInZone, todayKeyInZone, weekdayOfKey } from '../utils/dateKeys.js'
import { logger } from '../utils/logger.js'
import TaskCalendar from '../components/Tasks/TaskCalendar.vue'

export default {
	name: 'CalendarView',

	components: {
		TaskCalendar,
	},

	setup() {
		const roomTasksStore = useRoomTasksStore()
		const houseStore = useHouseStore()
		return { roomTasksStore, houseStore }
	},

	data() {
		return {
			mode: 'week',
			// CS-PLAN-057: the grid is anchored on the house-local "today"
			// key so its day keys agree with the backend's bucketing.
			anchorDate: this.mondayOfKey(todayKeyInZone(useHouseStore().timezone)),
			selectedDay: null,
			loading: false,
			error: null,
		}
	},

	computed: {
		todayYmd() {
			return todayKeyInZone(this.houseStore.timezone)
		},

		rangeStart() {
			return this.anchorDate
		},

		rangeEnd() {
			return addDaysToKey(this.anchorDate, this.mode === 'week' ? 6 : 29)
		},

		visibleDays() {
			const days = []
			const count = this.mode === 'week' ? 7 : 30
			for (let i = 0; i < count; i++) {
				days.push(addDaysToKey(this.anchorDate, i))
			}
			return days
		},

		rangeLabel() {
			const start = this.formatDate(this.rangeStart)
			const end = this.formatDate(this.rangeEnd)
			return `${start} – ${end}`
		},

		dayDetail() {
			if (!this.selectedDay) return { scheduled: [], completed: [] }
			return this.roomTasksStore.rangeTasksByDay[this.selectedDay] || { scheduled: [], completed: [] }
		},
	},

	watch: {
		rangeStart: {
			immediate: true,
			handler() {
				this.fetchData()
			},
		},
	},

	mounted() {
		// CS-PLAN-065 §3.4: a committed owner plan may have moved
		// occurrences inside the currently visible window — refetch exactly
		// that window (the store's sequence guard drops stale responses).
		this.scheduleAppliedHandler = () => {
			this.fetchData()
		}
		subscribe('chorestrike:schedule-applied', this.scheduleAppliedHandler)
	},

	beforeUnmount() {
		if (this.scheduleAppliedHandler) {
			unsubscribe('chorestrike:schedule-applied', this.scheduleAppliedHandler)
		}
	},

	methods: {
		t,

		async fetchData() {
			this.loading = true
			this.error = null
			try {
				await this.roomTasksStore.fetchRangeTasks(this.rangeStart, this.rangeEnd)
			} catch (e) {
				this.error = e.message
				logger.error('Calendar fetch failed', e, { view: 'CalendarView' })
			} finally {
				this.loading = false
			}
		},

		navigate(direction) {
			const step = this.mode === 'week' ? 7 : 30
			this.anchorDate = addDaysToKey(this.anchorDate, direction * step)
			this.selectedDay = null
		},

		mondayOfKey(key) {
			const dayName = weekdayOfKey(key)
			if (!dayName) return key
			const index = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'].indexOf(dayName)
			return addDaysToKey(key, index === 0 ? -6 : 1 - index)
		},

		formatDate(ymd) {
			const [y, m, d] = ymd.split('-').map(Number)
			const date = new Date(y, m - 1, d)
			return date.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })
		},

		isOverdue(item) {
			// `dueDate` (occurrence key) is already house-local; a bare
			// nextDue instant is bucketed in the house TZ.
			const due = item.dueDate || dateKeyInZone(item.roomTask?.nextDue, this.houseStore.timezone)
			if (!due) return false
			return due < this.todayYmd
		},

		isRecurring(item) {
			return item.task?.recurrenceMode === 'recurring'
		},
	},
}
</script>

<style scoped>
.calendar-view {
	padding: 20px;
	padding-inline-start: var(--cs-view-inline-start);
	max-width: 1200px;
	margin: 0 auto;
}

.calendar-header h1 {
	margin-bottom: 12px;
}

.calendar-controls {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
}

.calendar-mode-toggle {
	display: flex;
	border: 1px solid var(--color-border, #ddd);
	border-radius: 6px;
	overflow: hidden;
}

.toggle-btn {
	padding: 6px 14px;
	min-height: 44px;
	border: none;
	background: var(--color-main-background, #fff);
	cursor: pointer;
}

.toggle-btn.active {
	background: var(--color-primary, #0082c9);
	color: var(--color-primary-text, #fff);
}

.calendar-nav {
	display: flex;
	align-items: center;
	gap: 8px;
}

.calendar-nav button {
	padding: 4px 10px;
	min-width: 44px;
	min-height: 44px;
	cursor: pointer;
}

.range-label {
	font-weight: 600;
	min-width: 180px;
	text-align: center;
}

.calendar-loading .skeleton-grid {
	height: 300px;
	background: var(--color-background-dark, #eee);
	border-radius: 8px;
	animation: pulse 1.5s infinite;
}

@keyframes pulse {
	0%, 100% { opacity: 1; }
	50% { opacity: 0.5; }
}

.calendar-error {
	color: var(--color-error-text);
}

.day-detail {
	margin-top: 20px;
	padding: 16px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: 8px;
}

.day-task-list {
	list-style: none;
	padding: 0;
	margin: 8px 0;
}

.task-item {
	display: flex;
	justify-content: space-between;
	padding: 6px 8px;
	border-radius: 4px;
}

.task-item.completed {
	opacity: 0.5;
	text-decoration: line-through;
}

.task-item.overdue {
	background: var(--color-error-hover, #fde8e8);
}

.task-repeat {
	opacity: 0.6;
	font-size: 0.9em;
}

.close-detail {
	margin-top: 8px;
	cursor: pointer;
}
</style>
