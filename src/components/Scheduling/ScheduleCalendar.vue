<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="schedule-calendar" data-testid="schedule-calendar">
		<div class="calendar-header">
			<h2>{{ t('chorestrike', 'Schedule Calendar') }}</h2>
			<div class="calendar-controls">
				<div class="date-navigation">
					<button
						class="nav-button"
						@click="previousWeek"
						data-testid="previous-week-button">
						<Icon name="ChevronLeft" :size="16" />
					</button>
					<div class="current-week">
						<span class="week-range">{{ weekRange }}</span>
					</div>
					<button
						class="nav-button"
						@click="nextWeek"
						data-testid="next-week-button">
						<Icon name="ChevronRight" :size="16" />
					</button>
				</div>
				<div class="view-controls">
					<button
						class="today-button"
						@click="goToToday"
						data-testid="today-button">
						{{ t('chorestrike', 'Today') }}
					</button>
					<div class="view-toggle">
						<button
							:class="['view-button', { active: viewMode === 'week' }]"
							@click="setViewMode('week')"
							data-testid="week-view-button">
							{{ t('chorestrike', 'Week') }}
						</button>
						<button
							:class="['view-button', { active: viewMode === 'day' }]"
							@click="setViewMode('day')"
							data-testid="day-view-button">
							{{ t('chorestrike', 'Day') }}
						</button>
					</div>
				</div>
			</div>
		</div>

		<!-- Loading State -->
		<div v-if="loading" class="loading-state">
			<div class="loading-spinner"></div>
			<p>{{ t('chorestrike', 'Loading schedule calendar...') }}</p>
		</div>

		<!-- Calendar Grid -->
		<div v-else class="calendar-content">
			<!-- Week View -->
			<div v-if="viewMode === 'week'" class="week-view">
				<div class="week-header">
					<div class="time-column"></div>
					<div
						v-for="day in weekDays"
						:key="day.date"
						:class="['day-header', { 'today': isToday(day.date), 'weekend': isWeekend(day.date) }]">
						<div class="day-info">
							<span class="day-name">{{ day.name }}</span>
							<span class="day-date">{{ day.dayNumber }}</span>
						</div>
						<div class="day-capacity">
							<span class="capacity-label">{{ t('chorestrike', 'Capacity') }}:</span>
							<span class="capacity-value">{{ day.capacity }}</span>
						</div>
					</div>
				</div>

				<div class="time-slots">
					<div
						v-for="hour in timeSlots"
						:key="hour"
						class="hour-row">
						<div class="time-label">
							<span>{{ hour }}:00</span>
						</div>
						<div
							v-for="day in weekDays"
							:key="`${day.date}-${hour}`"
							:class="['time-slot', { 'has-task': hasTaskAtTime(day.date, hour) }]"
							@click="slotClicked(day.date, hour)"
							:data-testid="`slot-${day.date}-${hour}`">
							<div
								v-if="hasTaskAtTime(day.date, hour)"
								class="task-item"
								@click.stop="taskClicked(getTaskAtTime(day.date, hour))">
								<div class="task-title">{{ getTaskAtTime(day.date, hour)?.title }}</div>
								<div class="task-assignee">{{ getTaskAtTime(day.date, hour)?.assignee }}</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Day View -->
			<div v-else class="day-view">
				<div class="day-header-single">
					<div class="day-info-large">
						<span class="day-name-large">{{ currentDay.name }}</span>
						<span class="day-date-large">{{ currentDay.fullDate }}</span>
					</div>
					<div class="day-summary">
						<div class="summary-item">
							<span class="summary-label">{{ t('chorestrike', 'Tasks') }}:</span>
							<span class="summary-value">{{ currentDayTasks.length }}</span>
						</div>
						<div class="summary-item">
							<span class="summary-label">{{ t('chorestrike', 'Capacity') }}:</span>
							<span class="summary-value">{{ currentDay.capacity }}</span>
						</div>
						<div class="summary-item">
							<span class="summary-label">{{ t('chorestrike', 'Used') }}:</span>
							<span class="summary-value">{{ currentDayUsedEnergy }}</span>
						</div>
					</div>
				</div>

				<div class="day-timeline">
					<div
						v-for="hour in timeSlots"
						:key="hour"
						class="timeline-hour">
						<div class="hour-time">
							<span>{{ hour }}:00</span>
						</div>
						<div
							class="hour-content"
							@click="slotClicked(currentDay.date, hour)"
							:data-testid="`day-slot-${hour}`">
							<div
								v-for="task in getTasksAtHour(currentDay.date, hour)"
								:key="task.id"
								class="task-card"
								@click.stop="taskClicked(task)">
								<div class="task-header">
									<span class="task-time">{{ task.startTime }} - {{ task.endTime }}</span>
									<span class="task-priority" :class="`priority-${task.priority}`">
										{{ getPriorityLabel(task.priority) }}
									</span>
								</div>
								<div class="task-title">{{ task.title }}</div>
								<div class="task-details">
									<span class="task-assignee">{{ task.assignee }}</span>
									<span class="task-energy">{{ task.energyCost }} {{ t('chorestrike', 'energy') }}</span>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Task Details Modal -->
		<Modal
			v-if="showTaskModal"
			:title="t('chorestrike', 'Task Details')"
			@close="closeTaskModal"
			data-testid="task-details-modal">
			<div v-if="selectedTask" class="task-details">
				<div class="task-info">
					<h3>{{ selectedTask.title }}</h3>
					<div class="task-meta">
						<div class="meta-item">
							<span class="meta-label">{{ t('chorestrike', 'Assigned to') }}:</span>
							<span class="meta-value">{{ selectedTask.assignee }}</span>
						</div>
						<div class="meta-item">
							<span class="meta-label">{{ t('chorestrike', 'Priority') }}:</span>
							<span class="meta-value" :class="`priority-${selectedTask.priority}`">
								{{ getPriorityLabel(selectedTask.priority) }}
							</span>
						</div>
						<div class="meta-item">
							<span class="meta-label">{{ t('chorestrike', 'Energy Cost') }}:</span>
							<span class="meta-value">{{ selectedTask.energyCost }} {{ t('chorestrike', 'energy') }}</span>
						</div>
						<div class="meta-item">
							<span class="meta-label">{{ t('chorestrike', 'Scheduled Time') }}:</span>
							<span class="meta-value">{{ selectedTask.startTime }} - {{ selectedTask.endTime }}</span>
						</div>
					</div>
				</div>

				<div class="task-actions">
					<button
						class="action-button reschedule"
						@click="rescheduleTask"
						data-testid="reschedule-task-button">
						<Icon name="Calendar" :size="16" />
						{{ t('chorestrike', 'Reschedule') }}
					</button>
					<button
						class="action-button reassign"
						@click="reassignTask"
						data-testid="reassign-task-button">
						<Icon name="Users" :size="16" />
						{{ t('chorestrike', 'Reassign') }}
					</button>
					<button
						class="action-button delete"
						@click="unassignTask"
						data-testid="unassign-task-button">
						<Icon name="Trash" :size="16" />
						{{ t('chorestrike', 'Unassign') }}
					</button>
				</div>
			</div>

			<template #footer>
				<div class="modal-footer">
					<button class="secondary-button" @click="closeTaskModal">
						{{ t('chorestrike', 'Close') }}
					</button>
				</div>
			</template>
		</Modal>

		<!-- Add Task Modal -->
		<Modal
			v-if="showAddTaskModal"
			:title="t('chorestrike', 'Add Task to Schedule')"
			@close="closeAddTaskModal"
			data-testid="add-task-modal">
			<div class="add-task-form">
				<div class="form-group">
					<label for="task-time">{{ t('chorestrike', 'Time') }}:</label>
					<select
						id="task-time"
						v-model="newTask.time"
						class="form-select"
						data-testid="task-time-select">
						<option
							v-for="hour in timeSlots"
							:key="hour"
							:value="hour">
							{{ hour }}:00
						</option>
					</select>
				</div>

				<div class="form-group">
					<label for="task-duration">{{ t('chorestrike', 'Duration (hours)') }}:</label>
					<input
						id="task-duration"
						v-model.number="newTask.duration"
						type="number"
						min="1"
						max="8"
						class="form-input"
						data-testid="task-duration-input" />
				</div>

				<div class="form-group">
					<label for="task-member">{{ t('chorestrike', 'Assign to') }}:</label>
					<select
						id="task-member"
						v-model="newTask.memberId"
						class="form-select"
						data-testid="task-member-select">
						<option value="">{{ t('chorestrike', 'Select member') }}</option>
						<option
							v-for="member in availableMembers"
							:key="member.userId"
							:value="member.userId">
							{{ member.userName }}
						</option>
					</select>
				</div>
			</div>

			<template #footer>
				<div class="modal-footer">
					<button class="secondary-button" @click="closeAddTaskModal">
						{{ t('chorestrike', 'Cancel') }}
					</button>
					<button
						class="primary-button"
						@click="addTaskToSchedule"
						:disabled="!canAddTask"
						data-testid="add-task-button">
						{{ t('chorestrike', 'Add Task') }}
					</button>
				</div>
			</template>
		</Modal>
	</div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import Icon from '../Common/Icon.vue'
