<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="room-detail" data-testid="room-detail-view">
		<header class="room-header">
			<div class="header-content">
				<router-link :to="{ name: 'dashboard' }" class="back-link" data-testid="back-link">
					<Icon name="ArrowLeft" :size="20" />
					{{ t('chorestrike', 'Back to Dashboard') }}
				</router-link>
				<div class="room-name-row">
					<h1 data-testid="room-name">
						{{ room?.name || t('chorestrike', 'Room') }}
					</h1>
					<div v-if="userStore.isHouseOwner" class="room-header-actions">
						<button
							class="icon-action-button"
							data-testid="rename-room-button"
							:aria-label="t('chorestrike', 'Rename room')"
							:title="t('chorestrike', 'Rename room')"
							@click="openRenameRoom">
							<Icon name="Edit" :size="16" />
						</button>
						<button
							class="icon-action-button danger"
							data-testid="delete-room-button"
							:aria-label="t('chorestrike', 'Delete room')"
							:title="t('chorestrike', 'Delete room')"
							@click="roomToDelete = true">
							<Icon name="Trash" :size="16" />
						</button>
					</div>
				</div>
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
			<!-- Initialization failed (CS-PLAN-066 §3.5): explicit localized
			     retry state instead of a silently empty room. -->
			<ErrorState
				v-if="initError"
				data-testid="room-detail-error"
				:message="t('chorestrike', 'Could not load this room')"
				@retry="initializeRoom" />

			<template v-else>
				<ErrorState
					v-if="membersError"
					data-testid="member-names-error"
					:message="t('chorestrike', 'Failed to load members')"
					@retry="loadMembers" />
				<div class="room-overview" data-testid="room-overview">
					<div class="cleanliness-ring" :class="cleanlinessClass">
						<span class="cleanliness-ring-value">{{ cleanliness }}%</span>
						<span class="cleanliness-ring-label">{{ t('chorestrike', 'Cleanliness') }}</span>
					</div>
					<div v-if="roomStats" class="room-stats-card" data-testid="room-stats-card">
						<h3>{{ t('chorestrike', 'Task Stats') }}</h3>
						<dl class="stats-grid">
							<div class="stats-entry">
								<dt>{{ t('chorestrike', 'Total') }}</dt>
								<dd data-testid="room-stats-total">
									{{ roomStats.totalTasks }}
								</dd>
							</div>
							<div class="stats-entry">
								<dt>{{ t('chorestrike', 'Active') }}</dt>
								<dd data-testid="room-stats-active">
									{{ roomStats.activeTasks }}
								</dd>
							</div>
							<div class="stats-entry">
								<dt>{{ t('chorestrike', 'Overdue') }}</dt>
								<dd :class="{ warning: roomStats.overdueTasks > 0 }" data-testid="room-stats-overdue">
									{{ roomStats.overdueTasks }}
								</dd>
							</div>
							<div class="stats-entry">
								<dt>{{ t('chorestrike', 'Due today') }}</dt>
								<dd data-testid="room-stats-due-today">
									{{ roomStats.dueTodayTasks }}
								</dd>
							</div>
							<div class="stats-entry">
								<dt>{{ t('chorestrike', 'Completion') }}</dt>
								<dd data-testid="room-stats-completion-rate">
									{{ roomStats.completionRate }}%
								</dd>
							</div>
						</dl>
					</div>
				</div>

				<div class="tasks-section">
					<div class="section-header">
						<h2>{{ t('chorestrike', 'Tasks') }}</h2>
						<button
							v-if="canManageTasks"
							class="primary-button"
							data-testid="add-task-button"
							@click="openAddTask">
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
			</template>
		</main>

		<Modal v-if="showAddTaskModal" :aria-label="t('chorestrike', 'Add Task')" @close="showAddTaskModal = false">
			<TaskTemplatePicker
				v-if="addTaskMode === 'template'"
				:room-id="roomId"
				:room-type="roomType"
				data-testid="add-task-picker"
				@custom="addTaskMode = 'custom'"
				@created="handleTemplateCreated"
				@error="handleTemplateError" />
			<TaskEditForm
				v-else
				:preset-room-id="roomId"
				:loading="creating"
				@submit="handleAddTask"
				@cancel="showAddTaskModal = false" />
		</Modal>

		<Modal v-if="showEditTaskModal" :aria-label="t('chorestrike', 'Edit Task')" @close="closeEditModal">
			<RoomTaskEditModal
				:room-task="editingTask"
				:task="editingTaskDefinition"
				@saved="handleTaskSaved"
				@cancel="closeEditModal" />
		</Modal>

		<ConfirmDialog
			v-if="taskToDelete !== null"
			:title="t('chorestrike', 'Are you sure you want to delete this task?')"
			:loading="deleting"
			@confirm="confirmDeleteTask"
			@cancel="taskToDelete = null" />

		<!-- Rename Room (owner only) -->
		<Modal v-if="showRenameRoomModal" :aria-label="t('chorestrike', 'Rename Room')" @close="closeRenameRoom">
			<div class="modal-content">
				<h3>{{ t('chorestrike', 'Rename Room') }}</h3>
				<div class="form-group">
					<label for="room-rename-input">{{ t('chorestrike', 'Room Name') }}</label>
					<input
						id="room-rename-input"
						ref="roomRenameInput"
						v-model="renamingRoomName"
						type="text"
						class="text-input"
						data-testid="room-rename-input"
						@keyup.enter="confirmRenameRoom">
				</div>
				<div class="modal-actions">
					<button class="secondary-button" @click="closeRenameRoom">
						{{ t('chorestrike', 'Cancel') }}
					</button>
					<button
						class="primary-button"
						data-testid="room-rename-save"
						:disabled="!renamingRoomName.trim() || renamingRoom || renamingRoomName.trim() === room?.name"
						@click="confirmRenameRoom">
						{{ t('chorestrike', 'Save') }}
					</button>
				</div>
			</div>
		</Modal>

		<!-- Delete Room Confirmation (owner only) -->
		<ConfirmDialog
			v-if="roomToDelete"
			:title="deleteRoomTitle"
			:description="t('chorestrike', 'All tasks in this room will be deleted.')"
			:loading="deletingRoom"
			@confirm="confirmDeleteRoom"
			@cancel="roomToDelete = false" />

		<ParticipationDialog
			v-if="participation"
			:members="participationMembers()"
			:proposal="participation.proposal ?? null"
			:actor-user-id="userStore.currentUser?.nextcloudUserId ?? null"
			@close="participation = null"
			@confirm="confirmParticipation" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import ConfirmDialog from '../components/Common/ConfirmDialog.vue'
