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

		<div v-else-if="error" data-testid="today-error">
			<ErrorState
				:message="t('chorestrike', 'Could not load your tasks')"
				@retry="load" />
		</div>

		<template v-else>
			<!-- Partial derived refresh (CS-PLAN-066 D3): a committed action
			     stays successful; stale projections surface a read-only
			     retry — never a write replay. -->
			<div
				v-if="roomTasksStore.refreshFailed"
				class="refresh-banner"
				data-testid="today-refresh-failed"
				role="status">
				<span>{{ t('chorestrike', 'Some views could not be refreshed') }}</span>
				<button
					class="refresh-retry"
					:disabled="roomTasksStore.countsRefreshing"
					@click="retryDerivedRefresh">
					{{ t('chorestrike', 'Refresh') }}
				</button>
			</div>

			<section v-if="visibleOverdueItems.length > 0" class="today-section overdue">
				<h2 data-testid="today-section-overdue">
					<Icon name="AlertCircle" :size="18" class="section-icon" />
					{{ t('chorestrike', 'Overdue') }}
				</h2>
				<div class="task-rows">
					<div v-for="item in visibleOverdueItems" :key="item.roomTask.id" class="today-row-group">
						<div
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
								<p v-if="taskInstructions(item)" class="task-instructions">
									{{ taskInstructions(item) }}
								</p>
							</div>

							<button
								v-if="!hasDiscoveredEmptyChecklist(item.task.id)"
								class="checklist-toggle"
								:aria-expanded="isExpanded(item.roomTask.id)"
								:data-testid="`today-checklist-toggle-${item.roomTask.id}`"
								@click="toggleExpand(item)">
								<Icon :name="isExpanded(item.roomTask.id) ? 'ChevronUp' : 'ChevronDown'" :size="16" />
								<span>{{ checklistSummary(item) }}</span>
							</button>
						</div>

						<TaskChecklist
							v-if="isExpanded(item.roomTask.id)"
							:key="`checklist-${item.roomTask.id}`"
							class="today-checklist"
							:items="checklistStore.getTaskItems(item.task.id)"
							:progress="checklistStore.getRoomTaskProgress(item.roomTask.id)"
							:can-edit="false"
							:readonly="!item.roomTask.isActive"
							:room-task-id="item.roomTask.id"
							:task-id="item.task.id"
							@toggle="handleToggleChecklistItem" />
					</div>
				</div>
			</section>

			<section class="today-section">
				<h2 data-testid="today-section-today">
					<Icon name="Sun" :size="18" class="section-icon" />
					{{ t('chorestrike', 'Due Today') }}
				</h2>
				<div v-if="visibleTodayItems.length > 0" class="task-rows">
					<div v-for="item in visibleTodayItems" :key="item.roomTask.id" class="today-row-group">
						<div
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
								<p v-if="taskInstructions(item)" class="task-instructions">
									{{ taskInstructions(item) }}
								</p>
							</div>

							<button
								v-if="!hasDiscoveredEmptyChecklist(item.task.id)"
								class="checklist-toggle"
								:aria-expanded="isExpanded(item.roomTask.id)"
								:data-testid="`today-checklist-toggle-${item.roomTask.id}`"
								@click="toggleExpand(item)">
								<Icon :name="isExpanded(item.roomTask.id) ? 'ChevronUp' : 'ChevronDown'" :size="16" />
								<span>{{ checklistSummary(item) }}</span>
							</button>
						</div>

						<TaskChecklist
							v-if="isExpanded(item.roomTask.id)"
							:key="`checklist-${item.roomTask.id}`"
							class="today-checklist"
							:items="checklistStore.getTaskItems(item.task.id)"
							:progress="checklistStore.getRoomTaskProgress(item.roomTask.id)"
							:can-edit="false"
							:readonly="!item.roomTask.isActive"
							:room-task-id="item.roomTask.id"
							:task-id="item.task.id"
							@toggle="handleToggleChecklistItem" />
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
import { useHouseStore } from '../store/house.js'
import { useChecklistStore } from '../store/checklist.js'
import { useUserStore } from '../store/user.js'
import { logger } from '../utils/logger.js'
import { dateKeyInZone, todayKeyInZone } from '../utils/dateKeys.js'
import Icon from '../components/Common/Icon.vue'
import ErrorState from '../components/Common/ErrorState.vue'
import TaskChecklist from '../components/Tasks/TaskChecklist.vue'