import Modal from '../Common/Modal.vue'
import axios from '@nextcloud/axios'

type ViewMode = 'week' | 'day'

interface CalendarDay {
	date: string
	name: string
	dayNumber: number
	fullDate: string
	capacity: number
}

interface MemberInfo {
	userId: number
	userName: string
}

interface RawAssignment {
	taskId?: number
	taskTitle?: string
	assignedTo?: number
	date?: string
	energyCost?: number
	priority?: number
	scheduledFor?: string
	startTime?: string
	endTime?: string
	title?: string
	assignee?: string
}

interface CalendarTask {
	id: number
	title: string
	assignee: string
	priority: number
	energyCost: number
	scheduledFor: string
	startTime: string
	endTime: string
}

interface SchedulePayload {
	assignments?: RawAssignment[]
}

interface SlotSelection {
	date: string | null
	hour: number | null
}

interface NewTaskForm {
	time: number | null
	duration: number
	memberId: string
}

// Props
const props = defineProps<{
	houseId: number
	schedule: SchedulePayload | null
}>()

// Emits
const emit = defineEmits<{
	taskMoved: [taskUpdate: { date: string | null; startTime: string; endTime: string; memberId: string }]
}>()

// Reactive state
const loading = ref(false)
const viewMode = ref<ViewMode>('week')
const currentWeekStart = ref(new Date())
const weekDays = ref<CalendarDay[]>([])
const timeSlots = ref(Array.from({ length: 12 }, (_, i) => i + 8)) // 8 AM to 8 PM
const showTaskModal = ref(false)
const showAddTaskModal = ref(false)
const selectedTask = ref<CalendarTask | null>(null)
const selectedSlot = ref<SlotSelection>({ date: null, hour: null })
const availableMembers = ref<MemberInfo[]>([])
const newTask = ref<NewTaskForm>({
	time: null,
	duration: 1,
	memberId: ''
})

