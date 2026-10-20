<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="schedule-planner" data-testid="schedule-planner">
		<div class="planner-header">
			<h2>{{ t('chorestrike', 'Plan the week') }}</h2>
			<p class="planner-hint">
				{{ t('chorestrike', 'Pick real tasks, preview who does what on which day, then confirm once — nothing changes until you apply.') }}
			</p>
		</div>

		<!-- Selection: window + tasks -->
		<div class="selection-block">
			<div class="window-row">
				<label class="window-label" for="planner-start-date">{{ t('chorestrike', 'Start planning on') }}</label>
				<input
					id="planner-start-date"
					v-model="startDate"
					type="date"
					class="date-input"
					:data-testid="`planner-start-date`"
					:min="todayKey">
			</div>

			<div
				v-if="schedulableTasks.length === 0"
				class="empty-selection"
				data-testid="planner-empty-selection">
				<p>{{ t('chorestrike', 'No active tasks to schedule yet. Create tasks first.') }}</p>
			</div>
			<ul v-else class="task-selection" data-testid="planner-task-list">
				<li
					v-for="item in schedulableTasks"
					:key="item.id"
					class="task-select-row"
					:data-testid="`planner-task-row-${item.id}`">
					<label class="task-select-label">
						<input
							v-model="selectedIds"
							type="checkbox"
							class="task-checkbox"
							:value="item.id"
							:data-testid="`planner-task-checkbox-${item.id}`">
						<span class="task-name">{{ item.name }}</span>
						<span class="task-room">{{ item.roomName }}</span>
						<span class="task-due">{{ dueLabel(item.nextDue) }}</span>
						<span class="task-assignees">{{ assigneeLabel(item.assignees) }}</span>
					</label>
				</li>
			</ul>

			<div class="preview-controls">
				<button
					class="generate-button"
					:disabled="!canPreview"
					data-testid="preview-plan-button"
					@click="runPreview">
					{{ planning ? t('chorestrike', 'Preparing preview…') : t('chorestrike', 'Preview plan') }}
				</button>
				<p v-if="selectedIds.length === 0" class="control-hint">
					{{ t('chorestrike', 'Select at least one task to preview a plan.') }}
				</p>
			</div>
		</div>

		<!-- Status announcements -->
		<div aria-live="polite" class="cs-visually-hidden" data-testid="plan-status-announcer">
			{{ statusAnnouncement }}
		</div>

		<div v-if="planning" class="loading-state" data-testid="plan-loading">
			<div class="loading-spinner" />
			<p>{{ t('chorestrike', 'Building the plan from your tasks…') }}</p>
		</div>

		<p v-else-if="planStatus === 'error'" class="status-banner error" data-testid="plan-error">
			{{ t('chorestrike', 'The plan could not be prepared. Please try again.') }}
		</p>

		<p v-else-if="planStatus === 'stale'" class="status-banner warning" data-testid="plan-stale">
			{{ t('chorestrike', 'This preview is no longer current. Check your selection and prepare the plan again.') }}
		</p>

		<p v-else-if="planStatus === 'applied'" class="status-banner success" data-testid="plan-applied">
			{{ t('chorestrike', 'The plan was applied. Calendar and My Day are up to date.') }}
		</p>

		<p v-else-if="planStatus === 'refreshFailed'" class="status-banner warning" data-testid="plan-refresh-failed">
			{{ t('chorestrike', 'The plan was applied, but refreshing the views failed. Reload the page to see the result.') }}
		</p>

		<!-- Results -->
		<div v-if="hasPreview" class="preview-content" data-testid="plan-preview">
			<p v-if="noChanges" class="status-banner neutral" data-testid="plan-no-changes">
				{{ t('chorestrike', 'Nothing to change — the selected tasks are already scheduled as proposed.') }}
			</p>

			<div v-if="planPreview.warnings.length > 0" class="plan-warnings" data-testid="plan-warnings">
				<p v-for="warning in planPreview.warnings" :key="warning" class="status-banner warning">
					{{ warningText(warning) }}
				</p>
			</div>

			<div v-if="planPreview.changes.length > 0" class="changes-block">
				<h3>{{ t('chorestrike', 'Proposed changes') }}</h3>
				<ul class="change-list" data-testid="plan-changes">
					<li
						v-for="change in planPreview.changes"
						:key="change.roomTaskId"
						class="change-row"
						:data-testid="`plan-change-${change.roomTaskId}`">
						<div class="change-info">
							<span class="task-name">{{ change.taskName }}</span>
							<span class="task-room">{{ change.roomName }}</span>
							<span class="change-diff">
								{{ t('chorestrike', '{from} → {to}', {
									from: describeRow(change.before),
									to: describeRow(change.proposed),
								}) }}
							</span>
							<span
								v-if="shareLabel(change)"
								class="change-shares"
								:data-testid="`plan-change-shares-${change.roomTaskId}`">
								{{ shareLabel(change) }}
							</span>
							<span v-if="change.warnings.length > 0" class="change-warnings">
								{{ change.warnings.map(warningText).join(' · ') }}
							</span>
						</div>
						<div class="override-controls">
							<label class="override-label" :for="`assignee-${change.roomTaskId}`">
								{{ t('chorestrike', 'Person') }}
							</label>
							<select
								:id="`assignee-${change.roomTaskId}`"
								v-model="edits[change.roomTaskId].assignee"
								class="override-select"
								:data-testid="`planner-assignee-${change.roomTaskId}`"
								@change="markStale">
								<option value="">
									{{ t('chorestrike', 'As proposed') }}
								</option>
								<option v-for="member in memberOptions" :key="member" :value="member">
									{{ memberDisplayName(member) }}
								</option>
							</select>
							<label class="override-label" :for="`date-${change.roomTaskId}`">
								{{ t('chorestrike', 'Day') }}
							</label>
							<select
								:id="`date-${change.roomTaskId}`"
								v-model="edits[change.roomTaskId].date"
								class="override-select"
								:data-testid="`planner-date-${change.roomTaskId}`"
								@change="markStale">
								<option value="">
									{{ t('chorestrike', 'As proposed') }}
								</option>
								<option v-for="date in change.legalDates" :key="date" :value="date">
									{{ formatDateKey(date) }}
								</option>
							</select>
						</div>
					</li>
				</ul>
			</div>

			<div v-if="planPreview.unplaced.length > 0" class="unplaced-block">
				<h3>{{ t('chorestrike', 'Not schedulable right now') }}</h3>
				<ul class="plain-list" data-testid="plan-unplaced">
					<li
						v-for="row in planPreview.unplaced"
						:key="row.roomTaskId"
						:data-testid="`plan-unplaced-${row.roomTaskId}`">
						{{ taskNameById(row.roomTaskId) }} — {{ reasonText(row.reason) }}
					</li>
				</ul>
			</div>

			<div v-if="planPreview.unchanged.length > 0" class="unchanged-block">
				<h3>{{ t('chorestrike', 'Already as planned') }}</h3>
				<ul class="plain-list" data-testid="plan-unchanged">
					<li v-for="row in planPreview.unchanged" :key="row.roomTaskId">
						{{ taskNameById(row.roomTaskId) }} — {{ reasonText(row.reason) }}
					</li>
				</ul>
			</div>

			<div class="capacity-block">
				<h3>{{ t('chorestrike', 'Capacity per day') }}</h3>
				<table class="capacity-table" data-testid="plan-capacity">
					<thead>
						<tr>
							<th scope="col">
								{{ t('chorestrike', 'Day') }}
							</th>
							<th
								v-for="member in memberOptions"
								:key="member"
								scope="col">
								{{ memberDisplayName(member) }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="day in planPreview.capacity" :key="day.date">
							<th scope="row">
								{{ formatDateKey(day.date) }}
							</th>
							<td
								v-for="member in memberOptions"
								:key="member"
								:class="{
									unavailable: !day.members[member]?.available && !day.members[member]?.paused,
									paused: day.members[member]?.paused === true,
									overloaded: day.members[member]?.overloaded === true,
								}">
								{{ capacityCell(day.members[member]) }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="apply-controls">
				<label class="consent-label" for="planner-consent">
					<input
						id="planner-consent"
						:checked="schedulingStore.planConsent"
						type="checkbox"
						class="consent-checkbox"
						data-testid="planner-consent"
						@change="onConsentChange">
					<span class="consent-text">{{ t('chorestrike', 'Everyone affected has agreed to this plan') }}</span>
				</label>
				<button
					class="apply-button"
					:disabled="!canApply"
					data-testid="apply-plan-button"
					@click="apply">
					{{ applying ? t('chorestrike', 'Applying…') : t('chorestrike', 'Apply plan') }}
				</button>
				<p v-if="planStatus === 'stale'" class="control-hint">
					{{ t('chorestrike', 'Confirming is disabled until you prepare a fresh plan.') }}
				</p>
				<p v-else-if="!schedulingStore.planConsent && hasChanges" class="control-hint" data-testid="planner-consent-hint">
					{{ t('chorestrike', 'Confirm the agreement before applying the plan.') }}
				</p>
			</div>
		</div>
	</div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { useSchedulingStore } from '../../store/scheduling.js'
import { useRoomTasksStore } from '../../store/roomTasks.js'
import { useRoomsStore } from '../../store/rooms.js'
import { useTasksStore } from '../../store/tasks.js'
import { useUserStore } from '../../store/user.js'
import { useHouseStore } from '../../store/house.js'
import { logger } from '../../utils/logger.js'
import { dateKeyInZone, todayKeyInZone } from '../../utils/dateKeys.js'

const schedulingStore = useSchedulingStore()
const roomTasksStore = useRoomTasksStore()
const roomsStore = useRoomsStore()
const tasksStore = useTasksStore()
const userStore = useUserStore()
const houseStore = useHouseStore()

const todayKey = todayKeyInZone(houseStore.timezone)
const startDate = ref(todayKey)
const selectedIds = ref([])
// Per-row override edits: '' = as proposed (no override).
const edits = ref({})

const planning = computed(() => schedulingStore.planStatus === 'loading')
const applying = computed(() => schedulingStore.planStatus === 'applying')
const planStatus = computed(() => schedulingStore.planStatus)
const planPreview = computed(() => schedulingStore.planPreview)
const hasPreview = computed(() => schedulingStore.planPreview !== null
	&& ['ready', 'stale', 'applying', 'applied', 'refreshFailed'].includes(schedulingStore.planStatus))
const noChanges = computed(() => schedulingStore.planPreview?.changes?.length === 0
	&& schedulingStore.planStatus !== 'stale')

const schedulableTasks = computed(() => {
	const roomsById = roomsByIdMap()
	return roomTasksStore.roomTasks
		.filter((roomTask) => roomTask.isActive)
		.map((roomTask) => {
			const task = tasksStore.getTaskById(roomTask.taskId)
			return {
				id: roomTask.id,
				name: task?.name ?? t('chorestrike', 'Unknown task'),
				roomName: roomsById[roomTask.roomId]?.name ?? t('chorestrike', 'Room'),
				nextDue: roomTask.nextDue,
				assignees: roomTask.assignees ?? [],
			}
		})
		.sort((a, b) => String(a.nextDue ?? '9999').localeCompare(String(b.nextDue ?? '9999')))
})

const memberOptions = computed(() =>
	schedulingStore.memberAvailability.map((member) => member.userId))

const canPreview = computed(() =>
	!planning.value && !applying.value && selectedIds.value.length > 0 && startDate.value !== '')

const canApply = computed(() =>
	hasPreview.value
	&& schedulingStore.planStatus === 'ready'
	&& (schedulingStore.planPreview?.changes?.length ?? 0) > 0
	// WP2 §3.3.2 (RF-073-WP2-06/13): the explicit owner consent attestation
	// gates the apply control — the backend refuses the command without it.
	&& schedulingStore.planConsent === true)

const hasChanges = computed(() => (schedulingStore.planPreview?.changes?.length ?? 0) > 0)

const onConsentChange = (event) => {
	schedulingStore.setPlanConsent(event.target.checked === true)
}

const statusAnnouncement = computed(() => {
	if (planning.value) {
		return t('chorestrike', 'Building the plan from your tasks…')
	}
	if (applying.value) {
		return t('chorestrike', 'Applying the plan…')
	}
	if (schedulingStore.planStatus === 'applied') {
		return t('chorestrike', 'The plan was applied.')
	}
	if (schedulingStore.planStatus === 'stale') {
		return t('chorestrike', 'The preview is stale.')
	}
	if (schedulingStore.planStatus === 'error') {
		return t('chorestrike', 'The plan failed.')
	}
	return ''
})

const roomsByIdMap = () => {
	const map = {}
	for (const room of roomsStore.rooms) {
		map[room.id] = room
	}
	return map
}

const dueLabel = (nextDue) => {
	if (!nextDue) {
		return t('chorestrike', 'No due date')
	}
	return t('chorestrike', 'Due {date}', { date: formatDateKey(dateKeyInZone(nextDue, houseStore.timezone)) })
}

const assigneeLabel = (assignees) => {
	if (!assignees || assignees.length === 0) {
		return t('chorestrike', 'Unassigned')
	}
	return assignees.map((id) => memberDisplayName(id)).join(', ')
}

const memberDisplayName = (userId) => {
	const member = schedulingStore.memberAvailability.find((m) => m.userId === userId)
	if (member?.userName) {
		return member.userName
	}
	const current = userStore.currentUser
	if (current?.nextcloudUserId === userId && current?.displayName) {
		return current.displayName
	}
	return userId
}

const formatDateKey = (key) => {
	if (!key) {
		return '—'
	}
	const [y, m, d] = key.split('-').map(Number)
	return new Date(y, m - 1, d).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })
}

