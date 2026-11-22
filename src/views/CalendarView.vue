<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="calendar-view" data-testid="calendar-view">
		<header class="calendar-header">
			<h1>{{ t('chorestrike', 'Calendar') }}</h1>
			<CalendarControls
				:mode="mode"
				:rangeLabel="rangeLabel"
				@update:mode="mode = $event"
				@navigate="navigate" />
			<SegmentedSwitch
				v-if="mode === 'week'"
				v-model="filter"
				class="calendar-filter"
				:options="filterOptions"
				:label="t('chorestrike', 'Task filter')"
				testidPrefix="calendar-filter" />
		</header>
		<p v-if="mode === 'week'" class="calendar-hint" data-testid="calendar-hint">
			{{ t('chorestrike', 'A suggestion, not a plan: it adapts as soon as something is done or postponed.') }}
		</p>

		<div v-if="loading" class="calendar-loading">
			<div class="skeleton skeleton-grid" />
		</div>

		<div v-else-if="error" class="calendar-error" data-testid="calendar-error">
			<p>{{ error }}</p>
		</div>

		<!-- The week is a list of days with the live suggestion (ADR-022);
		     the month keeps the grid of the planned days. -->
		<CalendarAgenda
			v-else-if="mode === 'week'"
			:days="agendaDays"
			:members="userStore.houseUsers || []"
			:userId="myUserId"
			:horizonDays="schedulingStore.suggestion.horizonDays" />
		<TaskCalendar
			v-else
			:mode="mode"
			:days="visibleDays"
			:tasksByDay="roomTasksStore.rangeTasksByDay"
			@selectDay="selectedDay = $event" />

		<div v-if="selectedDay" class="day-detail" data-testid="calendar-day-list">
			<h3>{{ formatDayKey(selectedDay) }}</h3>
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
import SegmentedSwitch from '../components/Common/SegmentedSwitch.vue'
import CalendarAgenda from '../components/Tasks/CalendarAgenda.vue'
import CalendarControls from '../components/Tasks/CalendarControls.vue'
import TaskCalendar from '../components/Tasks/TaskCalendar.vue'
import { useHouseMemberNames } from '../composables/useHouseMemberNames.js'
import { useHouseStore } from '../store/house.js'
import { useRoomTasksStore } from '../store/roomTasks.js'
import { useSchedulingStore } from '../store/scheduling.js'
import { useUserStore } from '../store/user.js'
import { buildAgendaDays } from '../utils/calendarAgenda.js'
import { addDaysToKey, formatDayKey, todayKeyInZone, weekdayOfKey } from '../utils/dateKeys.js'
import { logger } from '../utils/logger.js'
import { isOverdue as isRowOverdue } from '../utils/taskDisplay.js'

/**
 * The calendar (CS-PLAN-091 WP-C): the week as a list of days with the
 * live suggestion and the viewer's energy (ADR-022), the month as the grid
 * of the planned days. Both read the range of the shown days; the week
 * adds the suggestion for the days inside its horizon.
 */
export default {
	name: 'CalendarView',

	components: {
		CalendarAgenda,
		CalendarControls,
		SegmentedSwitch,
		TaskCalendar,
	},

	setup() {
		const roomTasksStore = useRoomTasksStore()
		const houseStore = useHouseStore()
		const schedulingStore = useSchedulingStore()
		const userStore = useUserStore()
		return { roomTasksStore, houseStore, schedulingStore, userStore, ...useHouseMemberNames(userStore) }
	},

	data() {
		return {
			mode: 'week',
			// Whose work the week shows; the month shows the household.
			filter: 'mine',
			// CS-PLAN-057: the grid is anchored on the house-local "today"
			// key so its day keys agree with the backend's bucketing.
			anchorDate: this.mondayOfKey(todayKeyInZone(useHouseStore().timezone)),
			selectedDay: null,
			loading: false,
			error: null,
		}
	},

	computed: {
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
			return `${formatDayKey(this.rangeStart)} – ${formatDayKey(this.rangeEnd)}`
		},

		filterOptions() {
			return [
				{ value: 'mine', label: t('chorestrike', 'My tasks') },
				{ value: 'all', label: t('chorestrike', 'Everyone') },
			]
		},

		todayKey() {
			return todayKeyInZone(this.houseStore.timezone)
		},

		myUserId() {
			return this.userStore.currentUser?.nextcloudUserId || null
		},

		agendaDays() {
			return buildAgendaDays({
				days: this.visibleDays,
				todayKey: this.todayKey,
				suggestion: this.schedulingStore.suggestion,
				rangeByDay: this.roomTasksStore.rangeTasksByDay,
				userId: this.myUserId,
				mineOnly: this.filter === 'mine',
			})
		},

		dayDetail() {
			if (!this.selectedDay) {
				return { scheduled: [], completed: [] }
			}
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

		mode() {
			this.fetchData()
		},
	},

	methods: {
		t,
		formatDayKey,

		async fetchData() {
			this.loading = true
			this.error = null
			const [range, suggestion] = await Promise.allSettled([
				this.roomTasksStore.fetchRangeTasks(this.rangeStart, this.rangeEnd),
				this.fetchSuggestion(),
			])
			// A week wholly beyond the last known horizon shows the due windows of the range read without a suggestion.
			const horizonEndOn = this.schedulingStore.suggestion.horizonEndOn
			const beyond = horizonEndOn !== null && this.rangeStart > horizonEndOn
			const failure = range.status === 'rejected' ? range.reason : (suggestion.status === 'rejected' && !beyond ? suggestion.reason : null)
			if (failure) {
				this.error = failure.message
				logger.error('Calendar fetch failed', failure, { view: 'CalendarView' })
			}
			this.loading = false
		},

		/**
		 * The suggestion for the shown week. The server cuts the range to
		 * its horizon and names it, so a week beyond it still learns where
		 * the suggestion ends; a week that is over, and the month, show
		 * none.
		 */
		async fetchSuggestion() {
			if (this.mode !== 'week' || this.rangeEnd < this.todayKey) {
				this.schedulingStore.clearSuggestion()
				return
			}
			await this.schedulingStore.fetchSuggestion(this.rangeStart, this.rangeEnd)
		},

		navigate(direction) {
			const step = this.mode === 'week' ? 7 : 30
			this.anchorDate = addDaysToKey(this.anchorDate, direction * step)
			this.selectedDay = null
		},

		mondayOfKey(key) {
			const dayName = weekdayOfKey(key)
			if (!dayName) {
				return key
			}
			const index = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'].indexOf(dayName)
			return addDaysToKey(key, index === 0 ? -6 : 1 - index)
		},

		isOverdue(item) {
			// CR-084-01: a day entry is overdue when its cycle's window has
			// ended without the work (server `late` mark) — a past
			// planned day inside an open flex window is still due. Entries
			// without a mark follow the row rule of the task lists.
			if (typeof item.late === 'boolean') {
				return item.late
			}
			return isRowOverdue(item.roomTask)
		},

		isRecurring(item) {
			return item.task?.recurrenceMode === 'recurring'
		},
	},
}
</script>

<style scoped>
.calendar-view {
	padding: 20px var(--cs-view-gutter);
	max-width: 1200px;
	margin: 0 auto;
}

.calendar-header {
	padding-inline-start: var(--cs-view-heading-inset);
}

.calendar-header h1 {
	margin-bottom: 12px;
}

.calendar-hint {
	margin: 0.5rem 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.calendar-filter {
	max-width: 20rem;
	margin-block: 0.5rem 0;
}
</style>
