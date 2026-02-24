<template>
	<div class="task-manager">
		<header class="page-header">
			<h1>{{ t('chorewars', 'Task Manager') }}</h1>
			<p>{{ t('chorewars', 'Manage room tasks across your house.') }}</p>
		</header>

		<div class="toolbar">
			<label for="room-select">{{ t('chorewars', 'Room') }}</label>
			<select id="room-select"
				v-model.number="selectedRoomId"
				class="select-input"
				:disabled="loading || rooms.length === 0">
				<option v-for="room in rooms" :key="room.id" :value="room.id">
					{{ room.name }}
				</option>
			</select>
			<button class="primary-button" :disabled="!selectedRoomId" @click="showAddTaskModal = true">
				{{ t('chorewars', 'Add Task') }}
			</button>
		</div>

		<p v-if="error" class="error-message">
			{{ error }}
		</p>

		<TaskList
			:room-tasks="currentRoomTasks"
			:loading="loading"
			@complete="completeTask"
			@edit="handleEdit"
			@delete="deleteTask" />

		<Modal v-if="showAddTaskModal" @close="showAddTaskModal = false">
			<div class="modal-content">
				<h3>{{ t('chorewars', 'Add Task to Room') }}</h3>
				<TaskForm
					:room-id="selectedRoomId"
					@submit="addTask"
					@cancel="showAddTaskModal = false" />
			</div>
		</Modal>
	</div>
</template>

<script>
import { computed, onMounted, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useRoomsStore } from '../store/rooms.js'
import { useTasksStore } from '../store/tasks.js'
import { useRoomTasksStore } from '../store/roomTasks.js'
import { useUserStore } from '../store/user.js'
import TaskList from '../components/Tasks/TaskList.vue'
import TaskForm from '../components/Tasks/TaskForm.vue'
import Modal from '../components/Common/Modal.vue'

export default {
	name: 'TaskManager',
	components: {
		TaskList,
		TaskForm,
		Modal,
	},
	setup() {
		const roomsStore = useRoomsStore()
		const tasksStore = useTasksStore()
		const roomTasksStore = useRoomTasksStore()
		const userStore = useUserStore()

		const loading = ref(false)
		const error = ref('')
		const selectedRoomId = ref(null)
		const showAddTaskModal = ref(false)

		const rooms = computed(() => roomsStore.rooms)

		const currentRoomTasks = computed(() => {
			if (!selectedRoomId.value) {
				return []
			}
			return roomTasksStore.roomTasks
				.filter(item => item.roomId === selectedRoomId.value)
				.map(roomTask => ({
					roomTask,
					task: tasksStore.getTaskById(roomTask.taskId),
				}))
				.filter(item => item.task)
		})

		const loadRoomTasks = async () => {
			if (!selectedRoomId.value) {
				return
			}
			loading.value = true
			error.value = ''
			try {
				await Promise.all([
					tasksStore.fetchTasks(),
					roomTasksStore.fetchRoomTasks(selectedRoomId.value, false),
				])
			} catch (loadError) {
				error.value = loadError.message
			} finally {
				loading.value = false
			}
		}

		const initialize = async () => {
			loading.value = true
			error.value = ''
			try {
				await roomsStore.fetchFloorsWithRooms()
				if (roomsStore.rooms.length > 0) {
					selectedRoomId.value = roomsStore.rooms[0].id
				}
			} catch (initError) {
				error.value = initError.message
			} finally {
				loading.value = false
			}
		}

		const addTask = async (taskData) => {
			error.value = ''
			try {
				await roomTasksStore.createRoomTask({
					...taskData,
					roomId: selectedRoomId.value,
				})
				showAddTaskModal.value = false
				await loadRoomTasks()
			} catch (addError) {
				error.value = addError.message
			}
		}

		const completeTask = async (roomTaskId) => {
			error.value = ''
			try {
				await roomTasksStore.completeRoomTask(roomTaskId, userStore.userId || 'current-user')
				await roomsStore.fetchFloorsWithRooms()
				await loadRoomTasks()
			} catch (completeError) {
				error.value = completeError.message
			}
		}

		const deleteTask = async (roomTaskId) => {
			if (!confirm(t('chorewars', 'Remove this task from the room?'))) {
				return
			}
			error.value = ''
			try {
				await roomTasksStore.deleteRoomTask(roomTaskId)
				await loadRoomTasks()
			} catch (deleteError) {
				error.value = deleteError.message
			}
		}

		const handleEdit = () => {
			// Reserved for upcoming edit flow
		}

		watch(selectedRoomId, loadRoomTasks)
		onMounted(initialize)

		return {
			loading,
			error,
			rooms,
			selectedRoomId,
			showAddTaskModal,
			currentRoomTasks,
			addTask,
			completeTask,
			deleteTask,
			handleEdit,
			t,
		}
	},
}
</script>

<style scoped>
.task-manager {
	padding: 2rem;
	max-width: 960px;
	margin: 0 auto;
}

.page-header {
	margin-bottom: 1rem;
}

.page-header h1 {
	margin: 0 0 0.5rem;
}

.page-header p {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.toolbar {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	margin-bottom: 1rem;
	flex-wrap: wrap;
}

.select-input {
	padding: 0.55rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
}

.primary-button {
	border: none;
	border-radius: 6px;
	padding: 0.55rem 0.9rem;
	background: var(--color-primary);
	color: #fff;
	cursor: pointer;
}

.primary-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.error-message {
	margin: 0 0 0.8rem;
	padding: 0.6rem 0.75rem;
	border-radius: 6px;
	background: #fdeeee;
	color: #b03a2e;
}

.modal-content {
	padding: 1rem;
}

.modal-content h3 {
	margin: 0 0 0.5rem;
}
</style>
