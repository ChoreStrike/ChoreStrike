<template>
	<div class="room-detail">
		<!-- Loading State -->
		<div v-if="loading" class="loading-state">
			<div class="loading-spinner" />
			<p>{{ t('chorestrike', 'Loading room details...') }}</p>
		</div>

		<!-- Error State -->
		<div v-else-if="error" class="error-state">
			<div class="error-icon">
				⚠️
			</div>
			<h3>{{ t('chorestrike', 'Failed to load room') }}</h3>
			<p>{{ error }}</p>
			<button class="retry-button" @click="loadRoomData">
				{{ t('chorestrike', 'Retry') }}
			</button>
		</div>

		<!-- Room Content -->
		<div v-else-if="room" class="room-content">
			<!-- Breadcrumb & Header -->
			<div class="room-header">
				<nav class="breadcrumb">
					<router-link to="/" class="breadcrumb-link">
						{{ t('chorestrike', 'Dashboard') }}
					</router-link>
					<span class="breadcrumb-separator">/</span>
					<span class="breadcrumb-current">{{ room.name }}</span>
				</nav>

				<div class="header-main">
					<div class="header-info">
						<h1>{{ room.name }}</h1>
						<span class="floor-badge">{{ floorName }}</span>
					</div>
					<div class="header-actions">
						<button class="action-button" @click="showEditModal = true">
							<span class="icon">✏️</span>
							{{ t('chorestrike', 'Edit') }}
						</button>
					</div>
				</div>
			</div>

			<!-- Room Status Card -->
			<div class="status-card" :class="statusClass">
				<div class="status-header">
					<h2>{{ t('chorestrike', 'Room Status') }}</h2>
					<span class="status-badge">{{ statusLabel }}</span>
				</div>

				<div class="status-grid">
					<div class="status-item">
						<div class="status-label">
							{{ t('chorestrike', 'Cleanliness') }}
						</div>
						<div class="cleanliness-bar">
							<div
								class="cleanliness-fill"
								:style="{ width: `${room.cleanliness}%` }"
								:class="cleanlinessClass" />
						</div>
						<div class="cleanliness-value">
							{{ room.cleanliness }}%
						</div>
					</div>

					<div class="status-item">
						<div class="status-label">
							{{ t('chorestrike', 'Activity Level') }}
						</div>
						<div class="monster-indicator">
							<span v-for="n in room.monsterCount" :key="n" class="monster-icon">👾</span>
							<span v-if="room.monsterCount === 0" class="no-monsters">{{ t('chorestrike', 'Peaceful') }}</span>
						</div>
					</div>

					<div class="status-item">
						<div class="status-label">
							{{ t('chorestrike', 'Last Cleaned') }}
						</div>
						<div class="last-cleaned">
							{{ lastCleanedText }}
						</div>
					</div>
				</div>
			</div>

			<!-- Tasks Section -->
			<div class="tasks-section">
				<div class="section-header">
					<h2>{{ t('chorestrike', 'Tasks') }}</h2>
					<button class="primary-button" @click="showAddTaskModal = true">
						<span class="icon">+</span>
						{{ t('chorestrike', 'Add Task') }}
					</button>
				</div>

				<!-- Task Stats -->
				<div class="task-stats">
					<div class="stat-card">
						<div class="stat-value">
							{{ activeTasksCount }}
						</div>
						<div class="stat-label">
							{{ t('chorestrike', 'Active') }}
						</div>
					</div>
					<div class="stat-card overdue">
						<div class="stat-value">
							{{ overdueTasksCount }}
						</div>
						<div class="stat-label">
							{{ t('chorestrike', 'Overdue') }}
						</div>
					</div>
					<div class="stat-card due-today">
						<div class="stat-value">
							{{ dueTodayTasksCount }}
						</div>
						<div class="stat-label">
							{{ t('chorestrike', 'Due Today') }}
						</div>
					</div>
				</div>

				<!-- Task List -->
				<TaskList
					:room-tasks="roomTasksWithDetails"
					:loading="tasksLoading"
					@complete="handleTaskComplete"
					@edit="handleTaskEdit"
					@delete="handleTaskDelete" />
			</div>
		</div>

		<!-- Edit Room Modal -->
		<Modal v-if="showEditModal" @close="showEditModal = false">
			<div class="modal-content">
				<h3>{{ t('chorestrike', 'Edit Room') }}</h3>
				<div class="form-group">
					<label for="roomName">{{ t('chorestrike', 'Room Name') }}</label>
					<input
						id="roomName"
						v-model="editRoomName"
						type="text"
						class="form-input"
						@keyup.enter="saveRoomName">
				</div>
				<div class="modal-actions">
					<button class="secondary-button" @click="showEditModal = false">
						{{ t('chorestrike', 'Cancel') }}
					</button>
					<button class="primary-button" :disabled="!editRoomName.trim()" @click="saveRoomName">
						{{ t('chorestrike', 'Save') }}
					</button>
				</div>
			</div>
		</Modal>

		<!-- Add Task Modal -->
		<Modal v-if="showAddTaskModal" @close="showAddTaskModal = false">
			<div class="modal-content">
				<h3>{{ t('chorestrike', 'Add Task to Room') }}</h3>
				<TaskForm
					:room-id="roomId"
					@submit="handleTaskSubmit"
					@cancel="showAddTaskModal = false" />
			</div>
		</Modal>

		<!-- Edit Task Modal -->
		<Modal v-if="showEditTaskModal" @close="closeEditTaskModal">
			<div class="modal-content">
				<h3>{{ t('chorestrike', 'Edit Task') }}</h3>
				<TaskForm
					:room-id="roomId"
					:initial-data="editingTask"
					@submit="handleSaveTaskEdit"
					@cancel="closeEditTaskModal" />
			</div>
		</Modal>
	</div>
