<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="task-manager" data-testid="task-manager-view">
		<header class="manager-header">
			<h1 data-testid="task-manager-title">
				{{ t('chorestrike', 'Task Manager') }}
			</h1>
			<button class="primary-button" data-testid="add-task-button" @click="showAddTaskModal = true">
				<Icon name="Plus" :size="16" class="icon" />
				{{ t('chorestrike', 'New Task') }}
			</button>
		</header>

		<div class="filters-section">
			<div class="filter-group">
				<label>{{ t('chorestrike', 'Status') }}</label>
				<select v-model="filterStatus" class="filter-select" data-testid="status-filter">
					<option value="all">
						{{ t('chorestrike', 'All Tasks') }}
					</option>
					<option value="active">
						{{ t('chorestrike', 'Active') }}
					</option>
					<option value="overdue">
						{{ t('chorestrike', 'Overdue') }}
					</option>
					<option value="due-today">
						{{ t('chorestrike', 'Due Today') }}
					</option>
					<option value="completed">
						{{ t('chorestrike', 'Completed') }}
					</option>
				</select>
			</div>

			<div class="filter-group">
				<label>{{ t('chorestrike', 'Priority') }}</label>
				<select v-model="filterPriority" class="filter-select" data-testid="priority-filter">
					<option value="all">
						{{ t('chorestrike', 'All Priorities') }}
					</option>
					<option value="urgent">
						{{ t('chorestrike', 'Urgent') }}
					</option>
					<option value="normal">
						{{ t('chorestrike', 'Normal') }}
					</option>
					<option value="low">
						{{ t('chorestrike', 'Can Wait') }}
					</option>
				</select>
			</div>

			<div class="filter-group">
				<label>{{ t('chorestrike', 'Difficulty') }}</label>
				<select v-model="filterDifficulty" class="filter-select" data-testid="difficulty-filter">
					<option value="all">
						{{ t('chorestrike', 'All Difficulties') }}
					</option>
					<option value="1">
						{{ t('chorestrike', 'Level 1 (Quick)') }}
					</option>
					<option value="2">
						{{ t('chorestrike', 'Level 2 (Easy)') }}
					</option>
					<option value="3">
						{{ t('chorestrike', 'Level 3 (Medium)') }}
					</option>
					<option value="4">
						{{ t('chorestrike', 'Level 4 (Hard)') }}
					</option>
					<option value="5">
						{{ t('chorestrike', 'Level 5 (Major)') }}
					</option>
				</select>
			</div>

			<div class="filter-group search-group">
				<label>{{ t('chorestrike', 'Search') }}</label>
				<div class="search-input-wrapper">
					<Icon name="Search" :size="16" class="search-icon" />
					<input
						v-model="searchQuery"
						type="text"
						class="search-input"
						data-testid="search-input"
						:placeholder="t('chorestrike', 'Search tasks...')">
				</div>
			</div>
		</div>

		<div class="stats-bar">
			<div class="stat" data-testid="total-tasks-stat">
				<span class="stat-number" data-testid="total-tasks-count">{{ totalTasks }}</span>
				<span class="stat-label">{{ t('chorestrike', 'Total') }}</span>
			</div>
			<div class="stat" data-testid="overdue-tasks-stat">
				<span class="stat-number warning" data-testid="overdue-count">{{ overdueTasks }}</span>
				<span class="stat-label">{{ t('chorestrike', 'Overdue') }}</span>
			</div>
			<div class="stat" data-testid="due-today-stat">
				<span class="stat-number success" data-testid="due-today-count">{{ dueTodayTasks }}</span>
				<span class="stat-label">{{ t('chorestrike', 'Due Today') }}</span>
			</div>
			<div class="stat">
				<span class="stat-number">{{ filteredTasks.length }}</span>
				<span class="stat-label">{{ t('chorestrike', 'Shown') }}</span>
			</div>
		</div>

		<TaskList
			:room-tasks="filteredTasks"
			:loading="loading"
			:show-room="true"
			@complete="handleCompleteTask"
			@edit="handleEditTask"
			@delete="handleDeleteTask" />

		<Modal v-if="showAddTaskModal" @close="showAddTaskModal = false">
			<TaskEditForm
				:loading="creating"
				@submit="handleAddTask"
				@cancel="showAddTaskModal = false" />
		</Modal>

		<Modal v-if="showEditTaskModal" @close="showEditTaskModal = false">
			<TaskEditForm
				:task="editingTask"
				@submit="handleUpdateTask"
				@cancel="showEditTaskModal = false" />
		</Modal>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import { useRoomTasksStore } from '../store/roomTasks.js'
