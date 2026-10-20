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
			<!-- Essential onboarding slice (WP8 §3.6): windows, rhythm,
			     neutral days and the contribution-vs-Coins line. -->
			<HelpEntry variant="context" topic="progress" />
			<div class="filter-toggle" role="group" :aria-label="t('chorestrike', 'Task filter')">
				<button
					class="filter-button"
					:class="{ active: filter === 'mine' }"
					:aria-pressed="filter === 'mine'"
					data-testid="today-filter-mine"
					@click="filter = 'mine'">
					{{ t('chorestrike', 'My tasks') }}
				</button>
				<button
					class="filter-button"
					:class="{ active: filter === 'all' }"
					:aria-pressed="filter === 'all'"
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
			<ErrorState
				v-if="membersError"
				data-testid="member-names-error"
				:message="t('chorestrike', 'Failed to load members')"
				@retry="loadMembers" />
			<!-- Calm progress line (C5f, C1e/C1f): the small confirmed
			     streak plus the shared weekly-goal progress. Provisional
			     while today's window is open; neutral wording, no Coins,
			     no raw-activity metrics. -->
			<div
				v-if="hasProgress"
				class="progress-line"
				data-testid="today-progress-line"
				role="status">
				<span class="progress-item" data-testid="today-streak">
					<Icon name="Flame" :size="16" class="progress-icon" />
					{{ confirmedStreakLabel }}
				</span>
				<span class="progress-item" data-testid="today-weekly-goal">
					<Icon name="Target" :size="16" class="progress-icon" />
					{{ t('chorestrike', 'Shared weekly goal: {percent}% of {target}%', {
						percent: weeklyGoal.percent,
						target: weeklyGoal.targetPercent,
					}) }}
				</span>
			</div>

			<!-- Overload clarification (C5f): neutral, amounts visible,
			     invites the owner to replan — never a threat. -->
			<div
				v-if="overload"
				class="overload-banner"
				data-testid="today-overload"
				role="status">
				<Icon name="AlertTriangle" :size="18" class="overload-icon" />
				<p class="overload-text">
					{{ t('chorestrike', '{total} of {capacity} daily capacity is planned or still outstanding. It is too much for one day — the house owner can replan it.', overload) }}
				</p>
			</div>

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

			<!-- Catch-up (C5f, C1c/C1d): ONE card per chore with an
			     unfulfilled miss. The attributed planned date and window
			     stay visible; completing the shown occurrence covers the
			     miss without relabeling it as on time. -->
			<section v-if="visibleCatchUpItems.length > 0" class="today-section catchup" data-testid="today-section-catchup">
				<h2>
					<Icon name="Undo" :size="18" class="section-icon" />
					{{ t('chorestrike', 'Catch-up') }}
				</h2>
				<div class="task-rows">
					<div v-for="item in visibleCatchUpItems" :key="item.roomTask.id" class="today-row-group">
						<div
							class="task-row catchup"
							:class="{ completed: !item.roomTask.isActive }"
							data-testid="today-task-item">
							<HoldButton
								v-if="item.roomTask.isActive"
								:label="t('chorestrike', 'Done')"
								:icon-size="22"
								:busy="roomTasksStore.isCompleting(item.roomTask.id)"
								:data-testid="`today-complete-button-${item.roomTask.id}`"
								:title="t('chorestrike', 'Mark as done')"
								@confirm="complete(item)" />
							<span v-else class="completed-check" :data-testid="`today-completed-check-${item.roomTask.id}`">
								<Icon name="CheckCircle" :size="22" />
							</span>

							<div class="task-info">
								<span class="task-name">{{ item.task.name }}</span>
								<span class="task-room">{{ item.room?.name || t('chorestrike', 'Room') }}</span>
								<span class="task-assignees">{{ assigneeLabel(item) }}</span>
								<p v-if="catchUpDateLabel(item)" class="task-catchup-meta" data-testid="catchup-planned">
									{{ catchUpDateLabel(item) }}
								</p>
								<p class="task-catchup-hint">
									{{ t('chorestrike', 'Completing it now covers the miss — it stays a catch-up.') }}
								</p>
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
							<HoldButton
								v-if="item.roomTask.isActive"
								:label="t('chorestrike', 'Done')"
								:icon-size="22"
								:busy="roomTasksStore.isCompleting(item.roomTask.id)"
								:data-testid="`today-complete-button-${item.roomTask.id}`"
								:title="t('chorestrike', 'Mark as done')"
								@confirm="complete(item)" />
							<span v-else class="completed-check" :data-testid="`today-completed-check-${item.roomTask.id}`">
								<Icon name="CheckCircle" :size="22" />
							</span>

							<div class="task-info">
								<span class="task-name">{{ item.task.name }}</span>
								<span class="task-room">{{ item.room?.name || t('chorestrike', 'Room') }}</span>
								<span class="task-assignees">{{ assigneeLabel(item) }}</span>
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

			<!-- Next appointments (C5f): upcoming AGREED occurrences from
			     the delivered range read with room navigation, no unsolicited
			     backlog, no optional work. -->
			<section v-if="nextAppointments.length > 0" class="today-section appointments" data-testid="today-section-appointments">
				<h2>
					<Icon name="Calendar" :size="18" class="section-icon" />
					{{ t('chorestrike', 'Next appointments') }}
				</h2>
				<ul class="appointment-list">
					<li
						v-for="appointment in nextAppointments"
						:key="`${appointment.occurrenceId}`"
						data-testid="today-appointment">
						<RouterLink
							class="appointment-row"
							:to="{ name: 'room', params: { id: appointment.roomId } }">
							<span class="appointment-date">{{ appointment.dateLabel }}</span>
							<span class="appointment-name">{{ appointment.task?.name }}</span>
							<span class="appointment-room">{{ appointment.room?.name || t('chorestrike', 'Room') }}</span>
							<span class="appointment-action">{{ t('chorestrike', 'Open room') }}</span>
							<Icon name="ChevronRight" :size="18" />
						</RouterLink>
					</li>
				</ul>
			</section>

			<!-- Free day (C5f): a NEUTRAL empty state — no engagement
			     prompt, no pressure, no celebration promise. -->
			<div v-if="isEmpty" class="empty-state" data-testid="today-empty-state">
				<Icon name="Sun" :size="48" />
				<p>{{ t('chorestrike', 'Nothing is planned for today.') }}</p>
			</div>
		</template>

		<ParticipationDialog
			v-if="participation"
			:members="participationMembers"
			:proposal="participation.item.proposal ?? null"
			:actor-user-id="userStore.currentUser?.nextcloudUserId ?? null"
			@close="participation = null"
			@confirm="confirmParticipation" />
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import ErrorState from '../components/Common/ErrorState.vue'
import HoldButton from '../components/Common/HoldButton.vue'
import Icon from '../components/Common/Icon.vue'
import ParticipationDialog from '../components/Common/ParticipationDialog.vue'
import HelpEntry from '../components/Help/HelpEntry.vue'
import TaskChecklist from '../components/Tasks/TaskChecklist.vue'
import { useHouseMemberNames } from '../composables/useHouseMemberNames.js'
import { useChecklistStore } from '../store/checklist.js'
import { useGameStore } from '../store/game.js'
import { useHouseStore } from '../store/house.js'
import { useRoomTasksStore } from '../store/roomTasks.js'
import { useSchedulingStore } from '../store/scheduling.js'
import { useUserStore } from '../store/user.js'
import { addDaysToKey, dateKeyInZone, todayKeyInZone, weekdayOfKey } from '../utils/dateKeys.js'
import { showCompletionError, showErrorWithReference } from '../utils/errorToast.js'
import { logger } from '../utils/logger.js'
import { assignmentLabel } from '../utils/taskDisplay.js'