const describeRow = (row) => {
	const people = row.assignees.length === 0
		? t('chorestrike', 'unassigned')
		: row.assignees.map((id) => memberDisplayName(id)).join(', ')
	return t('chorestrike', '{people}, {date}', {
		people,
		date: row.dueDate ? formatDateKey(row.dueDate) : t('chorestrike', 'no date'),
	})
}

const taskNameById = (roomTaskId) => {
	const item = schedulableTasks.value.find((task) => task.id === roomTaskId)
	return item?.name ?? t('chorestrike', 'Task')
}

const warningText = (code) => {
	const messages = {
		EXISTING_OVERLOAD: t('chorestrike', 'Some days are already over capacity — existing overload is reported, not silently moved.'),
		DATE_MOVED: t('chorestrike', 'The due day moves.'),
		ASSIGNEES_CHANGED: t('chorestrike', 'The assigned people change.'),
	}
	return messages[code] ?? code
}

const reasonText = (code) => {
	const messages = {
		UP_TO_DATE: t('chorestrike', 'already scheduled as proposed'),
		CLAIMED: t('chorestrike', 'claimed by someone — resolve the claim first'),
		NO_LEGAL_SLOT: t('chorestrike', 'no legal day in this window (fixed weekday or no flexibility)'),
		NO_ELIGIBLE_MEMBER: t('chorestrike', 'no eligible person available'),
		NO_CAPACITY: t('chorestrike', 'no free capacity on any legal day'),
		ILLEGAL_OVERRIDE: t('chorestrike', 'the chosen day is not allowed for this task'),
	}
	return messages[code] ?? code
}

