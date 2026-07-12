<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="room-detail" data-testid="room-detail-view">
		<header class="room-header">
			<div class="header-content">
				<router-link to="/" class="back-link" data-testid="back-link">
					<Icon name="ArrowLeft" :size="20" />
					{{ t('chorestrike', 'Back to Dashboard') }}
				</router-link>
				<h1 data-testid="room-name">
					{{ room?.name || t('chorestrike', 'Room') }}
				</h1>
				<p v-if="floor" class="floor-info">
					<Icon name="Building" :size="16" class="icon" />
					{{ floor.name }}
				</p>
			</div>
			<div class="room-stats">
				<div class="stat-item" data-testid="cleanliness-stat">
					<span class="stat-label">{{ t('chorestrike', 'Cleanliness') }}</span>
					<span class="stat-value" :class="cleanlinessClass" data-testid="cleanliness-value">
						{{ cleanliness }}%
					</span>
				</div>
				<div class="stat-item" data-testid="tasks-count-stat">
					<span class="stat-label">{{ t('chorestrike', 'Tasks') }}</span>
					<span class="stat-value" data-testid="tasks-count">{{ tasks.length }}</span>
				</div>
				<div class="stat-item" data-testid="overdue-stat">
					<span class="stat-label">{{ t('chorestrike', 'Overdue') }}</span>
					<span class="stat-value" :class="{ warning: overdueCount > 0 }">
						{{ overdueCount }}
					</span>
				</div>
			</div>
		</header>

		<main class="room-content">
			<div class="room-overview" data-testid="room-overview">
				<div class="cleanliness-ring" :class="cleanlinessClass">
					<span class="cleanliness-ring-value">{{ cleanliness }}%</span>
					<span class="cleanliness-ring-label">{{ t('chorestrike', 'Cleanliness') }}</span>
				</div>
			</div>

			<div class="tasks-section">
				<div class="section-header">
					<h2>{{ t('chorestrike', 'Tasks') }}</h2>
					<button class="primary-button" data-testid="add-task-button" @click="showAddTaskModal = true">
						<Icon name="Plus" :size="16" class="icon" />
						{{ t('chorestrike', 'Add Task') }}
					</button>
				</div>

				<TaskList
					:room-tasks="tasks"
					:loading="loading"
					@complete="handleCompleteTask"
					@edit="handleEditTask"
					@delete="handleDeleteTask" />
			</div>
		</main>

		<Modal v-if="showAddTaskModal" @close="showAddTaskModal = false">
			<TaskForm
				:room-id="roomId"
				@submit="handleAddTask"
				@cancel="showAddTaskModal = false" />
		</Modal>

		<Modal v-if="showEditTaskModal" @close="showEditTaskModal = false">
			<TaskForm
				:room-id="roomId"
				:task="editingTask"
				@submit="handleUpdateTask"
				@cancel="showEditTaskModal = false" />
		</Modal>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { useRoomsStore } from '../store/rooms.js'
import { useRoomTasksStore } from '../store/roomTasks.js'
import { useTasksStore } from '../store/tasks.js'
import { useUserStore } from '../store/user.js'
import { logger } from '../utils/logger.js'
import Icon from '../components/Common/Icon.vue'
import TaskList from '../components/Tasks/TaskList.vue'
import TaskForm from '../components/Tasks/TaskForm.vue'
import Modal from '../components/Common/Modal.vue'

