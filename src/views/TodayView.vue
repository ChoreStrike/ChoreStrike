<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="today-view" data-testid="today-view">
		<header class="today-header">
			<h1 data-testid="today-title">
				{{ t('chorestrike', 'My Day') }}
			</h1>
			<div class="filter-toggle" role="group" :aria-label="t('chorestrike', 'Task filter')">
				<button
					class="filter-button"
					:class="{ active: filter === 'mine' }"
					data-testid="today-filter-mine"
					@click="filter = 'mine'">
					{{ t('chorestrike', 'My tasks') }}
				</button>
				<button
					class="filter-button"
					:class="{ active: filter === 'all' }"
					data-testid="today-filter-all"
					@click="filter = 'all'">
					{{ t('chorestrike', 'Everyone') }}
				</button>
			</div>
		</header>

		<div v-if="loading" class="loading-state">
			{{ t('chorestrike', 'Loading your day…') }}
		</div>

		<div v-else-if="error" class="error-state" data-testid="today-error">
			<p>{{ t('chorestrike', 'Could not load your tasks') }}</p>
			<button class="primary-button" data-testid="today-retry" @click="load">
				{{ t('chorestrike', 'Try again') }}
			</button>
		</div>

		<template v-else>
			<section v-if="filteredOverdueItems.length > 0" class="today-section overdue">
				<h2 data-testid="today-section-overdue">
					<Icon name="AlertCircle" :size="18" class="section-icon" />
					{{ t('chorestrike', 'Overdue') }}
				</h2>
				<div class="task-rows">
					<div
						v-for="item in filteredOverdueItems"
						:key="item.roomTask.id"
						class="task-row overdue"
						:class="{ completed: !item.roomTask.isActive }"
						data-testid="today-task-item">
						<button
							v-if="item.roomTask.isActive"
							class="complete-button"
							:data-testid="`today-complete-button-${item.roomTask.id}`"
							:disabled="completingIds.has(item.roomTask.id)"
							:title="t('chorestrike', 'Mark as done')"
							@click="complete(item)">
							<Icon name="CheckCircle" :size="22" />
							<span>{{ t('chorestrike', 'Done') }}</span>
						</button>
						<span v-else class="completed-check" :data-testid="`today-completed-check-${item.roomTask.id}`">
							<Icon name="CheckCircle" :size="22" />
						</span>

						<div class="task-info">
							<span class="task-name">{{ item.task.name }}</span>
							<span class="task-room">{{ item.room?.name || t('chorestrike', 'Room') }}</span>
						</div>

						<span class="task-energy">
							<Icon name="Zap" :size="16" />
							{{ item.task.energyCost ?? '?' }}
						</span>
					</div>
				</div>
			</section>

			<section class="today-section">
				<h2 data-testid="today-section-today">
					<Icon name="Sun" :size="18" class="section-icon" />
					{{ t('chorestrike', 'Due Today') }}
				</h2>
				<div v-if="filteredTodayItems.length > 0" class="task-rows">
					<div
						v-for="item in filteredTodayItems"
						:key="item.roomTask.id"
						class="task-row"
						:class="{ completed: !item.roomTask.isActive }"
						data-testid="today-task-item">
						<button
							v-if="item.roomTask.isActive"
							class="complete-button"
							:data-testid="`today-complete-button-${item.roomTask.id}`"
							:disabled="completingIds.has(item.roomTask.id)"
							:title="t('chorestrike', 'Mark as done')"
							@click="complete(item)">
							<Icon name="CheckCircle" :size="22" />
							<span>{{ t('chorestrike', 'Done') }}</span>
						</button>
						<span v-else class="completed-check" :data-testid="`today-completed-check-${item.roomTask.id}`">
							<Icon name="CheckCircle" :size="22" />
						</span>

						<div class="task-info">
							<span class="task-name">{{ item.task.name }}</span>
							<span class="task-room">{{ item.room?.name || t('chorestrike', 'Room') }}</span>
						</div>

						<span class="task-energy">
							<Icon name="Zap" :size="16" />
							{{ item.task.energyCost ?? '?' }}
						</span>
					</div>
				</div>
				<p v-else class="section-empty">
					{{ t('chorestrike', 'Nothing due today') }}
				</p>
			</section>

			<div v-if="isEmpty" class="empty-state" data-testid="today-empty-state">
				<Icon name="Sun" :size="48" />
				<p>{{ t('chorestrike', 'All done — enjoy your day!') }}</p>
			</div>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { useRoomTasksStore } from '../store/roomTasks.js'