const capacityCell = (entry) => {
	if (!entry) {
		return '—'
	}
	// WP2 neutral/overload clarification (RF-073-WP2-13): a paused member
	// is neutral, an existing overload stays visible with its amounts —
	// never silently hidden or truncated.
	if (entry.paused) {
		return t('chorestrike', 'Paused')
	}
	if (!entry.available) {
		return t('chorestrike', 'Away')
	}
	if (entry.overloaded) {
		return t('chorestrike', '{planned}/{budget} — over capacity', {
			planned: entry.planned,
			budget: entry.budget,
		})
	}
	return t('chorestrike', '{remaining}/{budget} left', { remaining: entry.remaining, budget: entry.budget })
}

/**
 * The exact agreed share labels of a proposed change (WP2 §3.5:
 * exact-share preview labels — whole percents, wallet-order remainder
 * split, e.g. 34/33/33).
 * @param {object} change The preview change row
 * @return {string} The rendered label (empty when no shares ride the row)
 */
const shareLabel = (change) => {
	const shares = change?.proposed?.shares ?? change?.shares ?? null
	if (!shares || Object.keys(shares).length === 0) {
		return ''
	}
	const parts = Object.entries(shares)
		.map(([userId, pct]) => `${memberDisplayName(userId)} ${pct}%`)
	return t('chorestrike', 'Exact shares: {shares}', { shares: parts.join(', ') })
}