export default {
	name: 'RoomDetail',

	components: {
		Icon,
		TaskList,
		TaskForm,
		Modal,
	},

	props: {
		id: {
			type: String,
			required: true,
		},
	},

	setup() {
		const roomsStore = useRoomsStore()
		const roomTasksStore = useRoomTasksStore()
		const tasksStore = useTasksStore()
		const userStore = useUserStore()
		return { roomsStore, roomTasksStore, tasksStore, userStore }
	},

	data() {
		return {
			loading: false,
			showAddTaskModal: false,
			showEditTaskModal: false,
			editingTask: null,
		}
	},

	computed: {
		roomId() {
			return parseInt(this.id, 10)
		},

		room() {
			return this.roomsStore.getRoomById(this.roomId)
		},

		floor() {
			if (!this.room) return null
			return this.roomsStore.getFloorById(this.room.floorId)
		},

		cleanliness() {
			return this.room?.cleanliness || 100
		},

		cleanlinessClass() {
			if (this.cleanliness >= 80) return 'excellent'
			if (this.cleanliness >= 60) return 'good'
			if (this.cleanliness >= 40) return 'fair'
			return 'poor'
		},

		tasks() {
			const roomTasks = this.roomTasksStore.roomTasksByRoom[this.roomId] || []
			return roomTasks.map(roomTask => ({
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

		overdueCount() {
			return this.tasks.filter(item => {
				if (!item.roomTask.isActive || !item.roomTask.nextDue) return false
				return new Date(item.roomTask.nextDue) < new Date()
			}).length
		},
	},

	async mounted() {
		await this.initializeRoom()
	},

	methods: {
		t,
		async initializeRoom() {
			this.loading = true
			try {
				await this.roomsStore.fetchFloorsWithRooms()
				await this.tasksStore.fetchTasks()
				await this.roomTasksStore.fetchRoomTasks(this.roomId)
			} catch (error) {
				logger.error('Failed to initialize room', error, { view: 'RoomDetail', action: 'initializeRoom', roomId: this.roomId })
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
					logger.error('Failed to complete task', error, { view: 'RoomDetail', action: 'handleCompleteTask', roomTaskId })
				})
		},

		handleEditTask(roomTask) {
			this.editingTask = roomTask
			this.showEditTaskModal = true
		},

		handleDeleteTask(roomTaskId) {
			if (!confirm(t('chorestrike', 'Are you sure you want to delete this task?'))) {
				return
			}
			this.roomTasksStore.deleteRoomTask(roomTaskId)
				.catch(error => {
					logger.error('Failed to delete task', error, { view: 'RoomDetail', action: 'handleDeleteTask', roomTaskId })
				})
		},

		handleAddTask(taskData) {
			const newTask = {
				...taskData,
				roomId: this.roomId,
			}
			this.roomTasksStore.createRoomTask(newTask)
				.then(() => {
					this.showAddTaskModal = false
				})
				.catch(error => {
					logger.error('Failed to create task', error, { view: 'RoomDetail', action: 'handleAddTask', roomId: this.roomId })
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
					logger.error('Failed to update task', error, { view: 'RoomDetail', action: 'handleUpdateTask', roomTaskId: this.editingTask?.id })
				})
		},
	},
}
</script>

<style scoped>
.room-detail {
	padding: 1rem 2rem 2rem;
	max-width: 1200px;
	margin: 0 auto;
}

.room-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 2rem;
	gap: 2rem;
	flex-wrap: wrap;
}

.header-content {
	flex: 1;
}

.back-link {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	color: var(--color-primary);
	text-decoration: none;
	font-size: 0.9rem;
	margin-bottom: 1rem;
	transition: opacity 0.2s;
}

.back-link:hover {
	opacity: 0.8;
}

h1 {
	font-size: 2rem;
	font-weight: bold;
	margin: 0 0 0.5rem 0;
}

.floor-info {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	color: var(--color-text-maxcontrast);
	margin: 0;
	font-size: 0.95rem;
}

.floor-info .icon {
	color: var(--color-text-maxcontrast);
}

.room-stats {
	display: flex;
	gap: 1.5rem;
}

.stat-item {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0.25rem;
	padding: 0.75rem 1rem;
	background: var(--color-background-dark);
	border-radius: 8px;
	min-width: 80px;
}

.stat-label {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.stat-value {
	font-size: 1.5rem;
	font-weight: bold;
	color: var(--color-main-text);
}

.stat-value.excellent {
	color: var(--color-success);
}

.stat-value.good {
	color: #4caf50;
}

.stat-value.fair {
	color: #ff9800;
}

.stat-value.poor {
	color: var(--color-error);
}

.stat-value.warning {
	color: var(--color-error);
}

.room-content {
	display: grid;
	grid-template-columns: 1fr 2fr;
	gap: 2rem;
}

.room-overview {
	background: var(--color-background-dark);
	border-radius: 12px;
	padding: 2rem;
	display: flex;
	justify-content: center;
	align-items: center;
	min-height: 300px;
}

.cleanliness-ring {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	width: 180px;
	height: 180px;
	border-radius: 50%;
	border: 8px solid var(--color-border);
	gap: 0.25rem;
}

.cleanliness-ring.excellent {
	border-color: var(--color-success);
}

.cleanliness-ring.good {
	border-color: #4caf50;
}

.cleanliness-ring.fair {
	border-color: #ff9800;
}

.cleanliness-ring.poor {
	border-color: var(--color-error);
}

.cleanliness-ring-value {
	font-size: 2.2rem;
	font-weight: bold;
	color: var(--color-main-text);
}

.cleanliness-ring-label {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.tasks-section {
	background: var(--color-background-dark);
	border-radius: 12px;
	padding: 1.5rem;
}

.section-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 1.5rem;
}

.section-header h2 {
	margin: 0;
	font-size: 1.3rem;
}

.primary-button {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.5rem 1rem;
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.9rem;
	font-weight: 500;
	transition: background-color 0.2s;
}

.primary-button:hover {
	background: var(--color-primary-hover);
}

@media (max-width: 768px) {
	.room-content {
		grid-template-columns: 1fr;
	}

	.room-header {
		flex-direction: column;
	}

	.room-stats {
		width: 100%;
		justify-content: space-around;
	}
}
</style>
