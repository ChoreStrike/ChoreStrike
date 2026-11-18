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
				@click="addTask = { room: null }">
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
				:categories="categoryChoices"
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

			<SegmentedSwitch
				:modelValue="group"
				:options="groupingOptions()"
				:label="t('chorestrike', 'Grouping')"
				testidPrefix="tasks-group"
				@update:modelValue="setGroup" />

			<div ref="listTop" />
			<TaskList
				v-if="group === 'due' || loading || finishedLoading"
				:roomTasks="pagedTasks"
				:loading="loading || finishedLoading"
				:compact="true"
				:compare="dueOrder"
				:sectionLabel="dueSectionLabel"
				@complete="handleCompleteTask"
				@edit="handleEditTask"
				@delete="handleDeleteTask" />
			<TaskGroupList
				v-else
				:sections="sections"
				:openKey="openGroup"
				:canAdd="canManageTasks"
				@toggle="toggleGroup"
				@add="addTask = { room: $event }"
				@complete="handleCompleteTask"
				@edit="handleEditTask"
				@delete="handleDeleteTask" />

			<TaskPager v-if="group === 'due' && pageCount > 1"
				:page="currentPage"
				:pageCount="pageCount"
				@go="goToPage" />
		</template>

		<TaskCreateDialog
			v-if="addTask"
			:room="addTask.room"
			:prefill="newTaskPrefill"
			@close="addTask = null" />

		<Modal v-if="showEditTaskModal"
			persistent
			:aria-label="t('chorestrike', 'Edit Task')"
			@close="showEditTaskModal = false">
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
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { useRoute, useRouter } from 'vue-router'
import ConfirmDialog from '../components/Common/ConfirmDialog.vue'
import ErrorState from '../components/Common/ErrorState.vue'
import Icon from '../components/Common/Icon.vue'
import Modal from '../components/Common/Modal.vue'
import SegmentedSwitch from '../components/Common/SegmentedSwitch.vue'
import TaskCreateDialog from '../components/Tasks/TaskCreateDialog.vue'
import TaskEditForm from '../components/Tasks/TaskEditForm.vue'
import TaskFilterBar from '../components/Tasks/TaskFilterBar.vue'
import TaskGroupList from '../components/Tasks/TaskGroupList.vue'
import TaskList from '../components/Tasks/TaskList.vue'
import TaskPager from '../components/Tasks/TaskPager.vue'
import { useHouseMemberNames } from '../composables/useHouseMemberNames.js'
import { useTaskCompletion } from '../composables/useTaskCompletion.js'
import { useHouseStore } from '../store/house.js'
import { useRoomsStore } from '../store/rooms.js'
import { useRoomTasksStore } from '../store/roomTasks.js'
import { useTasksStore } from '../store/tasks.js'
import { useUserStore } from '../store/user.js'
import { showErrorWithReference } from '../utils/errorToast.js'
import { logger } from '../utils/logger.js'
import { categoryOptions } from '../utils/taskCategories.js'
import { groupingOptions } from '../utils/taskDisplay.js'
import { filtersFromQuery, filterTaskItems, pageFromQuery } from '../utils/taskFilters.js'
import { compareByDueGroup, dueGroupOf, groupByDue, groupByPerson, groupByRoom, groupingFromQuery, openGroupFromQuery, sortByDueGroup, UNASSIGNED } from '../utils/taskGrouping.js'

// Client-side page size of the all-tasks list (CS-PLAN-084 D-084-02).
const PAGE_SIZE = 20