const ensureEditSlots = () => {
	for (const id of selectedIds.value) {
		if (!edits.value[id]) {
			edits.value = { ...edits.value, [id]: { assignee: '', date: '' } }
		}
	}
}

// CS-PLAN-065 §3.1 rule 7: an owner edit to a row OR input invalidates the
// confirmation — selection and window changes mark the preview stale
// (markPlanStale only downgrades a ready preview, so the initial empty
// selection is a no-op).
watch(selectedIds, () => {
	ensureEditSlots()
	schedulingStore.markPlanStale()
})
watch(startDate, () => {
	schedulingStore.markPlanStale()
})

const runPreview = async () => {
	if (!canPreview.value) {
		return
	}
	try {
		await schedulingStore.previewPlan({
			startDate: startDate.value,
			roomTaskIds: [...selectedIds.value],
			overrides: buildOverrides(),
		})
	} catch (error) {
		showError(t('chorestrike', 'The plan could not be prepared. Please try again.'))
	}
}

const buildOverrides = () => {
	const overrides = {}
	for (const id of selectedIds.value) {
		const edit = edits.value[id]
		if (!edit) {
			continue
		}
		const override = {}
		if (edit.assignee !== '') {
			override.assigneeUserIds = [edit.assignee]
		}
		if (edit.date !== '') {
			override.date = edit.date
		}
		if (Object.keys(override).length > 0) {
			overrides[id] = override
		}
	}
	return overrides
}