const APPOINTMENT_LIMIT = 5

export default {
	name: 'TodayView',

	components: {
		Icon,
		ErrorState,
		TaskChecklist,
		ParticipationDialog,
		HoldButton,
		HelpEntry,
	},

	setup() {
		const roomTasksStore = useRoomTasksStore()
		const houseStore = useHouseStore()
		const checklistStore = useChecklistStore()
		const userStore = useUserStore()
		const gameStore = useGameStore()
		const schedulingStore = useSchedulingStore()
		return { roomTasksStore, houseStore, checklistStore, userStore, gameStore, schedulingStore, ...useHouseMemberNames(userStore) }
	},

	data() {
		return {
			loading: false,
			error: false,
			filter: 'mine',
			expandedIds: [],
			// WP3 §3.6: the multi-person completion dialog target
			// (`{item}`) while open; solo work bypasses the dialog.
			participation: null,
			// Definitions whose checklist was discovered to be EMPTY: the
			// toggle is hidden for them after the one-shot discovery so a
			// checklist-less row never offers a dead control (manual a11y
			// pass 2026-09-12).
			emptyChecklistTaskIds: [],
		}
	},

	computed: {
		myUserId() {
			return this.userStore.currentUser?.nextcloudUserId || null
		},

		/**
		 * Candidate members for the open participation dialog (WP3 §3.6):
		 * the canonical assignee set of the completing task, falling back
		 * to the full house membership for unassigned work.
		 * @return {Array<{userId: string, displayName: string}>}
		 */
		participationMembers() {
			const item = this.participation?.item
			if (!item) {
				return []
			}
			const namesByUser = {}
			;(this.userStore.houseUsers ?? []).forEach((member) => {
				namesByUser[member.nextcloudUserId] = member.displayName
			})
			const assignees = item.assignees ?? []
			if (assignees.length > 0) {
				return assignees.map((userId) => ({ userId, displayName: namesByUser[userId] ?? userId }))
			}
			return Object.keys(namesByUser).map((userId) => ({ userId, displayName: namesByUser[userId] }))
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
				.filter((item) => item.roomTask.isActive && this.isDueToday(item.roomTask))
				.map((item) => item.roomTask.id))
		},

		todayItems() {
			// Completed rows stay visible (queued undo), a recurring task
			// whose next occurrence moved past today leaves the list.
			return this.roomTasksStore.dailyTasks
				.filter((item) => !item.roomTask.isActive || this.isDueToday(item.roomTask))
		},

		overdueItems() {
			return this.roomTasksStore.overdueTasks
				.filter((item) => item.roomTask.isActive && !this.todayIds.has(item.roomTask.id))
		},

		visibleTodayItems() {
			if (this.filter === 'all') {
				return this.todayItems
			}
			return this.todayItems.filter((item) => this.isMine(item))
		},

		visibleCatchUpItems() {
			if (this.filter === 'all') {
				return this.overdueItems
			}
			return this.overdueItems.filter((item) => this.isMine(item))
		},

		isEmpty() {
			return this.visibleTodayItems.length === 0 && this.visibleCatchUpItems.length === 0
		},

		/**
		 * Calm progress line (C1e/C1f): the server-evaluated confirmed
		 * streak and the shared weekly-goal snapshot. Hidden entirely when
		 * neither exists (fresh households) — never a zero-pressure prompt.
		 */
		hasProgress() {
			return this.gameStore.gameState !== null
		},

		confirmedStreakLabel() {
			const confirmed = this.gameStore.rhythm.confirmed
			if (this.gameStore.rhythm.pending) {
				return t('chorestrike', '{days} days confirmed — today pending', { days: confirmed })
			}
			return t('chorestrike', '{days} days confirmed', { days: confirmed })
		},

		weeklyGoal() {
			return this.gameStore.weeklyGoal
		},

		/**
		 * Overload clarification (C5f): planned + outstanding work exceeds
		 * the member's capacity for today — the capacity facts come from
		 * the delivered scheduling read surface, the amounts stay visible
		 * and the invitation targets the owner replan, never the member.
		 * Pure presentation aggregation of server facts — the capacity
		 * policy itself stays server-side.
		 */
		overload() {
			const capacity = this.schedulingStore.getCapacityForDay(weekdayOfKey(todayKeyInZone(this.houseStore.timezone)))
			if (!Number.isFinite(capacity)) {
				return null
			}
			const planned = this.visibleTodayItems
				.filter((item) => item.roomTask.isActive)
				.reduce((sum, item) => sum + (item.task?.energyCost ?? 0), 0)
			const outstanding = this.visibleCatchUpItems
				.reduce((sum, item) => sum + (item.task?.energyCost ?? 0), 0)
			const total = planned + outstanding
			if (total === 0 || total <= capacity) {
				return null
			}
			return { total, capacity }
		},

		/**
		 * Next appointments (C5f): the next AGREED, unfulfilled occurrences
		 * from the delivered range read (today onward). Projections and
		 * already-completed occurrences never render — no unsolicited
		 * backlog, no optional work.
		 */
		nextAppointments() {
			const todayKey = todayKeyInZone(this.houseStore.timezone)
			const appointments = []
			for (const item of this.roomTasksStore.rangeTasks.scheduled) {
				const details = Array.isArray(item.occurrenceDetails) ? item.occurrenceDetails : []
				for (const detail of details) {
					if (detail.classification !== 'agreed') {
						continue
					}
					if (detail.fulfilled === true) {
						continue
					}
					if (!detail.date || detail.date < todayKey) {
						continue
					}
					appointments.push({
						occurrenceId: detail.occurrenceId,
						task: item.task,
						room: item.room,
						roomId: item.roomTask.roomId,
						date: detail.date,
						dateLabel: this.formatDayKey(detail.date),
					})
				}
			}
			appointments.sort((a, b) => a.date.localeCompare(b.date))
			return appointments.slice(0, APPOINTMENT_LIMIT)
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
		assignmentLabel,

		assigneeLabel(item) {
			return this.assignmentLabel(
				item.proposal?.obligatedUserIds ?? item.assignees ?? item.roomTask.assignees,
				this.userStore.houseUsers,
			)
		},

		isMine(item) {
			// Unknown user (store not resolved yet): show everything rather
			// than an empty list. Canonical assignees ride the enriched row
			// (D1) — top-level on the payload.
			if (!this.myUserId) {
				return true
			}
			const obligated = item.proposal?.obligatedUserIds
			if (Array.isArray(obligated)) {
				return obligated.includes(this.myUserId)
			}
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
				this.loadSidePanels()
			} catch (error) {
				this.error = true
				logger.error('Failed to load today view', error, { view: 'TodayView', action: 'load' })
			} finally {
				this.loading = false
			}
		},

		/**
		 * The calm side panels (progress line, next appointments, overload
		 * facts) are OPTIONAL reads: a failure leaves the panel absent or
		 * stale — never an error state for the day's work.
		 */
		loadSidePanels() {
			const from = todayKeyInZone(this.houseStore.timezone)
			const to = addDaysToKey(from, 7)
			this.roomTasksStore.fetchRangeTasks(from, to).catch((error) => {
				logger.error('Failed to load the appointment window', error, { view: 'TodayView', action: 'loadSidePanels' })
			})
			this.gameStore.fetchGameState().catch((error) => {
				logger.error('Failed to load the progress snapshot', error, { view: 'TodayView', action: 'loadSidePanels' })
			})
			this.schedulingStore.fetchUserPreferences().catch((error) => {
				logger.error('Failed to load the capacity facts', error, { view: 'TodayView', action: 'loadSidePanels' })
			})
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
			// WP3 §3.6 (C2a, Q-074-02): a solo agreement naming the actor
			// completes directly; multi-person work (or an agreement naming
			// someone else) opens the participation dialog pre-filled with
			// the server proposal (the effective concrete agreement).
			if (this.needsParticipationDialog(item.proposal ?? null)) {
				await this.openParticipation({ item })
				return
			}
			await this.performComplete(item.roomTask.id)
		},

		/**
		 * Open the participation dialog with the actor identity resolved:
		 * the dialog needs the current user id AT CREATION (the actor
		 * joins a foreign split, Q-074-02). Member display names load in
		 * parallel and fall back to raw user ids.
		 * @param {{item: object}} target The dialog target.
		 */
		async openParticipation(target) {
			if (!this.userStore.currentUser) {
				await this.userStore.initializeCurrentUser().catch(() => {})
			}
			if ((this.userStore.houseUsers ?? []).length === 0) {
				this.userStore.fetchHouseUsers().catch(() => {})
			}
			this.participation = target
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

		async confirmParticipation(payload) {
			const item = this.participation?.item
			this.participation = null
			if (!item) {
				return
			}
			await this.performComplete(item.roomTask.id, payload)
		},

		async performComplete(roomTaskId, options = {}) {
			try {
				// Participant-less completion omits the options entirely —
				// the server credits the effective agreement.
				if (Object.keys(options).length > 0) {
					await this.roomTasksStore.completeRoomTask(roomTaskId, options)
				} else {
					await this.roomTasksStore.completeRoomTask(roomTaskId)
				}
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
				showCompletionError(error)
				logger.error('Failed to complete task', error, { view: 'TodayView', action: 'complete', roomTaskId })
			}
		},

		/**
		 * The attributed planned date and window of the catch-up card
		 * (C1c): straight from the occurrence block of the delivered
		 * overdue read — never recomputed client-side.
		 * @param {object} item Enriched overdue row.
		 * @return {string} The label (empty when no occurrence block).
		 */
		catchUpDateLabel(item) {
			const occurrence = item.occurrence ?? item.roomTask?.occurrence
			if (!occurrence?.plannedDate) {
				return ''
			}
			const windowLabel = occurrence.windowEnd
				? t('chorestrike', 'planned {date}, until {time}', {
						date: this.formatDayKey(occurrence.plannedDate),
						time: this.formatTime(occurrence.windowEnd),
					})
				: t('chorestrike', 'planned {date}', { date: this.formatDayKey(occurrence.plannedDate) })
			return windowLabel
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
			const done = progress.filter((p) => p.isChecked).length
			return this.t('chorestrike', 'Checklist ({done}/{total})', { done, total: items.length })
		},

		async toggleExpand(item) {
			const id = item.roomTask.id
			if (this.isExpanded(id)) {
				this.expandedIds = this.expandedIds.filter((expandedId) => expandedId !== id)
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
					this.expandedIds = this.expandedIds.filter((expandedId) => expandedId !== id)
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
				showErrorWithReference(t('chorestrike', 'Could not update the checklist'), error)
				logger.error('Failed to toggle checklist item', error, { view: 'TodayView', action: 'handleToggleChecklistItem', roomTaskId, itemId })
			}
		},

		isDueToday(roomTask) {
			if (!roomTask?.nextDue) {
				return false
			}
			return dateKeyInZone(roomTask.nextDue, this.houseStore.timezone)
				=== todayKeyInZone(this.houseStore.timezone)
		},

		formatDayKey(key) {
			if (!key) {
				return '—'
			}
			const [year, month, day] = key.split('-').map(Number)
			return new Date(year, month - 1, day).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })
		},

		formatTime(iso) {
			if (!iso) {
				return '—'
			}
			const date = new Date(iso)
			if (Number.isNaN(date.getTime())) {
				return '—'
			}
			return date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
		},
	},
}
</script>