const normalizedAssignments = computed<CalendarTask[]>(() => {
	const assignments = props.schedule?.assignments ?? []

	return assignments.map((task: RawAssignment, index: number) => {
		const startHour = task.startTime ? parseInt(task.startTime, 10) : 9
		const safeStartHour = Number.isNaN(startHour) ? 9 : startHour
		const safeEndHour = task.endTime
			? parseInt(task.endTime, 10)
			: safeStartHour + 1

		return {
			id: task.taskId ?? index + 1,
			title: task.taskTitle ?? task.title ?? t('chorestrike', 'Task'),
			assignee: task.assignee ?? (task.assignedTo ? `User #${task.assignedTo}` : t('chorestrike', 'Unassigned')),
			priority: task.priority ?? 3,
			energyCost: task.energyCost ?? 5,
			scheduledFor: task.date ?? task.scheduledFor ?? new Date().toISOString().split('T')[0],
			startTime: `${safeStartHour}:00`,
			endTime: `${safeEndHour}:00`,
		}
	})
})

// Computed properties
const weekRange = computed(() => {
	const start = new Date(currentWeekStart.value)
	const end = new Date(start)
	end.setDate(start.getDate() + 6)
	
	return `${start.toLocaleDateString()} - ${end.toLocaleDateString()}`
})

const currentDay = computed(() => {
	const today = new Date().toISOString().split('T')[0]
	return weekDays.value.find((day: CalendarDay) => day.date === today) ?? weekDays.value[0] ?? null
})

const currentDayTasks = computed(() => {
	if (!currentDay.value) return []
	return normalizedAssignments.value.filter((task: CalendarTask) => 
		task.scheduledFor === currentDay.value.date
	)
})