const markStale = () => {
	schedulingStore.markPlanStale()
}

const apply = async () => {
	if (!canApply.value) {
		return
	}
	try {
		const applied = await schedulingStore.applyPlan()
		if (applied && schedulingStore.planStatus === 'applied') {
			showSuccess(t('chorestrike', 'The plan was applied.'))
		} else if (schedulingStore.planStatus === 'refreshFailed') {
			showError(t('chorestrike', 'The plan was applied, but refreshing the views failed.'))
		}
	} catch (error) {
		if (schedulingStore.planStatus === 'stale') {
			showError(t('chorestrike', 'This preview is no longer current. Prepare the plan again.'))
		} else {
			showError(t('chorestrike', 'The plan could not be applied.'))
		}
		logger.error('Failed to apply the plan', error, { component: 'SchedulePlanner', action: 'apply' })
	}
}
</script>

<style scoped>
.schedule-planner {
	background: var(--color-main-background);
	border-radius: 12px;
	padding: 1.5rem;
}

.planner-header {
	margin-bottom: 1.5rem;
}

.planner-header h2 {
	font-size: 1.5rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0 0 0.25rem 0;
}

.planner-hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.selection-block {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 1rem;
	margin-bottom: 1.5rem;
}

.window-row {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	margin-bottom: 1rem;
	flex-wrap: wrap;
}

.window-label {
	font-weight: 500;
	color: var(--color-main-text);
}

.date-input {
	min-height: 44px;
	padding: 0.5rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.task-selection {
	list-style: none;
	margin: 0 0 1rem 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	max-height: 320px;
	overflow-y: auto;
}

.task-select-row {
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-background-dark);
}

.task-select-label {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	padding: 0.5rem 0.75rem;
	min-height: 44px;
	cursor: pointer;
	flex-wrap: wrap;
}

