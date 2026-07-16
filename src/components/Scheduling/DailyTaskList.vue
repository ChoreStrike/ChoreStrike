<template>
	<div class="daily-task-list" data-testid="daily-task-list">
		<div class="list-header">
			<h2>{{ t('chorestrike', 'Today\'s Tasks') }}</h2>
			<div class="date-display">
				{{ formatDate(new Date()) }}
			</div>
		</div>

		<!-- Loading State -->
		<div v-if="loading" class="loading-state">
			<div class="loading-spinner" />
			<p>{{ t('chorestrike', 'Loading tasks...') }}</p>
		</div>

		<!-- Empty State -->
		<div v-else-if="tasks.length === 0" class="empty-state">
			<div class="empty-icon">
				🎉
			</div>
			<h3>{{ t('chorestrike', 'No Tasks Today') }}</h3>
			<p>{{ t('chorestrike', 'You\'re all caught up! Enjoy your free time.') }}</p>
		</div>

		<!-- Task List -->
		<div v-else class="tasks-container">
			<div class="progress-summary">
				<div class="progress-text">
					{{ completedCount }} {{ t('chorestrike', 'of') }} {{ tasks.length }} {{ t('chorestrike', 'completed') }}
				</div>
				<div class="progress-bar">
					<div
						class="progress-fill"
						:style="{ width: progressPercentage + '%' }" />
				</div>
			</div>

			<div ref="tasksListRef" class="tasks-list">
				<div
					v-for="task in sortedTasks"
					:key="task.id"
					:class="['task-item', { completed: task.completed, overdue: task.isOverdue }]"
					:draggable="true"
					@dragstart="handleDragStart($event, task)"
					@dragend="handleDragEnd"
					@dragover.prevent
					@drop="handleDrop($event, task)">
					<div class="task-drag-handle">
						⋮⋮
					</div>

					<div class="task-checkbox">
						<input
							:type="task.allowTeamwork ? 'checkbox' : 'checkbox'"
							:checked="task.completed"
							@change="toggleTask(task)">
					</div>

					<div class="task-content">
						<div class="task-name">
							{{ task.name }}
						</div>
						<div class="task-meta">
							<span class="room-name">{{ task.roomName }}</span>
							<span class="energy-cost">⚡ {{ task.energyCost }}</span>
							<span v-if="task.priority >= 3" class="priority-badge high">{{ t('chorestrike', 'High') }}</span>
						</div>
					</div>

					<div class="task-actions">
						<button
							class="action-btn reschedule"
							:title="t('chorestrike', 'Reschedule')"
							@click="rescheduleTask(task)">
							📅
						</button>
						<button
							class="action-btn skip"
							:title="t('chorestrike', 'Skip')"
							@click="skipTask(task)">
							⏭️
						</button>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { logger } from '../../utils/logger.js'

defineProps({
	userId: {
		type: String,
		required: true,
	},
})

const loading = ref(true)
const tasks = ref([])
const tasksListRef = ref(null)
const draggedTask = ref(null)

const completedCount = computed(() => tasks.value.filter(t => t.completed).length)
const progressPercentage = computed(() => {
	if (tasks.value.length === 0) return 0
	return (completedCount.value / tasks.value.length) * 100
})

const sortedTasks = computed(() => {
	return [...tasks.value].sort((a, b) => {
		// Completed tasks go to bottom
		if (a.completed !== b.completed) {
			return a.completed ? 1 : -1
		}
		// Overdue tasks first
		if (a.isOverdue !== b.isOverdue) {
			return a.isOverdue ? -1 : 1
		}
		// Then by priority
		if (a.priority !== b.priority) {
			return b.priority - a.priority
		}
		// Then by order
		return a.order - b.order
	})
})

const loadTasks = async () => {
	loading.value = true
	try {
		const response = await axios.get('/api/v1/tasks/daily')
		if (response.data.success) {
			tasks.value = response.data.data
		}
	} catch (error) {
		logger.error('Failed to load tasks', error, { component: 'DailyTaskList', action: 'loadTasks' })
	} finally {
		loading.value = false
	}
}

const toggleTask = async (task) => {
	try {
		if (task.completed) {
			await axios.put(`/api/v1/tasks/${task.id}/uncomplete`)
		} else {
			await axios.put(`/api/v1/tasks/${task.id}/complete`)
		}
		task.completed = !task.completed
	} catch (error) {
		logger.error('Failed to toggle task', error, { component: 'DailyTaskList', action: 'toggleTask', taskId: task.id })
	}
}

