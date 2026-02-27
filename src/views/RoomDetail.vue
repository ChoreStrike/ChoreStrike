<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="room-detail">
		<header class="room-header">
			<div class="header-content">
				<router-link to="/" class="back-link">
					<Icon name="ArrowLeft" :size="20" />
					{{ t('chorestrike', 'Back to Dashboard') }}
				</router-link>
				<h1>{{ room?.name || t('chorestrike', 'Room') }}</h1>
				<p v-if="floor" class="floor-info">
					<Icon name="Building" :size="16" class="icon" />
					{{ floor.name }}
				</p>
			</div>
			<div class="room-stats">
				<div class="stat-item">
					<span class="stat-label">{{ t('chorestrike', 'Cleanliness') }}</span>
					<span class="stat-value" :class="cleanlinessClass">
						{{ cleanliness }}%
					</span>
				</div>
				<div class="stat-item">
					<span class="stat-label">{{ t('chorestrike', 'Tasks') }}</span>
					<span class="stat-value">{{ tasks.length }}</span>
				</div>
				<div class="stat-item">
					<span class="stat-label">{{ t('chorestrike', 'Overdue') }}</span>
					<span class="stat-value" :class="{ warning: overdueCount > 0 }">
						{{ overdueCount }}
					</span>
				</div>
			</div>
		</header>

		<main class="room-content">
			<div class="room-overview">
				<MonsterSprite
					:monster-type="monsterType"
					:count="monsterCount"
					:size="'large'" />
			</div>

			<div class="tasks-section">
				<div class="section-header">
					<h2>{{ t('chorestrike', 'Tasks') }}</h2>
					<button class="primary-button" @click="showAddTaskModal = true">
						<Icon name="Plus" :size="16" class="icon" />
						{{ t('chorestrike', 'Add Task') }}
					</button>
				</div>

				<TaskList
					:tasks="tasks"
					:loading="loading"
					@complete="handleCompleteTask"
					@edit="handleEditTask"
					@delete="handleDeleteTask" />
			</div>
		</main>

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
import { useRoomsStore } from '../store/rooms.js'
import { useRoomTasksStore } from '../store/roomTasks.js'
import { useUserStore } from '../store/user.js'
import Icon from '../components/Common/Icon.vue'
import MonsterSprite from '../components/Common/MonsterSprite.vue'
import TaskList from '../components/Tasks/TaskList.vue'
import TaskForm from '../components/Tasks/TaskForm.vue'
import Modal from '../components/Common/Modal.vue'

export default {
	name: 'RoomDetail',

	components: {
		Icon,
		MonsterSprite,
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
		const userStore = useUserStore()
		return { roomsStore, roomTasksStore, userStore }
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

		monsterCount() {
			return this.room?.monsterCount || 0
		},

		monsterType() {
			if (this.cleanliness >= 80) return 'none'
			if (this.cleanliness >= 60) return 'dust'
			if (this.cleanliness >= 40) return 'slime'
			return 'spider'
		},

		tasks() {
			return this.roomTasksStore.roomTasksByRoom[this.roomId] || []
		},

		overdueCount() {
			return this.tasks.filter(task => {
				if (!task.isActive || !task.nextDue) return false
				return new Date(task.nextDue) < new Date()
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
				await this.roomTasksStore.fetchRoomTasks()
			} catch (error) {
				console.error('Failed to initialize room:', error)
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
			const newTask = {
				...taskData,
				roomId: this.roomId,
			}
			this.roomTasksStore.createRoomTask(newTask)
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