import { useRoomsStore } from '../store/rooms.js'
import { useTasksStore } from '../store/tasks.js'
import { useUserStore } from '../store/user.js'
import { logger } from '../utils/logger.js'
import Icon from '../components/Common/Icon.vue'

export default {
	name: 'TodayView',

	components: {
		Icon,
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
			error: false,
			filter: 'mine',
			todayItems: [],
			overdueItems: [],
			completingIds: new Set(),
			uncompletedHandler: null,
		}
	},

	computed: {
		myUserId() {
			return this.userStore.currentUser?.nextcloudUserId || null
		},

		isEmpty() {
			return this.filteredTodayItems.length === 0 && this.filteredOverdueItems.length === 0
		},

		filteredTodayItems() {
			if (this.filter === 'all') return this.todayItems
			return this.todayItems.filter(item => this.isMine(item))
		},

		filteredOverdueItems() {
			if (this.filter === 'all') return this.overdueItems
			return this.overdueItems.filter(item => this.isMine(item))
		},
	},

	async mounted() {
		// The Dashboard's Overdue card links in with ?scope=all so nothing
		// due-related is hidden from the caller (D3 default stays "mine").
		if (this.$route?.query?.scope === 'all') {
			this.filter = 'all'
		}
		// Undo snackbar (CS-PLAN-041): restore local rows of undone tasks.
		// Rows already removed from the list (recurring task whose next
		// occurrence is in the future) stay removed — the server keeps the
		// advanced nextDue, so re-adding would show a not-due task.
		this.uncompletedHandler = ({ roomTask }) => {
			if (!roomTask?.id) return
			const apply = list => list.forEach(item => {
				if (item.roomTask.id === roomTask.id) item.roomTask = { ...roomTask }
			})
			apply(this.todayItems)
			apply(this.overdueItems)
		}
		subscribe('chorestrike:task-uncompleted', this.uncompletedHandler)
		await this.load()
	},

	beforeUnmount() {
		if (this.uncompletedHandler) {
			unsubscribe('chorestrike:task-uncompleted', this.uncompletedHandler)
		}
	},

	methods: {
		t,

		isMine(item) {
			// Unknown user (store not resolved yet): show everything rather
			// than an empty list.
			if (!this.myUserId) return true
			const assignees = item.roomTask.assignees
				?? (item.roomTask.assignedToUserId ? [item.roomTask.assignedToUserId] : [])
			return assignees.length === 0 || assignees.includes(this.myUserId)
		},

		async load() {
			this.loading = true
			this.error = false
			try {
				const [daily, overdue] = await Promise.all([
					this.roomTasksStore.fetchFilteredDailyTasks(),
					this.roomTasksStore.fetchOverdueTasks(),
				])

				if (this.tasksStore.tasks.length === 0) {
					await this.tasksStore.fetchTasks()
				}
				if (this.roomsStore.rooms.length === 0) {
					await this.roomsStore.fetchFloorsWithRooms()
				}

				const todayIds = new Set(daily.map(item => item.roomTask.id))
				this.todayItems = daily
					.filter(item => item.roomTask.isActive)
					.map(item => ({ ...item }))
				this.overdueItems = overdue
					.filter(roomTask => roomTask.isActive && !todayIds.has(roomTask.id))
					.map(roomTask => ({
						roomTask: { ...roomTask },
						task: this.tasksStore.getTaskById(roomTask.taskId) || {
							id: roomTask.taskId,
							name: this.t('chorestrike', 'Unknown Task'),
							energyCost: 0,
						},
						room: this.roomsStore.getRoomById(roomTask.roomId) || null,
					}))

				// Per-item assignee lists make the "My tasks" filter
				// multi-assign-aware (the list endpoints don't embed them).
				// Bounded by the due-today/overdue count; failures fall back
				// to the legacy single assignee.
				const all = [...this.todayItems, ...this.overdueItems]
				await Promise.allSettled(all.map(async (item) => {
					item.roomTask.assignees = await this.roomTasksStore.fetchAssignees(item.roomTask.id)
				}))
			} catch (error) {
				this.error = true
				logger.error('Failed to load today view', error, { view: 'TodayView', action: 'load' })
			} finally {
				this.loading = false
			}
		},

		async complete(item) {
			const roomTaskId = item.roomTask.id
			this.completingIds = new Set(this.completingIds).add(roomTaskId)
			try {
				const data = await this.roomTasksStore.completeRoomTask(roomTaskId)
				const updated = data?.roomTask || { ...item.roomTask, isActive: false }
				if (updated.isActive && this.isDueToday(updated)) {
					// Recurring task with another occurrence still due today
					item.roomTask = updated
					// An overdue task whose next occurrence is today now
					// belongs to the Today section, not the overdue one.
					if (this.overdueItems.includes(item)) {
						this.overdueItems = this.overdueItems.filter(listItem => listItem !== item)
						this.todayItems = [...this.todayItems, item]
					}
				} else if (!updated.isActive) {
					// One-time task: keep the row with its completed state
					item.roomTask = updated
				} else {
					// Recurring task: the next due date moved into the
					// future — the task is done for today and leaves the list.
					this.todayItems = this.todayItems.filter(listItem => listItem.roomTask.id !== roomTaskId)
					this.overdueItems = this.overdueItems.filter(listItem => listItem.roomTask.id !== roomTaskId)
				}
			} catch (error) {
				showError(t('chorestrike', 'Could not complete the task'))
				logger.error('Failed to complete task', error, { view: 'TodayView', action: 'complete', roomTaskId })
			} finally {
				const next = new Set(this.completingIds)
				next.delete(roomTaskId)
				this.completingIds = next
			}
		},

		isDueToday(roomTask) {
			if (!roomTask?.nextDue) return false
			return new Date(roomTask.nextDue).toDateString() === new Date().toDateString()
		},
	},
}
</script>

