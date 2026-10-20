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
			<TaskFilterBar
				:modelValue="filters"
				:members="userStore.houseUsers"
				:membersDisabled="membersLoading || membersError"
				:floors="roomsStore.floors"
				:rooms="roomsStore.rooms"
				@update:modelValue="setFilters" />

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

			<div ref="listTop" />
			<TaskList
				:roomTasks="pagedTasks"
				:loading="loading || finishedLoading"
				:showRoom="true"
				:compact="true"
				@complete="handleCompleteTask"
				@edit="handleEditTask"
				@delete="handleDeleteTask" />

			<nav v-if="pageCount > 1"
				class="task-pager"
				data-testid="task-pager"
				:aria-label="t('chorestrike', 'Task pages')">
				<NcButton :disabled="currentPage <= 1"
					data-testid="task-pager-previous"
					@click="goToPage(currentPage - 1)">
					{{ t('chorestrike', 'Previous') }}
				</NcButton>
				<span class="pager-status" data-testid="task-pager-status" aria-live="polite">
					{{ t('chorestrike', 'Page {page} of {pages}', { page: currentPage, pages: pageCount }) }}
				</span>
				<NcButton :disabled="currentPage >= pageCount"
					data-testid="task-pager-next"
					@click="goToPage(currentPage + 1)">
					{{ t('chorestrike', 'Next') }}
				</NcButton>
			</nav>
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
			:actorUserId="userStore.currentUser?.nextcloudUserId ?? null"
			@close="participation = null"
			@confirm="confirmParticipation" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import { useRoute, useRouter } from 'vue-router'
import ConfirmDialog from '../components/Common/ConfirmDialog.vue'
import ErrorState from '../components/Common/ErrorState.vue'
import Icon from '../components/Common/Icon.vue'
import Modal from '../components/Common/Modal.vue'
import ParticipationDialog from '../components/Common/ParticipationDialog.vue'
import TaskEditForm from '../components/Tasks/TaskEditForm.vue'
import TaskFilterBar from '../components/Tasks/TaskFilterBar.vue'
import TaskList from '../components/Tasks/TaskList.vue'
import { useHouseMemberNames } from '../composables/useHouseMemberNames.js'
import { useHouseStore } from '../store/house.js'
import { useRoomsStore } from '../store/rooms.js'
import { useRoomTasksStore } from '../store/roomTasks.js'
import { useTasksStore } from '../store/tasks.js'
import { useUserStore } from '../store/user.js'
import { showCompletionError, showErrorWithReference } from '../utils/errorToast.js'
import { logger } from '../utils/logger.js'
import { compareTaskItems, dueClass, isOverdue } from '../utils/taskDisplay.js'

// Status filter values a `/tasks?status=` link may preset.
const STATUS_PRESETS = ['active', 'overdue', 'due-today']
// Client-side page size of the all-tasks list (CS-PLAN-084 D-084-02).
const PAGE_SIZE = 20

/**
 * Initial filters from the router contract (`docs/concepts/ui-use-cases.md`):
 * `?visibility=private` (CS-PLAN-041), `?scope=`, `?status=` (CS-PLAN-084
 * WP-C); unknown values keep the defaults.
 * @param {object} query Route query.
 * @return {object} Filter values for TaskFilterBar.
 */
function filtersFromQuery(query = {}) {
	const filters = { scope: 'mine', person: '', floor: '', room: '', status: 'all', priority: 'all', difficulty: 'all', visibility: 'all', search: '' }
	if (query.visibility === 'private') {
		filters.visibility = 'private'
		filters.scope = 'all'
	}
	if (['mine', 'unassigned', 'all'].includes(query.scope)) {
		filters.scope = query.scope
	}
	if (STATUS_PRESETS.includes(query.status)) {
		filters.status = query.status
	}
	return filters
}

/**
 * Page number from `?page=` (1 when missing or invalid).
 * @param {unknown} value Raw query value.
 * @return {number}
 */