</template>

<script>
import { ref, computed, onMounted, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useRoomsStore } from '../store/rooms.js'
import { useTasksStore } from '../store/tasks.js'
import { useRoomTasksStore } from '../store/roomTasks.js'
import { useUserStore } from '../store/user.js'
import TaskList from '../components/Tasks/TaskList.vue'
import TaskForm from '../components/Tasks/TaskForm.vue'
import Modal from '../components/Common/Modal.vue'

export default {
	name: 'RoomDetail',

	components: {
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

	setup(props) {
		// Stores
		const roomsStore = useRoomsStore()
		const tasksStore = useTasksStore()
		const roomTasksStore = useRoomTasksStore()
		const userStore = useUserStore()

		// Local state
		const loading = ref(false)
		const tasksLoading = ref(false)
		const error = ref(null)
		const showEditModal = ref(false)
		const showAddTaskModal = ref(false)
		const showEditTaskModal = ref(false)
		const editingTask = ref(null)
		const editRoomName = ref('')

		// Computed
		const roomId = computed(() => parseInt(props.id))

		const room = computed(() => {
			return roomsStore.getRoomById(roomId.value)
		})

		const floor = computed(() => {
			if (!room.value) return null
			return roomsStore.getFloorById(room.value.floorId)
		})

		const floorName = computed(() => {
			return floor.value?.name || t('chorestrike', 'Unknown Floor')
		})

		const statusClass = computed(() => {
			if (!room.value) return ''
			const cleanliness = room.value.cleanliness
			if (cleanliness >= 80) return 'excellent'
			if (cleanliness >= 60) return 'good'
			if (cleanliness >= 40) return 'fair'
			if (cleanliness >= 20) return 'poor'
			return 'terrible'
		})

		const statusLabel = computed(() => {
			if (!room.value) return ''
			const cleanliness = room.value.cleanliness
			if (cleanliness >= 80) return t('chorestrike', 'Excellent')
			if (cleanliness >= 60) return t('chorestrike', 'Good')
			if (cleanliness >= 40) return t('chorestrike', 'Fair')
			if (cleanliness >= 20) return t('chorestrike', 'Poor')
			return t('chorestrike', 'Needs Attention')
		})

		const cleanlinessClass = computed(() => {
			if (!room.value) return ''
			const cleanliness = room.value.cleanliness
			if (cleanliness >= 60) return 'good'
			if (cleanliness >= 40) return 'medium'
			return 'poor'
		})

		const lastCleanedText = computed(() => {
			if (!room.value?.lastCleaned) return t('chorestrike', 'Never')

			const lastCleaned = new Date(room.value.lastCleaned)
			const now = new Date()
			const diffDays = Math.floor((now - lastCleaned) / (1000 * 60 * 60 * 24))

			if (diffDays === 0) return t('chorestrike', 'Today')
			if (diffDays === 1) return t('chorestrike', 'Yesterday')
			return t('chorestrike', '{days} days ago', { days: diffDays })
		})

		const roomTasksWithDetails = computed(() => {
			const roomTaskIds = roomTasksStore.roomTasks
				.filter(rt => rt.roomId === roomId.value)
				.map(rt => rt.id)

			return roomTaskIds.map(id => {
				const roomTask = roomTasksStore.getRoomTaskById(id)
				const task = tasksStore.getTaskById(roomTask?.taskId)
				return {
					roomTask,
					task,
				}
			}).filter(item => item.roomTask && item.task)
		})

		const activeTasksCount = computed(() => {
			return roomTasksWithDetails.value.filter(item => item.roomTask.isActive).length
		})

		const overdueTasksCount = computed(() => {
			const now = new Date()
			return roomTasksWithDetails.value.filter(item =>
				item.roomTask.isActive && new Date(item.roomTask.nextDue) < now,
			).length
		})

		const dueTodayTasksCount = computed(() => {
			const today = new Date()
			today.setHours(0, 0, 0, 0)
			const tomorrow = new Date(today)
			tomorrow.setDate(tomorrow.getDate() + 1)

			return roomTasksWithDetails.value.filter(item => {
				if (!item.roomTask.isActive) return false
				const dueDate = new Date(item.roomTask.nextDue)
				return dueDate >= today && dueDate < tomorrow
			}).length
		})

		// Methods
		const loadRoomData = async () => {
			loading.value = true
			error.value = null

			try {
				// Load room details from API if not in store
				if (!room.value) {
					await roomsStore.fetchFloorsWithRooms()
				}

				// Set current room
				if (room.value) {
					roomsStore.setCurrentRoom(roomId.value)
					editRoomName.value = room.value.name
				} else {
					error.value = t('chorestrike', 'Room not found')
				}
			} catch (err) {
				error.value = err.message || t('chorestrike', 'Failed to load room')
				console.error('Failed to load room:', err)
			} finally {
				loading.value = false
			}
		}

		const loadTasks = async () => {
			tasksLoading.value = true
			try {
				await Promise.all([
					tasksStore.fetchTasks(),
					roomTasksStore.fetchRoomTasks(roomId.value, false),
				])
			} catch (err) {
				console.error('Failed to load tasks:', err)
			} finally {
				tasksLoading.value = false
			}
		}

		const saveRoomName = async () => {
			if (!editRoomName.value.trim()) return

			try {
				await roomsStore.updateRoomName(roomId.value, editRoomName.value.trim())
				showEditModal.value = false
			} catch (err) {
				console.error('Failed to update room name:', err)
			}
		}

		const handleTaskSubmit = async (taskData) => {
			try {
				await roomTasksStore.createRoomTask({
					...taskData,
					roomId: roomId.value,
				})
				showAddTaskModal.value = false
				await loadTasks()
			} catch (err) {
				console.error('Failed to add task:', err)
			}
		}

		const handleTaskComplete = async (roomTaskId) => {
			try {
				const currentUserId = userStore.userId || 'current-user'
				await roomTasksStore.completeRoomTask({
					roomTaskId,
					userId: currentUserId,
				})

				// Refresh room data to get updated cleanliness
				await roomsStore.fetchFloorsWithRooms()
			} catch (err) {
				console.error('Failed to complete task:', err)
			}
		}

		const handleTaskEdit = (roomTask) => {
			editingTask.value = roomTask
			showEditTaskModal.value = true
		}

		const closeEditTaskModal = () => {
			showEditTaskModal.value = false
			editingTask.value = null
		}

		const handleSaveTaskEdit = async (updatedData) => {
			if (!editingTask.value) return

			try {
				await roomTasksStore.updateRoomTask(editingTask.value.id, updatedData)
				closeEditTaskModal()
				await loadTasks()
			} catch (err) {
				console.error('Failed to update task:', err)
			}
		}

		const handleTaskDelete = async (roomTaskId) => {
			if (!confirm(t('chorestrike', 'Are you sure you want to remove this task?'))) return

			try {
				await roomTasksStore.deleteRoomTask(roomTaskId)
			} catch (err) {
				console.error('Failed to delete task:', err)
			}
		}

		// Watch for room ID changes
		watch(() => props.id, () => {
			loadRoomData()
			loadTasks()
		})

		// Lifecycle
		onMounted(() => {
			loadRoomData()
			loadTasks()
		})

		return {
			room,
			roomId,
			floorName,
			statusClass,
			statusLabel,
			cleanlinessClass,
			lastCleanedText,
			roomTasksWithDetails,
			activeTasksCount,
			overdueTasksCount,
			dueTodayTasksCount,
			loading,
			tasksLoading,
			error,
			showEditModal,
			showAddTaskModal,
			showEditTaskModal,
			editingTask,
			editRoomName,
			loadRoomData,
			saveRoomName,
			handleTaskSubmit,
			handleTaskComplete,
			handleTaskEdit,
			closeEditTaskModal,
			handleSaveTaskEdit,
			handleTaskDelete,
			t,
		}
	},
}
</script>

<style scoped>
.room-detail {
	padding: 2rem;
	max-width: 1000px;
	margin: 0 auto;
}

/* Loading & Error States */
.loading-state,
.error-state {
	text-align: center;
	padding: 4rem 2rem;
	color: var(--color-text-maxcontrast);
}

.loading-spinner {
	width: 48px;
	height: 48px;
	border: 4px solid var(--color-border);
	border-top: 4px solid var(--color-primary);
	border-radius: 50%;
	animation: spin 1s linear infinite;
	margin: 0 auto 1rem;
}

@keyframes spin {
	0% { transform: rotate(0deg); }
	100% { transform: rotate(360deg); }
}

.error-icon {
	font-size: 48px;
	margin-bottom: 1rem;
}

.retry-button {
	padding: 0.75rem 1.5rem;
	background: var(--color-primary);
	color: white;
	border: none;
	border-radius: 6px;
	cursor: pointer;
	font-size: 14px;
	margin-top: 1rem;
}

.retry-button:hover {
	background: var(--color-primary-hover);
}

/* Header */
.room-header {
	margin-bottom: 2rem;
}

.breadcrumb {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-bottom: 1rem;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.breadcrumb-link {
	color: var(--color-primary);
	text-decoration: none;
}

.breadcrumb-link:hover {
	text-decoration: underline;
}

.breadcrumb-separator {
	color: var(--color-text-maxcontrast);
}

.breadcrumb-current {
	color: var(--color-main-text);
	font-weight: 500;
}

.header-main {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 1rem;
}

.header-info h1 {
	margin: 0 0 0.5rem 0;
	font-size: 2rem;
	color: var(--color-main-text);
}

.floor-badge {
	display: inline-block;
	padding: 0.25rem 0.75rem;
	background: var(--color-background-dark);
	border-radius: 20px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.header-actions {
	display: flex;
	gap: 0.5rem;
}

.action-button {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.5rem 1rem;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: 6px;
	cursor: pointer;
	font-size: 14px;
	transition: all 0.2s ease;
}

.action-button:hover {
	background: var(--color-background-hover);
}

/* Status Card */
.status-card {
	background: var(--color-background-dark);
	border-radius: 12px;
	padding: 1.5rem;
	margin-bottom: 2rem;
	border: 2px solid var(--color-border);
}

.status-card.excellent {
	border-color: #2ecc71;
}

.status-card.good {
	border-color: #27ae60;
}

.status-card.fair {
	border-color: #f39c12;
}

.status-card.poor {
	border-color: #e67e22;
}

.status-card.terrible {
	border-color: #e74c3c;
}

.status-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 1.5rem;
}

.status-header h2 {
	margin: 0;
	font-size: 1.25rem;
	color: var(--color-main-text);
}

.status-badge {
	padding: 0.25rem 0.75rem;
	border-radius: 20px;
	font-size: 12px;
	font-weight: 500;
	text-transform: uppercase;
}

.status-card.excellent .status-badge {
	background: #d5f4e6;
	color: #27ae60;
}

.status-card.good .status-badge {
	background: #d5f4e6;
	color: #27ae60;
}

.status-card.fair .status-badge {
	background: #fef9e7;
	color: #f39c12;
}

.status-card.poor .status-badge {
	background: #fdedec;
	color: #e67e22;
}

.status-card.terrible .status-badge {
	background: #fdedec;
	color: #e74c3c;
}

.status-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 1.5rem;
}

.status-item {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.status-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.cleanliness-bar {
	height: 12px;
	background: var(--color-background-hover);
	border-radius: 6px;
	overflow: hidden;
}

.cleanliness-fill {
	height: 100%;
	border-radius: 6px;
	transition: width 0.3s ease;
}

.cleanliness-fill.good {
	background: linear-gradient(90deg, #2ecc71, #27ae60);
}

.cleanliness-fill.medium {
	background: linear-gradient(90deg, #f39c12, #e67e22);
}

.cleanliness-fill.poor {
	background: linear-gradient(90deg, #e74c3c, #c0392b);
}

.cleanliness-value {
	font-size: 14px;
	font-weight: 600;
	color: var(--color-main-text);
}

.monster-indicator {
	display: flex;
	flex-wrap: wrap;
	gap: 0.25rem;
	font-size: 20px;
}

.no-monsters {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.last-cleaned {
	font-size: 14px;
	color: var(--color-main-text);
	font-weight: 500;
}

/* Tasks Section */
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
	font-size: 1.25rem;
	color: var(--color-main-text);
}

.primary-button {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.75rem 1rem;
	background: var(--color-primary);
	color: white;
	border: none;
	border-radius: 6px;
	cursor: pointer;
	font-size: 14px;
	transition: all 0.2s ease;
}

.primary-button:hover:not(:disabled) {
	background: var(--color-primary-hover);
}

.primary-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.secondary-button {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.75rem 1rem;
	background: var(--color-background-hover);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: 6px;
	cursor: pointer;
	font-size: 14px;
	transition: all 0.2s ease;
}

.secondary-button:hover {
	background: var(--color-background-dark);
}

/* Task Stats */
.task-stats {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 1rem;
	margin-bottom: 1.5rem;
}

.stat-card {
	background: var(--color-background-hover);
	border-radius: 8px;
	padding: 1rem;
	text-align: center;
}

.stat-card.overdue {
	background: #fdedec;
}

.stat-card.due-today {
	background: #fef9e7;
}

.stat-value {
	font-size: 2rem;
	font-weight: 700;
	color: var(--color-main-text);
	margin-bottom: 0.25rem;
}

.stat-card.overdue .stat-value {
	color: #e74c3c;
}

.stat-card.due-today .stat-value {
	color: #f39c12;
}

.stat-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

/* Modal */
.modal-content {
	padding: 1.5rem;
	min-width: 400px;
}

.modal-content h3 {
	margin: 0 0 1.5rem 0;
	font-size: 1.25rem;
	color: var(--color-main-text);
}

.form-group {
	margin-bottom: 1.5rem;
}

.form-group label {
	display: block;
	margin-bottom: 0.5rem;
	font-size: 14px;
	color: var(--color-main-text);
	font-weight: 500;
}

.form-input {
	width: 100%;
	padding: 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	font-size: 14px;
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.form-input:focus {
	outline: none;
	border-color: var(--color-primary);
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.75rem;
	margin-top: 1.5rem;
}

/* Responsive */
@media (max-width: 768px) {
	.room-detail {
		padding: 1rem;
	}

	.header-main {
		flex-direction: column;
		align-items: flex-start;
	}

	.status-grid {
		grid-template-columns: 1fr;
	}

	.task-stats {
		grid-template-columns: 1fr;
	}

	.modal-content {
		min-width: auto;
		width: 90vw;
	}
}
</style>