const currentDayUsedEnergy = computed(() => {
	return currentDayTasks.value.reduce((total: number, task: CalendarTask) => total + (task.energyCost || 0), 0)
})

const canAddTask = computed(() => {
	return newTask.value.time !== null && newTask.value.duration > 0 && newTask.value.memberId !== ''
})

// Methods
const loadCalendarData = async () => {
	if (!props.houseId) return
	
	loading.value = true
	try {
		// Load week days with capacity info
		await generateWeekDays()
		
		// Load available members
		const response = await axios.get(`/api/v1/smart-schedule/availability`)
		if (response.data.success) {
			availableMembers.value = response.data.data as MemberInfo[]
		}
	} catch (error) {
		console.error('Failed to load calendar data:', error)
	} finally {
		loading.value = false
	}
}

const generateWeekDays = () => {
	const start = new Date(currentWeekStart.value)
	const days = []
	
	for (let i = 0; i < 7; i++) {
		const date = new Date(start)
		date.setDate(start.getDate() + i)
		
		const dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']
		const dayName = t('chorestrike', dayNames[date.getDay()])
		
		days.push({
			date: date.toISOString().split('T')[0],
			name: dayName,
			dayNumber: date.getDate(),
			fullDate: date.toLocaleDateString(),
			capacity: getDayCapacity(date.toISOString().split('T')[0])
		})
	}
	
	weekDays.value = days
}

const getDayCapacity = (_date: string): number => {
	// This would come from the schedule data or member availability
	// For now, return a default value
	return 15
}

const isToday = (date: string): boolean => {
	return date === new Date().toISOString().split('T')[0]
}

const isWeekend = (date: string): boolean => {
	const day = new Date(date).getDay()
	return day === 0 || day === 6
}

const hasTaskAtTime = (date: string, hour: number): boolean => {
	return getTaskAtTime(date, hour) !== null
}

const getTaskAtTime = (date: string, hour: number): CalendarTask | null => {
	return normalizedAssignments.value.find((task: CalendarTask) => 
		task.scheduledFor === date && 
		task.startTime === `${hour}:00`
	) || null
}

const getTasksAtHour = (date: string, hour: number): CalendarTask[] => {
	return normalizedAssignments.value.filter((task: CalendarTask) => 
		task.scheduledFor === date && 
		parseInt(task.startTime) === hour
	)
}

const getPriorityLabel = (priority: number): string => {
	const labels = {
		1: t('chorestrike', 'Critical'),
		2: t('chorestrike', 'High'),
		3: t('chorestrike', 'Medium'),
		4: t('chorestrike', 'Low'),
		5: t('chorestrike', 'Very Low')
	} as Record<number, string>
	return labels[priority] || t('chorestrike', 'Unknown')
}

const previousWeek = () => {
	const newStart = new Date(currentWeekStart.value)
	newStart.setDate(newStart.getDate() - 7)
	currentWeekStart.value = newStart
	generateWeekDays()
}

const nextWeek = () => {
	const newStart = new Date(currentWeekStart.value)
	newStart.setDate(newStart.getDate() + 7)
	currentWeekStart.value = newStart
	generateWeekDays()
}

const goToToday = () => {
	const today = new Date()
	const dayOfWeek = today.getDay()
	const startOfWeek = new Date(today)
	startOfWeek.setDate(today.getDate() - dayOfWeek)
	currentWeekStart.value = startOfWeek
	generateWeekDays()
}

const setViewMode = (mode: ViewMode): void => {
	viewMode.value = mode
}

const slotClicked = (date: string, hour: number): void => {
	selectedSlot.value = { date, hour }
	showAddTaskModal.value = true
	newTask.value.time = hour
}

const taskClicked = (task: CalendarTask | null): void => {
	if (!task) {
		return
	}
	selectedTask.value = task
	showTaskModal.value = true
}

const closeTaskModal = () => {
	showTaskModal.value = false
	selectedTask.value = null
}

const closeAddTaskModal = () => {
	showAddTaskModal.value = false
	selectedSlot.value = { date: null, hour: null }
	newTask.value = { time: null, duration: 1, memberId: '' }
}