.task-checkbox {
	width: 20px;
	height: 20px;
	min-width: 20px;
	min-height: 20px;
	cursor: pointer;
}

.task-select-label:focus-within {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.task-name {
	font-weight: 600;
	color: var(--color-main-text);
}

.task-room,
.task-due,
.task-assignees {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.preview-controls {
	display: flex;
	align-items: center;
	gap: 1rem;
	flex-wrap: wrap;
}

.generate-button {
	min-height: 44px;
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
	border-radius: 6px;
	padding: 0.75rem 1.5rem;
	font-weight: 500;
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.generate-button:hover:not(:disabled) {
	background: var(--color-primary-hover);
}

.generate-button:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.control-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	margin: 0;
}

.empty-selection {
	color: var(--color-text-maxcontrast);
	padding: 1rem 0;
}

.loading-state {
	text-align: center;
	padding: 2rem 0;
}

.loading-spinner {
	width: 40px;
	height: 40px;
	border: 3px solid var(--color-border);
	border-top: 3px solid var(--color-primary-element);
	border-radius: 50%;
	animation: spin 1s linear infinite;
	margin: 0 auto 1rem;
}

@keyframes spin {
	0% { transform: rotate(0deg); }
	100% { transform: rotate(360deg); }
}

.status-banner {
	border-radius: 8px;
	padding: 0.75rem 1rem;
	margin: 0 0 1rem 0;
}

.status-banner.warning {
	background: rgba(255, 152, 0, 0.12);
	color: var(--color-main-text);
}

.status-banner.error {
	background: var(--color-error-hover, #fde8e8);
	color: var(--color-error-text);
}

.status-banner.success {
	background: rgba(76, 175, 80, 0.12);
	color: var(--color-main-text);
}

.status-banner.neutral {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.preview-content h3 {
	font-size: 1.1rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 1.5rem 0 0.5rem 0;
}

.change-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.change-row {
	display: flex;
	justify-content: space-between;
	gap: 1rem;
	padding: 0.75rem 1rem;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-background-dark);
	flex-wrap: wrap;
}

.change-info {
	display: flex;
	flex-direction: column;
	gap: 0.15rem;
	min-width: 0;
}

.change-diff {
	color: var(--color-main-text);
}

.change-warnings {
	color: var(--color-warning-text);
	font-size: 0.85rem;
}

.override-controls {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	flex-wrap: wrap;
}

.override-label {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.override-select {
	min-height: 44px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 0.25rem 0.5rem;
}

.plain-list {
	margin: 0;
	padding-inline-start: 1.25rem;
	color: var(--color-text-maxcontrast);
}

.capacity-table {
	width: 100%;
	border-collapse: collapse;
}

.capacity-table th,
.capacity-table td {
	border: 1px solid var(--color-border);
	padding: 0.5rem;
	text-align: start;
	font-size: 0.9rem;
}

.capacity-table td.unavailable {
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
}

.capacity-table td.paused {
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
	font-style: italic;
}

.capacity-table td.overloaded {
	color: var(--color-error-text);
	font-weight: 600;
}

.change-shares {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.consent-label {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	min-height: 44px;
	cursor: pointer;
	color: var(--color-main-text);
}

.consent-checkbox {
	width: 20px;
	height: 20px;
	min-width: 20px;
	min-height: 20px;
	cursor: pointer;
}

.consent-label:focus-within {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.consent-text {
	font-size: 0.95rem;
}

.apply-controls {
	margin-top: 1.5rem;
	display: flex;
	align-items: center;
	gap: 1rem;
	flex-wrap: wrap;
}

.apply-button {
	min-height: 44px;
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
	border-radius: 6px;
	padding: 0.75rem 2rem;
	font-weight: 600;
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.apply-button:hover:not(:disabled) {
	background: var(--color-primary-hover);
}

.apply-button:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

@media (max-width: 768px) {
	.change-row {
		flex-direction: column;
	}

	.capacity-table {
		font-size: 0.8rem;
	}
}
</style>