import ErrorState from '../components/Common/ErrorState.vue'
import Icon from '../components/Common/Icon.vue'
import Modal from '../components/Common/Modal.vue'
import ParticipationDialog from '../components/Common/ParticipationDialog.vue'
import RoomTaskEditModal from '../components/Tasks/RoomTaskEditModal.vue'
import TaskEditForm from '../components/Tasks/TaskEditForm.vue'
import TaskList from '../components/Tasks/TaskList.vue'
import TaskTemplatePicker from '../components/Tasks/TaskTemplatePicker.vue'
import { useHouseMemberNames } from '../composables/useHouseMemberNames.js'
import { useRoomsStore } from '../store/rooms.js'
import { useRoomTasksStore } from '../store/roomTasks.js'
import { useTasksStore } from '../store/tasks.js'
import { useUserStore } from '../store/user.js'
import { showCompletionError } from '../utils/errorToast.js'
import { logger } from '../utils/logger.js'
import { isOverdue } from '../utils/taskDisplay.js'

export default {
	name: 'RoomDetail',

	components: {
		Icon,
		TaskList,
		TaskEditForm,
		RoomTaskEditModal,
		TaskTemplatePicker,
		Modal,
		ConfirmDialog,
		ErrorState,
		ParticipationDialog,
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
		return { roomsStore, roomTasksStore, tasksStore, userStore, ...useHouseMemberNames(userStore) }
	},

	data() {
		return {
			loading: false,
			creating: false,
			initError: false,
			showAddTaskModal: false,
			addTaskMode: 'template',
			showEditTaskModal: false,
			editingTask: null,
			roomStats: null,
			taskToDelete: null,
			deleting: false,
			showRenameRoomModal: false,
			renamingRoomName: '',
			renamingRoom: false,
			roomToDelete: false,
			deletingRoom: false,
			// WP3 §3.6: the multi-person completion dialog target (the
			// room-task row with its server proposal) while open.
			participation: null,
		}
	},

	computed: {
		roomId() {
			return parseInt(this.id, 10)
		},

		room() {
			return this.roomsStore.getRoomById(this.roomId)
		},

		roomType() {
			return this.room?.roomType || 'generic'
		},

		canManageTasks() {
			return this.userStore.canManageTasks
		},

		floor() {
			if (!this.room) {
				return null
			}
			return this.roomsStore.getFloorById(this.room.floorId)
		},

		cleanliness() {
			return this.room?.cleanliness || 100
		},

		cleanlinessClass() {
			if (this.cleanliness >= 80) {
				return 'excellent'
			}
			if (this.cleanliness >= 60) {
				return 'good'
			}
			if (this.cleanliness >= 40) {
				return 'fair'
			}
			return 'poor'
		},

		tasks() {
			const roomTasks = this.roomTasksStore.roomTasksByRoom[this.roomId] || []
			return roomTasks.map((roomTask) => ({
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
			// The shared D-084-08 boundary, identical to the rows below.
			return this.tasks.filter((item) => isOverdue(item.roomTask)).length
		},

		editingTaskDefinition() {
			if (!this.editingTask) {
				return null
			}
			return this.tasksStore.getTaskById(this.editingTask.taskId) || null
		},

		deleteRoomTitle() {
			if (!this.roomToDelete) {
				return ''
			}
			return t('chorestrike', 'Delete "{name}"?', { name: this.room?.name })
		},
	},

	async mounted() {
		await this.initializeRoom()
	},

	methods: {
		t,
		openAddTask() {
			this.addTaskMode = 'template'
			this.showAddTaskModal = true
		},
		async initializeRoom() {
			this.loading = true
			this.initError = false
			try {
				await this.roomsStore.fetchFloorsWithRooms()
				await this.tasksStore.fetchTasks()
				await this.roomTasksStore.fetchRoomTasks(this.roomId)
				// Best-effort: the stats card must not block the room view.
				this.roomStats = await this.roomTasksStore.fetchRoomTaskStats(this.roomId)
			} catch (error) {
				// CS-PLAN-066 §3.5: an initialization failure is an explicit
				// localized error + retry state, never a silent empty room.
				this.initError = true
				logger.error('Failed to initialize room', error, { view: 'RoomDetail', action: 'initializeRoom', roomId: this.roomId })
			} finally {
				this.loading = false
			}
		},

		async handleCompleteTask(roomTaskId, claimAllocation = null) {
			// A confirmed overdue claim carries its reviewed sole-participant
			// allocation and completes directly.
			if (claimAllocation) {
				this.performComplete(roomTaskId, claimAllocation)
				return
			}
			// WP3 §3.6 (Q-074-02): the actor must take part in the work.
			// A solo agreement that already names the actor needs no
			// review — it completes directly; multi-person work (or an
			// agreement naming someone else) opens the participation
			// dialog pre-filled with the server proposal.
			const roomTask = (this.roomTasksStore.roomTasksByRoom[this.roomId] || [])
				.find((row) => row.id === roomTaskId)
			const proposal = roomTask?.proposal ?? null
			if (this.needsParticipationDialog(proposal)) {
				await this.openParticipation(roomTask)
				return
			}
			this.performComplete(roomTaskId)
		},

		/**
		 * Open the participation dialog with the actor identity resolved
		 * (the actor joins a foreign split, Q-074-02).
		 * @param {object} roomTask The room-task row with its proposal.
		 */
		async openParticipation(roomTask) {
			if (!this.userStore.currentUser) {
				await this.userStore.initializeCurrentUser().catch(() => {})
			}
			if ((this.userStore.houseUsers ?? []).length === 0) {
				this.userStore.fetchHouseUsers().catch(() => {})
			}
			this.participation = roomTask
		},

		/**
		 * Whether the completion needs the explicit participation dialog:
		 * more than one proposed participant, or a sole proposed
		 * participant who is not the acting user.
		 * @param {?object} proposal The server proposal block.
		 * @return {boolean}
		 */
		needsParticipationDialog(proposal) {
			const participants = proposal?.participants ?? []
			if (participants.length > 1) {
				return true
			}
			if (participants.length === 1) {
				const actorId = this.userStore.currentUser?.nextcloudUserId ?? null
				// An unresolved identity still opens the dialog — the safe default
				// never fires a participant-less completion into someone else's work.
				return actorId === null || participants[0].userId !== actorId
			}
			return false
		},

		/**
		 * Candidate members for the open participation dialog: the task's
		 * canonical assignees, falling back to the house membership.
		 * @return {Array<{userId: string, displayName: string}>}
		 */
		participationMembers() {
			const roomTask = this.participation
			if (!roomTask) {
				return []
			}
			const namesByUser = {}
			;(this.userStore.houseUsers ?? []).forEach((member) => {
				namesByUser[member.nextcloudUserId] = member.displayName
			})
			const assignees = roomTask.assignees ?? []
			if (assignees.length > 0) {
				return assignees.map((userId) => ({ userId, displayName: namesByUser[userId] ?? userId }))
			}
			return Object.keys(namesByUser).map((userId) => ({ userId, displayName: namesByUser[userId] }))
		},

		async confirmParticipation(payload) {
			const roomTask = this.participation
			this.participation = null
			if (!roomTask) {
				return
			}
			await this.performComplete(roomTask.id, payload)
		},

		async performComplete(roomTaskId, options = {}) {
			try {
				if (Object.keys(options).length > 0) {
					await this.roomTasksStore.completeRoomTask(roomTaskId, options)
				} else {
					await this.roomTasksStore.completeRoomTask(roomTaskId)
				}
				this.$emit('task-completed', roomTaskId)
				// The room's own stats card is a view-local projection —
				// refresh it after the committed completion (CS-PLAN-066
				// §3.2).
				this.refreshRoomStats()
			} catch (error) {
				// CS-PLAN-066 §3.5: mutation failures are visible toasts,
				// not logger-only lines.
				showCompletionError(error)
				logger.error('Failed to complete task', error, { view: 'RoomDetail', action: 'handleCompleteTask', roomTaskId })
			}
		},

		async refreshRoomStats() {
			try {
				this.roomStats = await this.roomTasksStore.fetchRoomTaskStats(this.roomId)
			} catch (error) {
				logger.error('Failed to refresh room stats', error, { view: 'RoomDetail', action: 'refreshRoomStats', roomId: this.roomId })
			}
		},

		handleEditTask(roomTask) {
			this.editingTask = roomTask
			this.showEditTaskModal = true
		},

		closeEditModal() {
			this.showEditTaskModal = false
			this.editingTask = null
		},

		async handleTaskSaved() {
			this.closeEditModal()
			// CS-PLAN-067 §3.4: no re-fetch — the edit modal reconciles the
			// due date/assignees through the owning store mutations, so the
			// room list is already authoritative here.
		},

		handleDeleteTask(roomTaskId) {
			this.taskToDelete = roomTaskId
		},

		async confirmDeleteTask() {
			const roomTaskId = this.taskToDelete
			if (roomTaskId === null) {
				return
			}
			this.deleting = true
			try {
				await this.roomTasksStore.deleteRoomTask(roomTaskId)
				this.taskToDelete = null
			} catch (error) {
				showError(t('chorestrike', 'Failed to delete task'))
				logger.error('Failed to delete task', error, { view: 'RoomDetail', action: 'confirmDeleteTask', roomTaskId })
			} finally {
				this.deleting = false
			}
		},

		/**
		 * The atomic creation aggregate (CS-PLAN-063 §3.5): exactly one
		 * request — on failure the modal stays open with the input preserved
		 * (no partial success toasts, no orphan definition). The aggregate
		 * response already reconciles the instance and definition stores, so
		 * no post-create re-fetch is needed (CS-PLAN-067 §3.4; field
		 * equivalence pinned by unit tests).
		 * @param {object} taskData Form payload: definition + roomId + assigneeUserIds + nextDue.
		 */
		async handleAddTask(taskData) {
			const { roomId: _roomId, assigneeUserIds, nextDue, ...definition } = taskData
			this.creating = true
			try {
				await this.roomTasksStore.createTaskAggregate({
					roomId: this.roomId,
					task: definition,
					assigneeUserIds: assigneeUserIds || [],
					nextDue: nextDue || null,
				})
				showSuccess(t('chorestrike', 'Task created'))
				this.showAddTaskModal = false
				this.addTaskMode = 'template'
			} catch (error) {
				// Recoverable failure: keep the modal open and the input intact.
				showError(t('chorestrike', 'Failed to create task'))
				logger.error('Failed to create task', error, { view: 'RoomDetail', action: 'handleAddTask', roomId: this.roomId })
			} finally {
				this.creating = false
			}
		},

		handleTemplateCreated() {
			this.showAddTaskModal = false
			// CS-PLAN-067 §3.4: the from-template aggregate already joined
			// the store — no room re-fetch.
		},

		handleTemplateError(error) {
			// Visible recovery (CS-PLAN-066 §3.5): the picker surfaces its
			// own failure state; this toast backs it up for the modal owner.
			showError(t('chorestrike', 'Failed to create task'))
			logger.error('Failed to create task from template', error, { view: 'RoomDetail', action: 'handleTemplateError', roomId: this.roomId })
		},

		/**
		 * Owner-only room rename (CS-PLAN-042, UX-004) — wires the
		 * rooms-store updateRoomName action that previously had no button.
		 */
		openRenameRoom() {
			this.renamingRoomName = this.room?.name || ''
			this.showRenameRoomModal = true
		},

		closeRenameRoom() {
			this.showRenameRoomModal = false
			this.renamingRoomName = ''
		},

		async confirmRenameRoom() {
			const trimmedName = this.renamingRoomName.trim()
			if (!trimmedName || trimmedName === this.room?.name) {
				return
			}
			this.renamingRoom = true
			try {
				await this.roomsStore.updateRoomName(this.roomId, trimmedName)
				showSuccess(t('chorestrike', 'Room renamed'))
				this.closeRenameRoom()
			} catch (error) {
				showError(t('chorestrike', 'Failed to rename room'))
				logger.error('Failed to rename room', error, { view: 'RoomDetail', action: 'confirmRenameRoom', roomId: this.roomId })
			} finally {
				this.renamingRoom = false
			}
		},

		/**
		 * Owner-only room delete — deletes the room and returns to the
		 * Dashboard (the room no longer exists to render). The explicit
		 * named route keeps the post-delete return on the Dashboard — the
		 * bare root lands on My Day for every role (CS-PLAN-070 WP6).
		 */
		async confirmDeleteRoom() {
			this.deletingRoom = true
			try {
				await this.roomsStore.deleteRoom(this.roomId)
				showSuccess(t('chorestrike', 'Room deleted'))
				this.roomToDelete = false
				this.$router.push({ name: 'dashboard' })
			} catch (error) {
				showError(t('chorestrike', 'Failed to delete room'))
				logger.error('Failed to delete room', error, { view: 'RoomDetail', action: 'confirmDeleteRoom', roomId: this.roomId })
			} finally {
				this.deletingRoom = false
			}
		},
	},
}
</script>

<style scoped>
.room-detail {
	padding: 1rem 2rem 2rem;
	padding-inline-start: var(--cs-view-inline-start);
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
	color: var(--color-main-text);
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

.room-name-row {
	display: flex;
	align-items: center;
	gap: 0.75rem;
}

.room-header-actions {
	display: flex;
	gap: 0.25rem;
}

.icon-action-button {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 44px;
	height: 44px;
	background: transparent;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	transition: all 0.2s;
}

.icon-action-button:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.icon-action-button.danger:hover {
	border-color: var(--color-element-error);
	color: var(--color-error-text);
}

.modal-content {
	padding: 1rem;
}

.modal-content h3 {
	margin: 0 0 1rem 0;
	font-size: 1.2rem;
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	margin-bottom: 1rem;
}

.form-group label {
	font-size: 0.9rem;
	font-weight: 500;
	color: var(--color-main-text);
}

.text-input {
	padding: 0.6rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.95rem;
}

.text-input:focus {
	outline: none;
	border-color: var(--color-primary-element);
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.75rem;
	margin-top: 1rem;
}

.primary-button,
.secondary-button {
	padding: 0.6rem 1rem;
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.9rem;
}

.primary-button {
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
}

.primary-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.secondary-button {
	background: var(--color-background-dark);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
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
	color: var(--color-success-text);
}

.stat-value.good {
	color: var(--color-success-text);
}

.stat-value.fair {
	color: var(--color-warning-text);
}

.stat-value.poor {
	color: var(--color-error-text);
}

.stat-value.warning {
	color: var(--color-error-text);
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
	flex-direction: column;
	gap: 1.5rem;
	justify-content: center;
	align-items: center;
	min-height: 300px;
}

.room-stats-card {
	width: 100%;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 1rem;
}

.room-stats-card h3 {
	margin: 0 0 0.75rem 0;
	font-size: 1rem;
	color: var(--color-main-text);
}

.stats-grid {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 0.5rem;
	margin: 0;
}

.stats-entry dt {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.stats-entry dd {
	margin: 0;
	font-size: 1.15rem;
	font-weight: bold;
	color: var(--color-main-text);
}

.stats-entry dd.warning {
	color: var(--color-error-text);
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
	border-color: var(--color-element-success);
}

.cleanliness-ring.good {
	border-color: var(--color-element-success);
}

.cleanliness-ring.fair {
	border-color: var(--color-element-warning);
}

.cleanliness-ring.poor {
	border-color: var(--color-element-error);
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
	/* UX-018: a plain 1fr track grows to its widest content and pushed the
	   view past a phone screen; minmax(0, 1fr) keeps it inside. */
	.room-detail {
		padding-inline-end: 1rem;
	}

	.room-content {
		grid-template-columns: minmax(0, 1fr);
	}

	.room-overview {
		padding: 1rem;
	}

	.section-header {
		flex-wrap: wrap;
		gap: 0.5rem;
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