const addTaskToSchedule = async () => {
	if (!canAddTask.value) return
	
	try {
		const taskTime = newTask.value.time
		if (taskTime === null) {
			return
		}

		const taskData = {
			date: selectedSlot.value.date,
			startTime: `${taskTime}:00`,
			endTime: `${taskTime + newTask.value.duration}:00`,
			memberId: newTask.value.memberId
		}
		
		// This would call the API to add the task
		emit('taskMoved', taskData)
		
		closeAddTaskModal()
	} catch (error) {
		console.error('Failed to add task:', error)
	}
}

const rescheduleTask = () => {
	// Implementation for rescheduling
	closeTaskModal()
}

const reassignTask = () => {
	// Implementation for reassigning
	closeTaskModal()
}

const unassignTask = () => {
	// Implementation for unassigning
	closeTaskModal()
}

// Lifecycle
onMounted(() => {
	if (props.houseId) {
		loadCalendarData()
	}
})

watch(() => props.schedule, () => {
	generateWeekDays()
}, { deep: true })
</script>

<style scoped>
.schedule-calendar {
	background: var(--color-main-background);
	border-radius: 12px;
	padding: 1.5rem;
}

.calendar-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 2rem;
	flex-wrap: wrap;
	gap: 1rem;
}

.calendar-header h2 {
	font-size: 1.5rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0;
}

.calendar-controls {
	display: flex;
	align-items: center;
	gap: 1rem;
	flex-wrap: wrap;
}

.date-navigation {
	display: flex;
	align-items: center;
	gap: 1rem;
}

.nav-button {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 36px;
	height: 36px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	transition: all 0.2s ease;
}

.nav-button:hover {
	background: var(--color-background-dark);
}

.current-week {
	font-weight: 500;
	color: var(--color-main-text);
	min-width: 150px;
	text-align: center;
}

.view-controls {
	display: flex;
	align-items: center;
	gap: 1rem;
}

.today-button {
	padding: 0.5rem 1rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	transition: all 0.2s ease;
}

.today-button:hover {
	background: var(--color-background-dark);
}

.view-toggle {
	display: flex;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	overflow: hidden;
}

.view-button {
	padding: 0.5rem 1rem;
	border: none;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	transition: all 0.2s ease;
}

.view-button.active {
	background: var(--color-primary);
	color: var(--color-primary-text);
}

.loading-state {
	text-align: center;
	padding: 3rem 0;
}

.loading-spinner {
	width: 40px;
	height: 40px;
	border: 3px solid var(--color-border);
	border-top: 3px solid var(--color-primary);
	border-radius: 50%;
	animation: spin 1s linear infinite;
	margin: 0 auto 1rem;
}

@keyframes spin {
	0% { transform: rotate(0deg); }
	100% { transform: rotate(360deg); }
}

.week-view {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	overflow: hidden;
}

.week-header {
	display: grid;
	grid-template-columns: 80px repeat(7, 1fr);
	background: var(--color-background-dark);
	border-bottom: 1px solid var(--color-border);
}

.time-column {
	padding: 1rem;
	border-right: 1px solid var(--color-border);
}

.day-header {
	padding: 1rem;
	border-right: 1px solid var(--color-border);
	text-align: center;
}

.day-header.today {
	background: rgba(33, 150, 243, 0.1);
}

.day-header.weekend {
	background: rgba(255, 152, 0, 0.05);
}

.day-info {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	margin-bottom: 0.5rem;
}

.day-name {
	font-weight: 600;
	color: var(--color-main-text);
}