<style scoped>
.today-view {
	padding: 1rem 2rem 2rem;
	padding-inline-start: var(--cs-view-inline-start);
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
	border: 1px solid var(--color-text-maxcontrast);
	border-radius: 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 0.9rem;
	font-weight: 500;
}

.filter-button.active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-color: var(--color-primary-element);
}

.progress-line {
	display: flex;
	align-items: center;
	gap: 1.5rem;
	flex-wrap: wrap;
	padding: 0.5rem 1rem;
	margin-bottom: 1rem;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.progress-item {
	display: inline-flex;
	align-items: center;
	gap: 0.4rem;
}

.progress-icon {
	color: var(--color-text-maxcontrast);
}

.overload-banner {
	display: flex;
	align-items: flex-start;
	gap: 0.75rem;
	padding: 0.75rem 1rem;
	margin-bottom: 1rem;
	border: 1px solid var(--color-border);
	border-inline-start: 3px solid var(--color-element-warning);
	border-radius: 10px;
	background: var(--color-background-dark);
}

.overload-icon {
	color: var(--color-warning-text);
	margin-top: 0.15rem;
}

.overload-text {
	margin: 0;
	color: var(--color-main-text);
	font-size: 0.9rem;
	line-height: 1.4;
}

.refresh-banner {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 0.75rem;
	padding: 0.6rem 1rem;
	margin-bottom: 1rem;
	border: 1px solid var(--color-element-warning);
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

.task-row.catchup {
	border-color: var(--color-border);
	border-inline-start: 3px solid var(--color-element-warning);
}

.task-row.completed {
	opacity: 0.6;
}

.completed-check {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 44px;
	height: 44px;
	color: var(--color-success-text);
}

.checklist-toggle {
	display: flex;
	align-items: center;
	gap: 0.35rem;
	min-height: 44px;
	padding: 0.4rem 0.8rem;
	border: 1px solid var(--color-text-maxcontrast);
	border-radius: 10px;
	background: var(--color-main-background);
	color: var(--color-main-text);
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

.task-assignees {
	font-size: 0.85rem;
	color: var(--color-main-text);
	overflow-wrap: anywhere;
}

.task-catchup-meta {
	margin: 0.25rem 0 0 0;
	font-size: 0.85rem;
	color: var(--color-main-text);
}

.task-catchup-hint {
	margin: 0;
	font-size: 0.8rem;
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

.appointment-list {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.appointment-row {
	display: flex;
	align-items: center;
	gap: 1rem;
	padding: 0.6rem 1rem;
	background: var(--color-background-dark);
	border-radius: 10px;
	font-size: 0.95rem;
	min-block-size: 44px;
	border: 1px solid var(--color-text-maxcontrast);
	color: var(--color-main-text);
}

.appointment-row:hover {
	background: var(--color-background-hover);
}

.appointment-action {
	text-decoration: underline;
	margin-inline-start: auto;
}

.appointment-row:focus-visible,
.filter-button:focus-visible,
.checklist-toggle:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.appointment-date {
	min-width: 7.5rem;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

.appointment-name {
	font-weight: 600;
	color: var(--color-main-text);
}

.appointment-room {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
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
		padding-inline-start: var(--cs-view-inline-start);
	}

	.task-row {
		flex-wrap: wrap;
	}

	/* The task text takes its own full-width line above the actions; with
	   its zero flex basis it shrank to a sliver instead of wrapping
	   (CS-PLAN-087 RF-087-08). */
	.task-info {
		flex-basis: 100%;
		order: -1;
	}

	.checklist-toggle {
		margin-inline-start: auto;
	}

	.appointment-row {
		flex-wrap: wrap;
		gap: 0.25rem 1rem;
	}
}
</style>