function pageFromQuery(value) {
	const page = Number.parseInt(value, 10)
	return Number.isInteger(page) && page > 0 ? page : 1
}

export default {
	name: 'TaskManager',

	components: {
		Icon,
		NcButton,
		TaskList,
		TaskFilterBar,
		TaskEditForm,
		Modal,
		ConfirmDialog,
		ErrorState,
		ParticipationDialog,
	},

	emits: ['taskCompleted'],

	setup() {
		const roomTasksStore = useRoomTasksStore()
		const roomsStore = useRoomsStore()
		const tasksStore = useTasksStore()
		const userStore = useUserStore()
		const houseStore = useHouseStore()
		const route = useRoute()
		const router = useRouter()
		return { roomTasksStore, roomsStore, tasksStore, userStore, houseStore, route, router, ...useHouseMemberNames(userStore) }
	},

	data() {
		return {
			loading: false,
			creating: false,
			initError: false,
			filters: filtersFromQuery(this.route?.query),
			page: pageFromQuery(this.route?.query?.page),
			// D-084-07: the on-demand finished-task read behind "Completed".
			finishedLoading: false,
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
		allTasks() {
			return this.roomTasksStore.roomTasks.map((roomTask) => this.enrich(roomTask))
		},

		filteredTasks() {
			const { status, priority, difficulty, visibility, search } = this.filters
			const roomIds = new Set(this.roomsStore.rooms
				.filter((room) => !this.filters.floor || String(room.floorId) === this.filters.floor)
				.map((room) => room.id))
			// D-084-07: "Completed" lists the finished rows read on demand.
			let tasks = status === 'completed'
				? this.roomTasksStore.finishedRoomTasks.map((roomTask) => this.enrich(roomTask))
				: this.allTasks
			tasks = tasks.filter(({ roomTask }) => this.matchesScopeFilter(roomTask, roomIds))

			if (status === 'active') {
				tasks = tasks.filter((t) => t.roomTask.isActive)
			} else if (status === 'overdue') {
				// Same missed-work rule as the row badge and claim button (D-084-08).
				tasks = tasks.filter((t) => isOverdue(t.roomTask))
			} else if (status === 'due-today') {
				// The row badge's house-local rule: due today or an open flex window (D-084-08).
				tasks = tasks.filter((t) => t.roomTask.nextDue && dueClass(t.roomTask, this.houseStore.timezone) === 'today')
			}

			if (priority !== 'all') {
				const priorityMap = { urgent: 3, normal: 2, low: 1 }
				tasks = tasks.filter((t) => t.task.priority === priorityMap[priority])
			}

			if (difficulty !== 'all') {
				tasks = tasks.filter((t) => t.task.energyCost === parseInt(difficulty, 10))
			}

			// Visibility filter (CS-PLAN-041): "private" scopes the list to the
			// current user's private task definitions — the destination of the
			// Dashboard QuickStats "My Private Tasks" chip.
			if (visibility === 'private') {
				tasks = tasks.filter((t) => t.task.visibilityType === 'private')
			}

			if (search.trim()) {
				const query = search.toLowerCase()
				tasks = tasks.filter((t) => t.task.name?.toLowerCase().includes(query))
			}

			// Sorted here so each page holds the next rows of the visible order.
			return [...tasks].sort(compareTaskItems)
		},

		pageCount() {
			return Math.max(1, Math.ceil(this.filteredTasks.length / PAGE_SIZE))
		},

		// A page beyond the end (fewer rows after a refresh) shows the last page.
		currentPage() {
			return Math.min(this.page, this.pageCount)
		},

		pagedTasks() {
			const start = (this.currentPage - 1) * PAGE_SIZE
			return this.filteredTasks.slice(start, start + PAGE_SIZE)
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

	watch: {
		// Browser back/forward between pages keeps the view mounted.
		'route.query.page': function(value) {
			this.page = pageFromQuery(value)
		},
	},

	async mounted() {
		await this.initializeTasks()
	},

	methods: {
		t,

		/**
		 * Enriched list row; a missing definition gets a neutral placeholder.
		 * @param {object} roomTask Room task payload.
		 * @return {{roomTask: object, task: object}}
		 */
		enrich(roomTask) {
			return {
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
			}
		},

		/**
		 * Apply new filter values: back to page 1, and selecting "Completed"
		 * reads the finished tasks on demand (D-084-07).
		 * @param {object} next Filter values from TaskFilterBar.
		 */
		setFilters(next) {
			const enteringCompleted = next.status === 'completed' && this.filters.status !== 'completed'
			this.filters = next
			this.goToPage(1, false)
			if (enteringCompleted) {
				this.loadFinishedTasks()
			}
		},

		async loadFinishedTasks() {
			this.finishedLoading = true
			try {
				await this.roomTasksStore.fetchInactiveHouseRoomTasks()
			} catch (error) {
				showErrorWithReference(t('chorestrike', 'Could not load finished tasks'), error)
				logger.error('Failed to load finished tasks', error, { view: 'TaskManager', action: 'loadFinishedTasks' })
			} finally {
				this.finishedLoading = false
			}
		},

		/**
		 * Show a page and mirror it in `?page=` (omitted for page 1).
		 * @param {number} page Target page.
		 * @param {boolean} scroll Whether to bring the list start into view.
		 */
		goToPage(page, scroll = true) {
			this.page = page
			const current = this.route?.query ?? {}
			const query = { ...current }
			if (page > 1) {
				query.page = String(page)
			} else {
				delete query.page
			}
			if (query.page !== current.page) {
				// The page is already shown; a failed URL mirror only loses the deep link.
				this.router?.replace({ query }).catch((error) => logger.warn('Could not mirror the task page in the URL', { view: 'TaskManager', action: 'goToPage', page, error: error?.message }))
			}
			if (scroll) {
				this.$refs.listTop?.scrollIntoView?.({ block: 'start' })
			}
		},

		matchesScopeFilter(roomTask, roomIds) {
			const { scope, person, floor, room } = this.filters
			const actorId = this.userStore.currentUser?.nextcloudUserId
			const assignees = roomTask.assignees ?? []
			// Unknown user (identity not resolved yet): show assigned rows
			// rather than an empty list, mirroring TodayView.isMine.
			if (scope === 'mine' && actorId && assignees.length > 0 && !assignees.includes(actorId)) {
				return false
			}
			if (scope === 'unassigned' && assignees.length > 0) {
				return false
			}
			if (person && !assignees.includes(person)) {
				return false
			}
			if (floor && !roomIds.has(roomTask.roomId)) {
				return false
			}
			return !room || String(roomTask.roomId) === room
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
			const roomTask = this.roomTasksStore.roomTasks.find((row) => row.id === roomTaskId)
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
				this.$emit('taskCompleted', roomTaskId)
			} catch (error) {
				// CS-PLAN-066 §3.5: mutation failures are visible toasts,
				// not logger-only lines.
				showCompletionError(error)
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
			if (roomTaskId === null) {
				return
			}
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
			if (!this.editingTask) {
				return
			}
			this.tasksStore.updateTask(this.editingTask.id, taskData)
				.then(() => {
					this.showEditTaskModal = false
					this.editingTask = null
				})
				.catch((error) => {
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
	padding: 1rem var(--cs-view-gutter) 2rem;
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
	padding-inline-start: var(--cs-view-heading-inset);
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

.task-pager {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 1rem;
	margin-block-start: 1rem;
}

.pager-status {
	color: var(--color-main-text);
}

.stat-label {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

@media (max-width: 768px) {
	.stats-bar {
		justify-content: space-around;
		gap: 1rem;
	}
}
</style>