.day-date {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.day-capacity {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.capacity-label {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.capacity-value {
	font-weight: 600;
	color: var(--color-primary);
}

.time-slots {
	max-height: 600px;
	overflow-y: auto;
}

.hour-row {
	display: grid;
	grid-template-columns: 80px repeat(7, 1fr);
	border-bottom: 1px solid var(--color-border);
}

.time-label {
	padding: 1rem;
	border-right: 1px solid var(--color-border);
	background: var(--color-background-dark);
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.time-slot {
	padding: 1rem;
	border-right: 1px solid var(--color-border);
	min-height: 60px;
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.time-slot:hover {
	background: var(--color-background-dark);
}

.time-slot.has-task {
	background: rgba(76, 175, 80, 0.1);
}

.task-item {
	background: var(--color-primary);
	color: var(--color-primary-text);
	border-radius: 4px;
	padding: 0.5rem;
	font-size: 0.8rem;
}

.task-title {
	font-weight: 500;
	margin-bottom: 0.25rem;
}

.task-assignee {
	opacity: 0.9;
}

.day-view {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	overflow: hidden;
}

.day-header-single {
	padding: 1.5rem;
	background: var(--color-background-dark);
	border-bottom: 1px solid var(--color-border);
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.day-info-large {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.day-name-large {
	font-size: 1.5rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.day-date-large {
	font-size: 1.1rem;
	color: var(--color-text-maxcontrast);
}

.day-summary {
	display: flex;
	gap: 2rem;
}

.summary-item {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	text-align: center;
}

.summary-label {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.summary-value {
	font-weight: 600;
	color: var(--color-main-text);
}

.day-timeline {
	max-height: 600px;
	overflow-y: auto;
}

.timeline-hour {
	display: flex;
	border-bottom: 1px solid var(--color-border);
}

.hour-time {
	width: 80px;
	padding: 1rem;
	border-right: 1px solid var(--color-border);
	background: var(--color-background-dark);
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.hour-content {
	flex: 1;
	padding: 1rem;
	min-height: 80px;
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.hour-content:hover {
	background: var(--color-background-dark);
}

.task-card {
	background: var(--color-primary);
	color: var(--color-primary-text);
	border-radius: 6px;
	padding: 1rem;
	margin-bottom: 0.5rem;
}

.task-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 0.5rem;
}

.task-time {
	font-size: 0.9rem;
	opacity: 0.9;
}

.task-priority {
	padding: 0.25rem 0.5rem;
	border-radius: 4px;
	font-size: 0.7rem;
	font-weight: 500;
}

.priority-1 { background: rgba(244, 67, 54, 0.2); }
.priority-2 { background: rgba(255, 152, 0, 0.2); }
.priority-3 { background: rgba(76, 175, 80, 0.2); }
.priority-4 { background: rgba(33, 150, 243, 0.2); }
.priority-5 { background: rgba(156, 39, 176, 0.2); }

.task-details {
	display: flex;
	justify-content: space-between;
	align-items: center;
	font-size: 0.8rem;
	opacity: 0.9;
}

/* Modal Styles */
.task-details {
	display: flex;
	flex-direction: column;
	gap: 1.5rem;
}

.task-info h3 {
	font-size: 1.2rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 1rem;
}

.task-meta {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.meta-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.meta-label {
	color: var(--color-text-maxcontrast);
}

.meta-value {
	font-weight: 500;
	color: var(--color-main-text);
}

.task-actions {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.action-button {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.75rem 1rem;
	border: none;
	border-radius: 6px;
	font-weight: 500;
	cursor: pointer;
	transition: all 0.2s ease;
}

.action-button.reschedule {
	background: var(--color-primary);
	color: var(--color-primary-text);
}

.action-button.reassign {
	background: var(--color-secondary);
	color: var(--color-secondary-text);
}

.action-button.delete {
	background: var(--color-error);
	color: var(--color-error-text);
}

.add-task-form {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.form-group label {
	font-weight: 500;
	color: var(--color-main-text);
}

.form-input, .form-select {
	padding: 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.modal-footer {
	display: flex;
	justify-content: flex-end;
	gap: 1rem;
}

.primary-button, .secondary-button {
	padding: 0.75rem 1.5rem;
	border-radius: 6px;
	font-weight: 500;
	cursor: pointer;
	transition: all 0.2s ease;
}

.primary-button {
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
}

.primary-button:hover:not(:disabled) {
	background: var(--color-primary-hover);
}

.primary-button:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.secondary-button {
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
}

.secondary-button:hover {
	background: var(--color-background-dark);
}

@media (max-width: 768px) {
	.calendar-header {
		flex-direction: column;
		align-items: flex-start;
	}
	
	.calendar-controls {
		width: 100%;
		justify-content: space-between;
	}
	
	.week-header {
		grid-template-columns: 60px repeat(7, 1fr);
	}
	
	.hour-row {
		grid-template-columns: 60px repeat(7, 1fr);
	}
	
	.time-label {
		width: 60px;
		font-size: 0.8rem;
	}
	
	.day-header-single {
		flex-direction: column;
		align-items: flex-start;
		gap: 1rem;
	}
	
	.day-summary {
		width: 100%;
		justify-content: space-around;
	}
	
	.timeline-hour {
		flex-direction: column;
	}
	
	.hour-time {
		width: 100%;
		text-align: left;
	}
}
</style>
