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
			<button
				v-if="canManageTasks"
				class="primary-button"
				data-testid="add-task-button"
				:disabled="loading || initError"
				@click="showAddTaskModal = true">
				<Icon name="Plus" :size="16" class="icon" />
				{{ t('chorestrike', 'New Task') }}
			</button>
		</header>

		<!-- Initialization failed (CS-PLAN-066 §3.5): an explicit localized
		     retry state instead of a silent empty list. -->
		<ErrorState
			v-if="initError"
			data-testid="task-manager-error"
			:message="t('chorestrike', 'Could not load your tasks')"
			@retry="initializeTasks" />

		<template v-else>
			<ErrorState
				v-if="membersError"
				data-testid="member-names-error"
				:message="t('chorestrike', 'Failed to load members')"
				@retry="loadMembers" />
			<div class="scope-filters" role="group" :aria-label="t('chorestrike', 'Task filter')">
				<button type="button"
					:aria-pressed="filterScope === 'mine'"
					data-testid="tasks-filter-mine"
					@click="setScope('mine')">
					{{ t('chorestrike', 'My tasks') }}
				</button>
				<button type="button"
					:aria-pressed="filterScope === 'unassigned'"
					data-testid="tasks-filter-unassigned"
					@click="setScope('unassigned')">
					{{ t('chorestrike', 'Unassigned') }}
				</button>
				<button type="button"
					:aria-pressed="filterScope === 'all'"
					data-testid="tasks-filter-all"
					@click="setScope('all')">
					{{ t('chorestrike', 'Everyone') }}
				</button>
			</div>
			<p v-if="filterScope === 'mine'" class="scope-hint">
				{{ t('chorestrike', 'Your tasks and unassigned tasks') }}
			</p>
			<div class="filters-section">
				<div class="filter-group">
					<label for="task-person-filter">{{ t('chorestrike', 'Person') }}</label>
					<select id="task-person-filter"
						v-model="filterPerson"
						:disabled="membersLoading || membersError"
						class="filter-select"
						data-testid="person-filter"
						@change="filterScope = 'all'">
						<option value="">
							{{ t('chorestrike', 'Everyone') }}
						</option>
						<option v-for="member in userStore.houseUsers" :key="member.nextcloudUserId" :value="member.nextcloudUserId">
							{{ member.displayName || member.nextcloudUserId }}
						</option>
					</select>
				</div>
				<div class="filter-group">
					<label for="task-floor-filter">{{ t('chorestrike', 'Floor') }}</label>
					<select id="task-floor-filter"
						v-model="filterFloor"
						class="filter-select"
						data-testid="floor-filter"
						@change="filterRoom = ''">
						<option value="">
							{{ t('chorestrike', 'All floors') }}
						</option>
						<option v-for="floor in roomsStore.floors" :key="floor.id" :value="String(floor.id)">
							{{ floor.name }}
						</option>
					</select>
				</div>
				<div class="filter-group">
					<label for="task-room-filter">{{ t('chorestrike', 'Room') }}</label>
					<select id="task-room-filter"
						v-model="filterRoom"
						class="filter-select"
						data-testid="room-filter">
						<option value="">
							{{ t('chorestrike', 'All rooms') }}
						</option>
						<option v-for="room in filterRooms" :key="room.id" :value="String(room.id)">
							{{ room.name }}
						</option>
					</select>
				</div>
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
							{{ t('chorestrike', 'High') }}
						</option>
						<option value="normal">
							{{ t('chorestrike', 'Normal') }}
						</option>
						<option value="low">
							{{ t('chorestrike', 'Low') }}
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

				<div class="filter-group">
					<label>{{ t('chorestrike', 'Visibility') }}</label>
					<select v-model="filterVisibility" class="filter-select" data-testid="visibility-filter">
						<option value="all">
							{{ t('chorestrike', 'All') }}
						</option>
						<option value="private">
							{{ t('chorestrike', 'Private') }}
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
		</template>

		<Modal v-if="showAddTaskModal" :aria-label="t('chorestrike', 'New Task')" @close="showAddTaskModal = false">
			<TaskEditForm
				:loading="creating"
				@submit="handleAddTask"
				@cancel="showAddTaskModal = false" />
		</Modal>

		<Modal v-if="showEditTaskModal" :aria-label="t('chorestrike', 'Edit Task')" @close="showEditTaskModal = false">
			<TaskEditForm
				:task="editingTask"
				@submit="handleUpdateTask"
				@cancel="showEditTaskModal = false" />
		</Modal>

		<ConfirmDialog
			v-if="taskToDelete !== null"
			:title="t('chorestrike', 'Are you sure you want to delete this task?')"
			:loading="deleting"
			@confirm="confirmDeleteTask"
			@cancel="taskToDelete = null" />

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
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { useRoomTasksStore } from '../store/roomTasks.js'
import { useRoomsStore } from '../store/rooms.js'
import { useTasksStore } from '../store/tasks.js'
import { useHouseStore } from '../store/house.js'
import { dueClass, isOverdue } from '../utils/taskDisplay.js'
import { logger } from '../utils/logger.js'
import Icon from '../components/Common/Icon.vue'
import TaskList from '../components/Tasks/TaskList.vue'
import ParticipationDialog from '../components/Common/ParticipationDialog.vue'
import TaskEditForm from '../components/Tasks/TaskEditForm.vue'
import ConfirmDialog from '../components/Common/ConfirmDialog.vue'
import ErrorState from '../components/Common/ErrorState.vue'
import { useUserStore } from '../store/user.js'
import Modal from '../components/Common/Modal.vue'
import { useRoute } from 'vue-router'
import { useHouseMemberNames } from '../composables/useHouseMemberNames.js'

