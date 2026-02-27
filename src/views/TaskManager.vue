<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="task-manager">
		<header class="manager-header">
			<h1>{{ t('chorestrike', 'Task Manager') }}</h1>
			<button class="primary-button" @click="showAddTaskModal = true">
				<Icon name="Plus" :size="16" class="icon" />
				{{ t('chorestrike', 'New Task') }}
			</button>
		</header>

		<div class="filters-section">
			<div class="filter-group">
				<label>{{ t('chorestrike', 'Status') }}</label>
				<select v-model="filterStatus" class="filter-select">
					<option value="all">{{ t('chorestrike', 'All Tasks') }}</option>
					<option value="active">{{ t('chorestrike', 'Active') }}</option>
					<option value="overdue">{{ t('chorestrike', 'Overdue') }}</option>
					<option value="due-today">{{ t('chorestrike', 'Due Today') }}</option>
					<option value="completed">{{ t('chorestrike', 'Completed') }}</option>
				</select>
			</div>

			<div class="filter-group">
				<label>{{ t('chorestrike', 'Priority') }}</label>
				<select v-model="filterPriority" class="filter-select">
					<option value="all">{{ t('chorestrike', 'All Priorities') }}</option>
					<option value="urgent">{{ t('chorestrike', 'Urgent') }}</option>
					<option value="normal">{{ t('chorestrike', 'Normal') }}</option>
					<option value="low">{{ t('chorestrike', 'Can Wait') }}</option>
				</select>
			</div>

			<div class="filter-group">
				<label>{{ t('chorestrike', 'Difficulty') }}</label>
				<select v-model="filterDifficulty" class="filter-select">
					<option value="all">{{ t('chorestrike', 'All Difficulties') }}</option>
					<option value="1">★ (Quick)</option>
					<option value="2">★★ (Easy)</option>
					<option value="3">★★★ (Medium)</option>
					<option value="4">★★★★ (Hard)</option>
					<option value="5">★★★★★ (Major)</option>
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
						:placeholder="t('chorestrike', 'Search tasks...')" />
				</div>
			</div>
		</div>

		<div class="stats-bar">
			<div class="stat">
				<span class="stat-number">{{ totalTasks }}</span>
				<span class="stat-label">{{ t('chorestrike', 'Total') }}</span>
			</div>
			<div class="stat">
				<span class="stat-number warning">{{ overdueTasks }}</span>
				<span class="stat-label">{{ t('chorestrike', 'Overdue') }}</span>
			</div>
			<div class="stat">
				<span class="stat-number success">{{ dueTodayTasks }}</span>
				<span class="stat-label">{{ t('chorestrike', 'Due Today') }}</span>
			</div>
			<div class="stat">
				<span class="stat-number">{{ filteredTasks.length }}</span>
				<span class="stat-label">{{ t('chorestrike', 'Shown') }}</span>
			</div>
		</div>

		<TaskList
			:tasks="filteredTasks"
			:loading="loading"
			:show-room="true"
			@complete="handleCompleteTask"
			@edit="handleEditTask"
			@delete="handleDeleteTask" />

		<Modal v-if="showAddTaskModal" @close="showAddTaskModal = false">
			<TaskForm
				@submit="handleAddTask"
				@cancel="showAddTaskModal = false" />
		</Modal>

		<Modal v-if="showEditTaskModal" @close="showEditTaskModal = false">
			<TaskForm
				:task="editingTask"
				@submit="handleUpdateTask"
				@cancel="showEditTaskModal = false" />
		</Modal>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { useRoomTasksStore } from '../store/roomTasks.js'
import { useRoomsStore } from '../store/rooms.js'
import Icon from '../components/Common/Icon.vue'
import TaskList from '../components/Tasks/TaskList.vue'
import TaskForm from '../components/Tasks/TaskForm.vue'
import { useUserStore } from '../store/user.js'
import Modal from '../components/Common/Modal.vue'

export default {
	name: 'TaskManager',

	components: {
		Icon,
		TaskList,
		TaskForm,
		Modal,
	},

	setup() {
		const roomTasksStore = useRoomTasksStore()
		const roomsStore = useRoomsStore()
		const userStore = useUserStore()
		return { roomTasksStore, roomsStore, userStore }
	},

	data() {
		return {
			loading: false,
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
			return this.roomTasksStore.roomTasks
		},

		filteredTasks() {
			let tasks = this.allTasks

			// Status filter
			if (this.filterStatus === 'active') {
				tasks = tasks.filter(t => t.isActive)
			} else if (this.filterStatus === 'overdue') {
				tasks = tasks.filter(t => {
					if (!t.isActive || !t.nextDue) return false
					return new Date(t.nextDue) < new Date()
				})
			} else if (this.filterStatus === 'due-today') {
				const today = new Date()
				today.setHours(0, 0, 0, 0)
				const tomorrow = new Date(today)
				tomorrow.setDate(tomorrow.getDate() + 1)
				tasks = tasks.filter(t => {
					if (!t.isActive || !t.nextDue) return false
					const dueDate = new Date(t.nextDue)
					return dueDate >= today && dueDate < tomorrow
				})
			} else if (this.filterStatus === 'completed') {
				tasks = tasks.filter(t => !t.isActive)
			}

			// Priority filter
			if (this.filterPriority !== 'all') {
				tasks = tasks.filter(t => t.priority === this.filterPriority)
			}

			// Difficulty filter
			if (this.filterDifficulty !== 'all') {
				tasks = tasks.filter(t => t.difficulty === parseInt(this.filterDifficulty, 10))
			}

			// Search filter
			if (this.searchQuery.trim()) {
				const query = this.searchQuery.toLowerCase()
				tasks = tasks.filter(t => t.name?.toLowerCase().includes(query))
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
				await this.roomTasksStore.fetchRoomTasks()
				await this.roomsStore.fetchFloorsWithRooms()
			} catch (error) {
				console.error('Failed to initialize tasks:', error)
			} finally {
				this.loading = false
			}
		},

		handleCompleteTask(task) {
			this.roomTasksStore.completeRoomTask(task.id, this.userStore.userId)
				.then(() => {
					this.$emit('task-completed', task)
				})
				.catch(error => {
					console.error('Failed to complete task:', error)
				})
		},

		handleEditTask(task) {
			this.editingTask = task
			this.showEditTaskModal = true
		},

		handleDeleteTask(task) {
			if (!confirm(t('chorestrike', 'Are you sure you want to delete this task?'))) {
				return
			}
			this.roomTasksStore.deleteRoomTask(task.id)
				.catch(error => {
					console.error('Failed to delete task:', error)
				})
		},

		handleAddTask(taskData) {
			this.roomTasksStore.createRoomTask(taskData)
				.then(() => {
					this.showAddTaskModal = false
				})
				.catch(error => {
					console.error('Failed to create task:', error)
				})
		},

		handleUpdateTask(taskData) {
			if (!this.editingTask) return
			this.roomTasksStore.updateRoomTaskDueDate(this.editingTask.id, taskData.nextDue)
				.then(() => {
					this.showEditTaskModal = false
					this.editingTask = null
				})
				.catch(error => {
					console.error('Failed to update task:', error)
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