const rescheduleTask = () => {
}

const skipTask = async (task) => {
	try {
		await axios.put(`/api/v1/tasks/${task.id}/skip`)
		// Remove from list
		tasks.value = tasks.value.filter(t => t.id !== task.id)
	} catch (error) {
		logger.error('Failed to skip task', error, { component: 'DailyTaskList', action: 'skipTask', taskId: task.id })
	}
}

const handleDragStart = (event, task) => {
	draggedTask.value = task
	if (event.dataTransfer) {
		event.dataTransfer.effectAllowed = 'move'
	}
}

const handleDragEnd = () => {
	draggedTask.value = null
}

const handleDrop = (event, targetTask) => {
	event.preventDefault()
	if (!draggedTask.value || draggedTask.value.id === targetTask.id) {
		return
	}

	// Reorder tasks
	const draggedIndex = tasks.value.findIndex(t => t.id === draggedTask.value.id)
	const targetIndex = tasks.value.findIndex(t => t.id === targetTask.id)

	if (draggedIndex !== -1 && targetIndex !== -1) {
		const [removed] = tasks.value.splice(draggedIndex, 1)
		tasks.value.splice(targetIndex, 0, removed)

		// Update order values
		tasks.value.forEach((task, index) => {
			task.order = index
		})
	}
}

const formatDate = (date) => {
	return date.toLocaleDateString(undefined, {
		weekday: 'long',
		year: 'numeric',
		month: 'long',
		day: 'numeric',
	})
}

onMounted(() => {
	loadTasks()
})
</script>

<style scoped>
.daily-task-list {
	background: var(--color-main-background);
	border-radius: 12px;
	padding: 1.5rem;
}

.list-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 1.5rem;
}

.list-header h2 {
	font-size: 1.5rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0;
}

.date-display {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
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

.empty-state {
	text-align: center;
	padding: 3rem 0;
}

.empty-icon {
	font-size: 3rem;
	margin-bottom: 1rem;
}

.empty-state h3 {
	font-size: 1.2rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.5rem;
}

.empty-state p {
	color: var(--color-text-maxcontrast);
}

.progress-summary {
	margin-bottom: 1.5rem;
	padding: 1rem;
	background: var(--color-background-dark);
	border-radius: 8px;
}

.progress-text {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
	margin-bottom: 0.5rem;
}

.progress-bar {
	height: 8px;
	background: var(--color-border);
	border-radius: 4px;
	overflow: hidden;
}

.progress-fill {
	height: 100%;
	background: var(--color-primary);
	transition: width 0.3s ease;
}

.tasks-list {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.task-item {
	display: flex;
	align-items: center;
	gap: 1rem;
	padding: 1rem;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	transition: all 0.2s ease;
}

.task-item:hover {
	border-color: var(--color-primary);
}

.task-item.completed {
	opacity: 0.6;
	background: var(--color-background-dark);
}

.task-item.completed .task-name {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

.task-item.overdue {
	border-left: 4px solid var(--color-error);
}

.task-drag-handle {
	color: var(--color-text-maxcontrast);
	cursor: grab;
	font-size: 1rem;
	padding: 0.25rem;
}

.task-drag-handle:active {
	cursor: grabbing;
}

.task-checkbox input {
	width: 20px;
	height: 20px;
	cursor: pointer;
}

.task-content {
	flex: 1;
}

.task-name {
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.25rem;
}

.task-meta {
	display: flex;
	gap: 0.75rem;
	align-items: center;
	font-size: 0.85rem;
}

.room-name {
	color: var(--color-text-maxcontrast);
}

.energy-cost {
	color: var(--color-primary);
}

.priority-badge {
	padding: 0.25rem 0.5rem;
	border-radius: 4px;
	font-size: 0.75rem;
	font-weight: 600;
}

.priority-badge.high {
	background: #ffccbc;
	color: #d84315;
}

.task-actions {
	display: flex;
	gap: 0.5rem;
}

.action-btn {
	padding: 0.5rem;
	background: transparent;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	cursor: pointer;
	font-size: 1rem;
	transition: all 0.2s ease;
}

.action-btn:hover {
	background: var(--color-background-dark);
	border-color: var(--color-primary);
}

@media (max-width: 768px) {
	.task-item {
		flex-wrap: wrap;
	}

	.task-actions {
		width: 100%;
		justify-content: flex-end;
		margin-top: 0.5rem;
	}
}
</style>