export default {
	name: 'TaskManager',

	components: {
		Icon,
		TaskList,
		TaskEditForm,
		Modal,
		ConfirmDialog,
		ErrorState,
		ParticipationDialog,
	},

	setup() {
		const roomTasksStore = useRoomTasksStore()
		const roomsStore = useRoomsStore()
		const tasksStore = useTasksStore()
		const userStore = useUserStore()
		const houseStore = useHouseStore()
		const route = useRoute()
		return { roomTasksStore, roomsStore, tasksStore, userStore, houseStore, route, ...useHouseMemberNames(userStore) }
	},

	data() {
		return {
			loading: false,
			creating: false,
			initError: false,
			filterScope: 'mine',
			filterPerson: '',
			filterFloor: '',
			filterRoom: '',
			filterStatus: 'all',
			filterPriority: 'all',
			filterDifficulty: 'all',
			filterVisibility: 'all',
			searchQuery: '',
			showAddTaskModal: false,
			showEditTaskModal: false,
			editingTask: null,
			taskToDelete: null,
			deleting: false,
			// WP3 §3.6: the multi-person completion dialog target (the
			// room-task row with its server proposal) while open.
			participation: null,
		}
	},

	computed: {
		filterRooms() {
			return this.roomsStore.rooms.filter(room => !this.filterFloor || String(room.floorId) === this.filterFloor)
		},

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
			const roomIds = new Set(this.filterRooms.map(room => room.id))
			let tasks = this.allTasks.filter(({ roomTask }) => this.matchesScopeFilter(roomTask, roomIds))

			// Status filter
			if (this.filterStatus === 'active') {
				tasks = tasks.filter(t => t.roomTask.isActive)
			} else if (this.filterStatus === 'overdue') {
				// Same missed-work rule as the row badge and claim button (D-084-08).
				tasks = tasks.filter(t => isOverdue(t.roomTask))
			} else if (this.filterStatus === 'due-today') {
				// The row badge's house-local rule: due today or an open flex window (D-084-08).
				tasks = tasks.filter(t => t.roomTask.nextDue && dueClass(t.roomTask, this.houseStore.timezone) === 'today')
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

			// Visibility filter (CS-PLAN-041): "private" scopes the list to the
			// current user's private task definitions — the destination of the
			// Dashboard QuickStats "My Private Tasks" chip.
			if (this.filterVisibility === 'private') {
				tasks = tasks.filter(t => t.task.visibilityType === 'private')
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

		canManageTasks() {
			return this.userStore.canManageTasks
		},

		overdueTasks() {
			return this.roomTasksStore.totalOverdueTasks
		},

		dueTodayTasks() {
			return this.roomTasksStore.totalDueTodayTasks
		},
	},

	async mounted() {
		// Deep-link scope: the Dashboard "My Private Tasks" chip routes here
		// with ?visibility=private (CS-PLAN-041).
		if (this.route?.query?.visibility === 'private') {
			this.filterVisibility = 'private'
			this.filterScope = 'all'
		}
		if (['mine', 'unassigned', 'all'].includes(this.route?.query?.scope)) {
			this.filterScope = this.route.query.scope
		}
		await this.initializeTasks()
	},

	methods: {
		t,

		setScope(scope) {
			this.filterScope = scope
			this.filterPerson = ''
		},

		matchesScopeFilter(roomTask, roomIds) {
			const actorId = this.userStore.currentUser?.nextcloudUserId
			const assignees = roomTask.assignees ?? []
			// Unknown user (identity not resolved yet): show assigned rows
			// rather than an empty list, mirroring TodayView.isMine.
			if (this.filterScope === 'mine' && actorId && assignees.length > 0 && !assignees.includes(actorId)) return false
			if (this.filterScope === 'unassigned' && assignees.length > 0) return false
			if (this.filterPerson && !assignees.includes(this.filterPerson)) return false
			if (this.filterFloor && !roomIds.has(roomTask.roomId)) return false
			return !this.filterRoom || String(roomTask.roomId) === this.filterRoom
		},

		async initializeTasks() {
			this.loading = true
			this.initError = false
			try {
				await this.roomsStore.fetchFloorsWithRooms()
				await this.tasksStore.fetchTasks()
				// CS-PLAN-067 (D1): ONE house-wide instance request replaces
				// the per-room fetch loop; the stats bar reads the dedicated
				// count projections (CS-PLAN-066 D2).
				await Promise.all([
					this.roomTasksStore.fetchDueTodayTasks(),
					this.roomTasksStore.fetchOverdueTasks(),
					this.roomTasksStore.fetchHouseRoomTasks(),
				])
			} catch (error) {
				// CS-PLAN-066 §3.5: an initialization failure is an explicit
				// localized error + retry state, never a silent empty list.
				this.initError = true
				logger.error('Failed to initialize tasks', error, { view: 'TaskManager', action: 'initializeTasks' })
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
			// WP3 §3.6 (Q-074-02): a solo agreement naming the actor
			// completes directly; multi-person work (or an agreement
			// naming someone else) opens the participation dialog
			// pre-filled with the server proposal.
			const roomTask = this.roomTasksStore.roomTasks.find(row => row.id === roomTaskId)
			if (this.needsParticipationDialog(roomTask?.proposal ?? null)) {
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
		 * Candidate members for the open participation dialog.
		 * @return {Array<{userId: string, displayName: string}>}
		 */
		participationMembers() {
			const roomTask = this.participation
			if (!roomTask) return []
			const namesByUser = {}
			;(this.userStore.houseUsers ?? []).forEach(member => {
				namesByUser[member.nextcloudUserId] = member.displayName
			})
			const assignees = roomTask.assignees ?? []
			if (assignees.length > 0) {
				return assignees.map(userId => ({ userId, displayName: namesByUser[userId] ?? userId }))
			}
			return Object.keys(namesByUser).map(userId => ({ userId, displayName: namesByUser[userId] }))
		},

		async confirmParticipation(payload) {
			const roomTask = this.participation
			this.participation = null
			if (!roomTask) return
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
			} catch (error) {
				// CS-PLAN-066 §3.5: mutation failures are visible toasts,
				// not logger-only lines.
				showError(t('chorestrike', 'Could not complete the task'))
				logger.error('Failed to complete task', error, { view: 'TaskManager', action: 'handleCompleteTask', roomTaskId })
			}
		},

		handleEditTask(roomTask) {
			this.editingTask = this.tasksStore.getTaskById(roomTask.taskId) || null
			this.showEditTaskModal = true
		},

		handleDeleteTask(roomTaskId) {
			this.taskToDelete = roomTaskId
		},

		async confirmDeleteTask() {
			const roomTaskId = this.taskToDelete
			if (roomTaskId === null) return
			this.deleting = true
			try {
				await this.roomTasksStore.deleteRoomTask(roomTaskId)
				this.taskToDelete = null
			} catch (error) {
				showError(t('chorestrike', 'Failed to delete task'))
				logger.error('Failed to delete task', error, { view: 'TaskManager', action: 'confirmDeleteTask', roomTaskId })
			} finally {
				this.deleting = false
			}
		},

		/**
		 * The atomic creation aggregate (CS-PLAN-063 §3.5): exactly one
		 * request — on failure the modal stays open with the input preserved
		 * (no partial success toasts, no orphan definition). The store
		 * reconciles the aggregate response into the instance and definition
		 * stores, so no post-create re-fetch is needed (CS-PLAN-067 §3.4:
		 * aggregate vs. list field equality is pinned by unit tests).
		 * @param {object} taskData Form payload: definition + roomId + assigneeUserIds + nextDue.
		 */
		async handleAddTask(taskData) {
			const { roomId, assigneeUserIds, nextDue, ...definition } = taskData
			this.creating = true
			try {
				await this.roomTasksStore.createTaskAggregate({
					roomId,
					task: definition,
					assigneeUserIds: assigneeUserIds || [],
					nextDue: nextDue || null,
				})
				showSuccess(t('chorestrike', 'Task created'))
				this.showAddTaskModal = false
			} catch (error) {
				// Recoverable failure: keep the modal open and the input intact.
				showError(t('chorestrike', 'Failed to create task'))
				logger.error('Failed to create task', error, { view: 'TaskManager', action: 'handleAddTask', roomId })
			} finally {
				this.creating = false
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
					// Recoverable failure (CS-PLAN-066 §3.5): the modal stays
					// open with the input intact and a visible error toast.
					showError(t('chorestrike', 'Could not save the task'))
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

.scope-filters {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	margin-block-end: 0.5rem;
}

.scope-filters button {
	min-block-size: 44px;
	padding: 0.5rem 1rem;
	border: 1px solid var(--color-text-maxcontrast);
	border-radius: 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.scope-filters button[aria-pressed='true'] {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-color: var(--color-primary-element);
	font-weight: 700;
}

.scope-filters button:focus-visible,
.filter-select:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.scope-hint {
	margin-block: 0 1rem;
	color: var(--color-text-maxcontrast);
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
	min-block-size: 44px;
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
	color: var(--color-error-text);
}

.stat-number.success {
	color: var(--color-success-text);
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