export default {
	name: 'TaskManager',

	components: {
		Icon,
		TaskList,
		TaskPager,
		TaskGroupList,
		SegmentedSwitch,
		TaskFilterBar,
		TaskCreateDialog,
		TaskEditForm,
		Modal,
		ConfirmDialog,
		ErrorState,
	},

	emits: ['taskCompleted'],

	setup(props, { emit }) {
		const roomTasksStore = useRoomTasksStore()
		const roomsStore = useRoomsStore()
		const tasksStore = useTasksStore()
		const userStore = useUserStore()
		const houseStore = useHouseStore()
		const route = useRoute()
		const router = useRouter()
		const completion = useTaskCompletion({
			roomTasksStore,
			view: 'TaskManager',
			onCompleted: (roomTaskId) => emit('taskCompleted', roomTaskId),
		})
		return { roomTasksStore, roomsStore, tasksStore, userStore, houseStore, route, router, ...useHouseMemberNames(userStore), ...completion }
	},

	data() {
		return {
			loading: false,
			initError: false,
			filters: filtersFromQuery(this.route?.query),
			page: pageFromQuery(this.route?.query?.page),
			// By due date, by room or by person, mirrored in `?group=`; a
			// grouped list has one open group, a room's mirrored in `?room=`.
			group: groupingFromQuery(this.route?.query?.group),
			openGroup: openGroupFromQuery(this.route?.query),
			// D-084-07: the on-demand finished-task read behind "Completed".
			finishedLoading: false,
			// The create dialog: `{ room }`, a room when opened from its heading.
			addTask: null,
			// The row whose due date and "Last done" are edited.
			showEditTaskModal: false,
			editingTask: null,
			taskToDelete: null,
			deleting: false,
		}
	},

	computed: {
		allTasks() {
			return this.roomTasksStore.roomTasks.map((roomTask) => this.enrich(roomTask))
		},

		filteredTasks() {
			// D-084-07: "Completed" lists the finished rows read on demand.
			const tasks = this.filters.status === 'completed'
				? this.roomTasksStore.finishedRoomTasks.map((roomTask) => this.enrich(roomTask))
				: this.allTasks
			// Sorted here so each page holds the next rows of the visible order.
			return sortByDueGroup(filterTaskItems(tasks, this.filters, {
				rooms: this.roomsStore.rooms,
				actorId: this.userStore.currentUser?.nextcloudUserId,
				timezone: this.houseStore.timezone,
				categories: this.houseStore.taskCategories.items,
			}), this.houseStore.timezone)
		},

		categoryChoices() {
			return categoryOptions(this.houseStore.taskCategories.items)
		},

		dueOrder() {
			return compareByDueGroup(this.houseStore.timezone)
		},

		/** Heading per due class with its count over all pages. */
		dueHeadings() {
			const labels = {
				overdue: (count) => t('chorestrike', 'Overdue · {count}', { count }),
				today: (count) => t('chorestrike', 'Due today · {count}', { count }),
				upcoming: (count) => t('chorestrike', 'Later · {count}', { count }),
				completed: (count) => t('chorestrike', 'Completed · {count}', { count }),
			}
			return Object.fromEntries(groupByDue(this.filteredTasks, this.houseStore.timezone)
				.map((group) => [group.key, labels[group.key](group.items.length)]))
		},

		/**
		 * The grouped list: floors with their rooms (narrowed by the floor
		 * and room filters), or one section of people.
		 * @return {object[]} Sections for TaskGroupList.
		 */
		sections() {
			if (this.group === 'person') {
				const groups = groupByPerson(this.filteredTasks, this.userStore.houseUsers)
					.map((entry) => ({
						...entry,
						key: entry.key === UNASSIGNED ? 'unassigned' : `user:${entry.key}`,
						label: entry.key === UNASSIGNED ? t('chorestrike', 'Unassigned') : entry.name,
					}))
				return groups.length > 0 ? [{ key: 'people', label: '', groups }] : []
			}
			const { floor, room } = this.filters
			const rooms = this.roomsStore.rooms.filter((entry) => (!floor || String(entry.floorId) === floor) && (!room || String(entry.id) === room))
			return groupByRoom(this.filteredTasks, this.roomsStore.floors, rooms).map((entry) => ({
				key: entry.floor.id,
				label: entry.floor.name,
				open: entry.open,
				groups: entry.rooms.map((roomGroup) => ({ ...roomGroup, key: roomGroup.room.id, label: roomGroup.room.name })),
			}))
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

		/**
		 * FEAT-UX-038: the active floor/room/person filters prefill a new
		 * task; the "mine"/"unassigned" scopes preselect nobody (D-088-13).
		 * @return {{roomId: ?number, floorId: ?number, assigneeIds: string[]}}
		 */
		newTaskPrefill() {
			const { floor, room, person } = this.filters
			const isMember = this.userStore.houseUsers.some((member) => member.nextcloudUserId === person)
			return {
				roomId: room ? Number(room) : null,
				floorId: floor ? Number(floor) : null,
				assigneeIds: person && isMember ? [person] : [],
			}
		},

		overdueTasks() {
			return this.roomTasksStore.totalOverdueTasks
		},

		dueTodayTasks() {
			return this.roomTasksStore.totalDueTodayTasks
		},
	},

	watch: {
		// Browser back/forward keeps the view mounted.
		'route.query.page': function(value) {
			this.page = pageFromQuery(value)
		},

		// A link into a grouped list shows everyone's tasks, like the switch.
		'route.query.group': function(value) {
			this.group = groupingFromQuery(value)
			this.showEveryoneWhenGrouped()
		},

		'route.query.room': function() {
			if (this.group === 'room') {
				this.openGroup = openGroupFromQuery(this.route?.query)
			}
		},
	},

	async mounted() {
		await this.initializeTasks()
	},

	methods: {
		t,
		groupingOptions,

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

		// The due-class heading of a row, for TaskList.
		dueSectionLabel(item) {
			return this.dueHeadings[dueGroupOf(item, this.houseStore.timezone)] ?? ''
		},

		/**
		 * Mirror view state in the route query; a null value drops its key.
		 * The state is already shown, so a failed mirror only loses the deep link.
		 * @param {object} patch Query keys to set or drop.
		 */
		mirrorQuery(patch) {
			const current = this.route?.query ?? {}
			const query = { ...current }
			for (const [key, value] of Object.entries(patch)) {
				if (value === null) {
					delete query[key]
				} else {
					query[key] = String(value)
				}
			}
			if (Object.keys({ ...current, ...query }).some((key) => query[key] !== current[key])) {
				this.router?.replace({ query }).catch((error) => logger.warn('Could not mirror the task list in the URL', { view: 'TaskManager', action: 'mirrorQuery', error: error?.message }))
			}
		},

		/**
		 * Show a page and mirror it in `?page=` (omitted for page 1).
		 * @param {number} page Target page.
		 * @param {boolean} scroll Whether to bring the list start into view.
		 */
		goToPage(page, scroll = true) {
			this.page = page
			this.mirrorQuery({ page: page > 1 ? page : null })
			if (scroll) {
				this.$refs.listTop?.scrollIntoView?.({ block: 'start' })
			}
		},

		/**
		 * Switch the grouping. A grouped list starts with everyone's tasks
		 * and no open group (D-090-41).
		 * @param {string} group 'due' | 'room' | 'person'.
		 */
		setGroup(group) {
			this.group = group
			this.openGroup = null
			this.page = 1
			this.showEveryoneWhenGrouped()
			this.mirrorQuery({ group: group === 'due' ? null : group, room: null, page: null })
		},

		showEveryoneWhenGrouped() {
			if (this.group !== 'due' && this.filters.scope === 'mine') {
				this.filters = { ...this.filters, scope: 'all' }
			}
		},

		/**
		 * Open a group, or close the open one; a room's is mirrored in `?room=`.
		 * @param {string|number} key Group key.
		 */
		toggleGroup(key) {
			this.openGroup = this.openGroup === key ? null : key
			if (this.group === 'room') {
				this.mirrorQuery({ room: this.openGroup })
			}
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

		/**
		 * Complete a row through the shared flow (`useTaskCompletion`).
		 * @param {number} roomTaskId The row's room task.
		 * @param {?object} split The split of the row's picker or of a confirmed overdue claim.
		 */
		async handleCompleteTask(roomTaskId, split = null) {
			await this.requestCompletion(roomTaskId, split)
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

		handleUpdateTask(taskData) {
			if (!this.editingTask) {
				return
			}
			this.tasksStore.updateTask(this.editingTask.id, taskData)
				.then(() => {
					this.showEditTaskModal = false
					this.editingTask = null
					// A saved definition changes open cycles too (energy,
					// flex days): without their rows, completing conflicts.
					this.roomTasksStore.refreshDerivedProjections({ rows: true })
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