import { useRoomsStore } from '../store/rooms.js'
import { useTasksStore } from '../store/tasks.js'
import { logger } from '../utils/logger.js'
import Icon from '../components/Common/Icon.vue'
import TaskList from '../components/Tasks/TaskList.vue'
import TaskEditForm from '../components/Tasks/TaskEditForm.vue'
import { useUserStore } from '../store/user.js'
import Modal from '../components/Common/Modal.vue'

export default {
	name: 'TaskManager',

	components: {
		Icon,
		TaskList,
		TaskEditForm,
		Modal,
	},

	setup() {
		const roomTasksStore = useRoomTasksStore()
		const roomsStore = useRoomsStore()
		const tasksStore = useTasksStore()
		const userStore = useUserStore()
		return { roomTasksStore, roomsStore, tasksStore, userStore }
	},

	data() {
		return {
			loading: false,
			creating: false,
			filterStatus: 'all',
			filterPriority: 'all',
			filterDifficulty: 'all',
			searchQuery: '',
			showAddTaskModal: false,
			showEditTaskModal: false,
			editingTask: null,
		}
	},

	computed: {
		allTasks() {
			return this.roomTasksStore.roomTasks.map(roomTask => ({
				roomTask,
				task: this.tasksStore.getTaskById(roomTask.taskId) || {
					id: roomTask.taskId,
					name: this.t('chorestrike', 'Unknown Task'),
					description: '',
					energyCost: 0,
					estimatedMinutes: 0,
					priority: 2,
					frequencyType: 'weekly',
					frequencyValue: 1,
				},
			}))
		},

		filteredTasks() {
			let tasks = this.allTasks

			// Status filter
			if (this.filterStatus === 'active') {
				tasks = tasks.filter(t => t.roomTask.isActive)
			} else if (this.filterStatus === 'overdue') {
				tasks = tasks.filter(t => {
					if (!t.roomTask.isActive || !t.roomTask.nextDue) return false
					return new Date(t.roomTask.nextDue) < new Date()
				})
			} else if (this.filterStatus === 'due-today') {
				const today = new Date()
				today.setHours(0, 0, 0, 0)
				const tomorrow = new Date(today)
				tomorrow.setDate(tomorrow.getDate() + 1)
				tasks = tasks.filter(t => {
					if (!t.roomTask.isActive || !t.roomTask.nextDue) return false
					const dueDate = new Date(t.roomTask.nextDue)
					return dueDate >= today && dueDate < tomorrow
				})
			} else if (this.filterStatus === 'completed') {
				tasks = tasks.filter(t => !t.roomTask.isActive)
			}

			// Priority filter
			if (this.filterPriority !== 'all') {
				const priorityMap = { urgent: 3, normal: 2, low: 1 }
				tasks = tasks.filter(t => t.task.priority === priorityMap[this.filterPriority])
			}

			// Difficulty filter
			if (this.filterDifficulty !== 'all') {
				tasks = tasks.filter(t => t.task.energyCost === parseInt(this.filterDifficulty, 10))
			}

			// Search filter
			if (this.searchQuery.trim()) {
				const query = this.searchQuery.toLowerCase()
				tasks = tasks.filter(t => t.task.name?.toLowerCase().includes(query))
			}

			return tasks
		},

		totalTasks() {
			return this.allTasks.length
		},

		overdueTasks() {
			return this.roomTasksStore.totalOverdueTasks
		},

		dueTodayTasks() {
			return this.roomTasksStore.totalDueTodayTasks
		},
	},

	async mounted() {
		await this.initializeTasks()
	},

	methods: {
		t,

		async initializeTasks() {
			this.loading = true
			try {
				await this.roomsStore.fetchFloorsWithRooms()
				await this.tasksStore.fetchTasks()
				await Promise.all(this.roomsStore.rooms.map(room => this.roomTasksStore.fetchRoomTasks(room.id)))
			} catch (error) {
				logger.error('Failed to initialize tasks', error, { view: 'TaskManager', action: 'initializeTasks' })
			} finally {
				this.loading = false
			}
		},

		handleCompleteTask(roomTaskId) {
			this.roomTasksStore.completeRoomTask(roomTaskId)
				.then(() => {
					this.$emit('task-completed', roomTaskId)
				})
				.catch(error => {
					logger.error('Failed to complete task', error, { view: 'TaskManager', action: 'handleCompleteTask', roomTaskId })
				})
		},

		handleEditTask(roomTask) {
			this.editingTask = this.tasksStore.getTaskById(roomTask.taskId) || null
			this.showEditTaskModal = true
		},

		handleDeleteTask(roomTaskId) {
			if (!confirm(t('chorestrike', 'Are you sure you want to delete this task?'))) {
				return
			}
			this.roomTasksStore.deleteRoomTask(roomTaskId)
				.catch(error => {
					logger.error('Failed to delete task', error, { view: 'TaskManager', action: 'handleDeleteTask', roomTaskId })
				})
		},

		async handleAddTask(taskData) {
			const { roomId, assigneeUserIds, ...definition } = taskData
			this.creating = true
			try {
				const result = await this.roomTasksStore.createTaskWithRoomTask(
					definition,
					roomId,
					assigneeUserIds || [],
				)
				if (result.roomTaskError) {
					showError(t('chorestrike', 'Task saved, but adding it to the room failed. Try again from the room page.'))
				} else {
					if (result.assignError) {
						showWarning(t('chorestrike', 'Task created, but assigning members failed. You can assign them in the task list.'))
					} else {
						showSuccess(t('chorestrike', 'Task created'))
					}
					this.showAddTaskModal = false
					await this.refreshAfterCreate(roomId)
				}
			} catch (error) {
				showError(t('chorestrike', 'Failed to create task'))
				logger.error('Failed to create task', error, { view: 'TaskManager', action: 'handleAddTask', roomId })
			} finally {
				this.creating = false
			}
		},

		async refreshAfterCreate(roomId) {
			try {
				await this.roomTasksStore.fetchRoomTasks(roomId)
				await this.tasksStore.fetchTasks()
			} catch (error) {
				logger.error('Failed to refresh tasks after creation', error, { view: 'TaskManager', action: 'refreshAfterCreate', roomId })
			}
		},

		handleUpdateTask(taskData) {
			if (!this.editingTask) return
			this.tasksStore.updateTask(this.editingTask.id, taskData)
				.then(() => {
					this.showEditTaskModal = false
					this.editingTask = null
				})
				.catch(error => {
					logger.error('Failed to update task', error, { view: 'TaskManager', action: 'handleUpdateTask', taskId: this.editingTask?.id })
				})
		},
	},
}
</script>