export default {
	name: 'TodayView',

	components: {
		Icon,
		ErrorState,
		TaskChecklist,
	},

	setup() {
		const roomTasksStore = useRoomTasksStore()
		const houseStore = useHouseStore()
		const checklistStore = useChecklistStore()
		const userStore = useUserStore()
		return { roomTasksStore, houseStore, checklistStore, userStore }
	},

	data() {
		return {
			loading: false,
			error: false,
			filter: 'mine',
			completingIds: new Set(),
			expandedIds: [],
			// Definitions whose checklist was discovered to be EMPTY: the
			// toggle is hidden for them after the one-shot discovery so a
			// checklist-less row never offers a dead control (manual a11y
			// pass 2026-09-12).
			emptyChecklistTaskIds: [],
			uncompletedHandler: null,
		}
	},

	computed: {
		myUserId() {
			return this.userStore.currentUser?.nextcloudUserId || null
		},

		// CS-PLAN-066 §3.3: the rows are SELECTORS of their owning store
		// slices — no view-local copies that a stale read could overwrite.
		// Rows are the enriched `{roomTask, task, room, assignees}` payloads
		// (D1), so no per-row store lookups remain. The WP6 meaning of My
		// Day is preserved: server visibility/eligibility/child limits stay
		// authoritative — this view only applies the house-local day filter
		// and the local mine/everyone preference.
		todayIds() {
			return new Set(this.roomTasksStore.dailyTasks
				.filter(item => item.roomTask.isActive && this.isDueToday(item.roomTask))
				.map(item => item.roomTask.id))
		},

		todayItems() {
			// Completed rows stay visible (queued undo), a recurring task
			// whose next occurrence moved past today leaves the list.
			return this.roomTasksStore.dailyTasks
				.filter(item => !item.roomTask.isActive || this.isDueToday(item.roomTask))
		},

		overdueItems() {
			return this.roomTasksStore.overdueTasks
				.filter(item => item.roomTask.isActive && !this.todayIds.has(item.roomTask.id))
		},

		visibleTodayItems() {
			if (this.filter === 'all') return this.todayItems
			return this.todayItems.filter(item => this.isMine(item))
		},

		visibleOverdueItems() {
			if (this.filter === 'all') return this.overdueItems
			return this.overdueItems.filter(item => this.isMine(item))
		},

		isEmpty() {
			return this.visibleTodayItems.length === 0 && this.visibleOverdueItems.length === 0
		},
	},

	async mounted() {
		// The Dashboard's Overdue card links in with ?scope=all so nothing
		// due-related is hidden from the caller (D3 default stays "mine").
		if (this.$route?.query?.scope === 'all') {
			this.filter = 'all'
		}
		// CS-PLAN-065 §3.4: a committed owner plan may have moved today's or
		// overdue rows — refresh from the reconciled stores.
		this.scheduleAppliedHandler = () => {
			this.load()
		}
		subscribe('chorestrike:schedule-applied', this.scheduleAppliedHandler)
		await this.load()
	},

	beforeUnmount() {
		if (this.scheduleAppliedHandler) {
			unsubscribe('chorestrike:schedule-applied', this.scheduleAppliedHandler)
		}
	},

	methods: {
		t,

		isMine(item) {
			// Unknown user (store not resolved yet): show everything rather
			// than an empty list. Canonical assignees ride the enriched row
			// (D1) — top-level on the payload.
			if (!this.myUserId) return true
			const assignees = item.assignees ?? item.roomTask.assignees ?? []
			return assignees.length === 0 || assignees.includes(this.myUserId)
		},

		async load() {
			this.loading = true
			this.error = false
			try {
				// Rows are enriched server-side (CS-PLAN-066 D1) — the daily
				// and overdue reads carry task, room and canonical assignees.
				await Promise.all([
					this.roomTasksStore.fetchFilteredDailyTasks(),
					this.roomTasksStore.fetchOverdueTasks(),
				])
				this.roomTasksStore.fetchDueTodayTasks().catch(() => {
					// Count projections refresh opportunistically; the view
					// rows do not depend on them.
				})
			} catch (error) {
				this.error = true
				logger.error('Failed to load today view', error, { view: 'TodayView', action: 'load' })
			} finally {
				this.loading = false
			}
		},

		async retryDerivedRefresh() {
			// Read-only retry (D3): refetch the stale projections, never
			// replay any committed write.
			try {
				await this.roomTasksStore.refreshDerivedProjections()
			} catch (error) {
				logger.error('Failed to refresh derived projections', error, { view: 'TodayView', action: 'retryDerivedRefresh' })
			}
		},

		async complete(item) {
			const roomTaskId = item.roomTask.id
			this.completingIds = new Set(this.completingIds).add(roomTaskId)
			try {
				await this.roomTasksStore.completeRoomTask(roomTaskId)
				// The store reconciled every owning slice from this payload
				// (D10) and refreshed the count projections (D3); this view's
				// computed selectors re-derive their rows reactively.
				if (this.isExpanded(roomTaskId)) {
					// Occurrence-scoped server reset (CS-PLAN-064 D6): refresh
					// the checklist progress after the completion.
					this.checklistStore.fetchRoomTaskProgress(roomTaskId).catch(() => {
						// Optional read — the next expand re-fetches anyway.
					})
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

		/**
		 * Short, plain household instructions for the row (CS-PLAN-066
		 * §3.5): the definition's instructions, falling back to the
		 * description. Rendered as escaped text — never v-html.
		 * @param {object} item Enriched daily/overdue row.
		 * @return {string} The instruction text (may be empty).
		 */
		taskInstructions(item) {
			return item.task.instructions || item.task.description || ''
		},

		checklistSummary(item) {
			const items = this.checklistStore.getTaskItems(item.task.id)
			if (items.length === 0) {
				return this.t('chorestrike', 'Checklist')
			}
			const progress = this.checklistStore.getRoomTaskProgress(item.roomTask.id)
			const done = progress.filter(p => p.isChecked).length
			return this.t('chorestrike', 'Checklist ({done}/{total})', { done, total: items.length })
		},

		async toggleExpand(item) {
			const id = item.roomTask.id
			if (this.isExpanded(id)) {
				this.expandedIds = this.expandedIds.filter(expandedId => expandedId !== id)
				return
			}
			this.expandedIds = [...this.expandedIds, id]
			try {
				await Promise.all([
					this.checklistStore.fetchTaskItems(item.task.id),
					this.checklistStore.fetchRoomTaskProgress(id),
				])
				// Tasks without a checklist collapse again instead of showing
				// an empty sub-task box — and the toggle disappears for that
				// definition (one-shot discovery, manual a11y pass
				// 2026-09-12): a checklist-less row must not keep offering a
				// dead clickable control.
				if (this.checklistStore.getTaskItems(item.task.id).length === 0) {
					this.expandedIds = this.expandedIds.filter(expandedId => expandedId !== id)
					if (!this.hasDiscoveredEmptyChecklist(item.task.id)) {
						this.emptyChecklistTaskIds = [...this.emptyChecklistTaskIds, item.task.id]
					}
				}
			} catch (error) {
				showError(t('chorestrike', 'Could not load the checklist'))
				logger.error('Failed to load checklist', error, { view: 'TodayView', action: 'toggleExpand', roomTaskId: id })
			}
		},

		isExpanded(roomTaskId) {
			return this.expandedIds.includes(roomTaskId)
		},

		hasDiscoveredEmptyChecklist(taskId) {
			return this.emptyChecklistTaskIds.includes(taskId)
		},

		async handleToggleChecklistItem({ itemId, roomTaskId }) {
			try {
				await this.checklistStore.toggleItem(roomTaskId, itemId)
			} catch (error) {
				showError(t('chorestrike', 'Could not update the checklist'))
				logger.error('Failed to toggle checklist item', error, { view: 'TodayView', action: 'handleToggleChecklistItem', roomTaskId, itemId })
			}
		},

		isDueToday(roomTask) {
			if (!roomTask?.nextDue) return false
			return dateKeyInZone(roomTask.nextDue, this.houseStore.timezone)
				=== todayKeyInZone(this.houseStore.timezone)
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

.refresh-banner {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 0.75rem;
	padding: 0.6rem 1rem;
	margin-bottom: 1rem;
	border: 1px solid var(--color-warning);
	border-radius: 8px;
	background: var(--color-background-dark);
}

.refresh-retry {
	min-height: 44px;
	padding: 0.4rem 1rem;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: transparent;
	cursor: pointer;
	font-weight: 600;
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

.checklist-toggle {
	display: flex;
	align-items: center;
	gap: 0.35rem;
	min-height: 44px;
	padding: 0.4rem 0.8rem;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	font-size: 0.85rem;
	font-weight: 500;
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

/* Plain escaped user content — short instructions for the whole family. */
.task-instructions {
	margin: 0.25rem 0 0 0;
	font-size: 0.9rem;
	line-height: 1.4;
	color: var(--color-main-text);
	white-space: pre-line;
}

.today-checklist {
	margin-top: -0.25rem;
}

.section-empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.loading-state {
	text-align: center;
	padding: 3rem 1rem;
	color: var(--color-text-maxcontrast);
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