<style scoped>
.today-view {
	padding: 1rem 2rem 2rem;
	max-width: 900px;
	margin: 0 auto;
}

.today-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 1.5rem;
	flex-wrap: wrap;
	gap: 1rem;
}

.today-header h1 {
	font-size: 2rem;
	font-weight: bold;
	margin: 0;
}

.filter-toggle {
	display: flex;
	gap: 0.25rem;
	padding: 0.25rem;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	background: var(--color-background-dark);
}

.filter-button {
	min-height: 44px;
	padding: 0.5rem 1rem;
	border: none;
	border-radius: 8px;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	font-size: 0.9rem;
	font-weight: 500;
}

.filter-button.active {
	background: var(--color-primary);
	color: var(--color-primary-text);
}

.today-section {
	margin-bottom: 2rem;
}

.today-section h2 {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	font-size: 1.2rem;
	margin: 0 0 1rem 0;
}

.today-section.overdue h2 {
	color: var(--color-error);
}

.section-icon {
	color: currentColor;
}

.task-rows {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.task-row {
	display: flex;
	align-items: center;
	gap: 1rem;
	padding: 0.75rem 1rem;
	background: var(--color-background-dark);
	border: 2px solid transparent;
	border-radius: 12px;
}

.task-row.overdue {
	border-color: var(--color-error);
}

.task-row.completed {
	opacity: 0.6;
}

.complete-button {
	display: flex;
	align-items: center;
	gap: 0.4rem;
	min-width: 44px;
	min-height: 44px;
	padding: 0.5rem 0.9rem;
	border: 2px solid var(--color-primary);
	border-radius: 22px;
	background: transparent;
	color: var(--color-primary);
	cursor: pointer;
	font-size: 1rem;
	font-weight: 600;
	transition: background-color 0.2s, color 0.2s;
}

.complete-button:hover:not(:disabled) {
	background: var(--color-primary);
	color: var(--color-primary-text);
}

.complete-button:disabled {
	opacity: 0.5;
	cursor: wait;
}

.completed-check {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 44px;
	height: 44px;
	color: var(--color-success);
}

.task-info {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 0.15rem;
	min-width: 0;
}

.task-name {
	font-size: 1.05rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.task-room {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.task-energy {
	display: flex;
	align-items: center;
	gap: 0.3rem;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.section-empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.loading-state,
.error-state {
	text-align: center;
	padding: 3rem 1rem;
	color: var(--color-text-maxcontrast);
}

.error-state .primary-button {
	margin-top: 1rem;
	min-height: 44px;
	padding: 0.6rem 1.2rem;
	border: none;
	border-radius: 8px;
	background: var(--color-primary);
	color: var(--color-primary-text);
	cursor: pointer;
}

.empty-state {
	text-align: center;
	padding: 3rem 1rem;
	color: var(--color-text-maxcontrast);
}

.empty-state p {
	margin: 0.5rem 0 0 0;
	font-size: 1.1rem;
}

@media (max-width: 600px) {
	.today-view {
		padding: 1rem;
	}

	.task-row {
		flex-wrap: wrap;
	}
}
</style>