<style scoped>
.task-manager {
	padding: 1rem 2rem 2rem;
	max-width: 1400px;
	margin: 0 auto;
}

.manager-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 2rem;
	flex-wrap: wrap;
	gap: 1rem;
}

.manager-header h1 {
	font-size: 2rem;
	font-weight: bold;
	margin: 0;
}

.primary-button {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.75rem 1.25rem;
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
	border-radius: 8px;
	cursor: pointer;
	font-size: 0.95rem;
	font-weight: 500;
	transition: background-color 0.2s;
}

.primary-button:hover {
	background: var(--color-primary-hover);
}

.filters-section {
	display: flex;
	flex-wrap: wrap;
	gap: 1rem;
	margin-bottom: 1.5rem;
	padding: 1rem;
	background: var(--color-background-dark);
	border-radius: 8px;
}

.filter-group {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.filter-group label {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.filter-select {
	padding: 0.5rem 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.9rem;
	min-width: 150px;
}

.search-group {
	flex: 1;
	min-width: 200px;
}

.search-input-wrapper {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.5rem 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
}

.search-icon {
	color: var(--color-text-maxcontrast);
}

.search-input {
	flex: 1;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.9rem;
	outline: none;
}

.stats-bar {
	display: flex;
	gap: 2rem;
	margin-bottom: 1.5rem;
	padding: 1rem;
	background: var(--color-background-dark);
	border-radius: 8px;
}

.stat {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0.25rem;
}

.stat-number {
	font-size: 1.5rem;
	font-weight: bold;
	color: var(--color-main-text);
}

.stat-number.warning {
	color: var(--color-error);
}

.stat-number.success {
	color: var(--color-success);
}

.stat-label {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

@media (max-width: 768px) {
	.filters-section {
		flex-direction: column;
	}

	.filter-group {
		width: 100%;
	}

	.filter-select,
	.search-input-wrapper {
		width: 100%;
	}

	.stats-bar {
		justify-content: space-around;
		gap: 1rem;
	}
}
</style>
