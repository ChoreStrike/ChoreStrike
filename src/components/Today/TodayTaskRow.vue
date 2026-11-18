<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="today-row-group">
		<div
			class="task-row"
			:class="[variant, { completed: done, 'cs-just-completed': justCompleted }]"
			data-testid="today-task-item">
			<HoldButton
				v-if="!done"
				:iconSize="22"
				:busy="roomTasksStore.isCompleting(id)"
				:data-testid="`today-complete-button-${id}`"
				:aria-label="t('chorestrike', 'Mark “{name}” as done', { name: item.task.name })"
				:title="t('chorestrike', 'Mark as done')"
				@confirm="emit('complete', item)" />
			<span v-else class="completed-check" :data-testid="`today-completed-check-${id}`">
				<Icon name="CheckCircle" :size="22" />
			</span>

			<div class="task-info">
				<span class="task-title">
					<span class="task-name">{{ item.task.name }}</span>
					<span v-if="overdueDays > 0" class="overdue-badge" data-testid="today-overdue-days">
						{{ daysLabel(overdueDays) }}
					</span>
				</span>
				<TaskMeta
					:energy="taskEnergy(item)"
					:recurrence="recurrenceLabel(item.task)"
					:location="location"
					:assignees="assignees" />
				<p v-if="plannedLabel" class="task-planned" :data-testid="plannedTestId">
					{{ plannedLabel }}
				</p>
				<p v-if="instructions" class="task-instructions">
					{{ instructions }}
				</p>
			</div>

			<!-- BUG-UX-003: only a definition with checklist items offers the
			     toggle; the reads carry the count. -->
			<button
				v-if="(item.checklistItemCount ?? 0) > 0"
				type="button"
				class="checklist-toggle"
				:aria-expanded="expanded"
				:data-testid="`today-checklist-toggle-${id}`"
				@click="toggleExpand">
				<ExpandChevron :open="expanded" />
				<span>{{ checklistSummary }}</span>
			</button>
		</div>

		<TaskChecklist
			v-if="expanded"
			class="today-checklist"
			:items="checklistStore.getTaskItems(item.task.id)"
			:progress="checklistStore.getRoomTaskProgress(id)"
			:canEdit="false"
			:readonly="done"
			:roomTaskId="id"
			:taskId="item.task.id"
			@toggle="toggleChecklistItem" />
	</div>
</template>

<script setup>
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import ExpandChevron from '../Common/ExpandChevron.vue'
import HoldButton from '../Common/HoldButton.vue'
import Icon from '../Common/Icon.vue'
import TaskChecklist from '../Tasks/TaskChecklist.vue'
import TaskMeta from '../Tasks/TaskMeta.vue'
import { useChecklistStore } from '../../store/checklist.js'
import { useHouseStore } from '../../store/house.js'
import { useRoomsStore } from '../../store/rooms.js'
import { useRoomTasksStore } from '../../store/roomTasks.js'
import { useUserStore } from '../../store/user.js'
import { daysBetweenKeys, formatDayKey, todayKeyInZone } from '../../utils/dateKeys.js'
import { showErrorWithReference } from '../../utils/errorToast.js'
import { logger } from '../../utils/logger.js'
import { assignmentLabel, cycleOf, daysLabel, locationLabel, recurrenceLabel, taskEnergy } from '../../utils/taskDisplay.js'

/**
 * One task row of Today with its execute-only checklist (CS-PLAN-090
 * WP-B). The view decides which rows a section holds and runs the
 * completion; the row shows the facts, the checklist and the feedback.
 *
 * A row whose completion just succeeded shows the check mark instead of
 * the Done button for as long as the store marks it, also when the task
 * recurs and stays active: the view keeps such a row in place for that
 * moment (D-090-30).
 */
const props = defineProps({
	/** Enriched row `{ roomTask, task, room, assignees, cycle, … }`. */
	item: { type: Object, required: true },
	/** Section of the row: `due`, `overdue` or `possible`. */
	variant: { type: String, default: 'due' },
})

const emit = defineEmits(['complete'])

const roomTasksStore = useRoomTasksStore()
const checklistStore = useChecklistStore()
const roomsStore = useRoomsStore()
const userStore = useUserStore()
const houseStore = useHouseStore()

const expanded = ref(false)

const id = computed(() => props.item.roomTask.id)
const justCompleted = computed(() => roomTasksStore.wasJustCompleted(id.value))
const done = computed(() => !props.item.roomTask.isActive || justCompleted.value)
const cycle = computed(() => cycleOf(props.item) ?? null)

const location = computed(() => locationLabel(props.item.room, roomsStore.getFloorById(props.item.room?.floorId)))
const assignees = computed(() => assignmentLabel(
	props.item.assignees ?? props.item.roomTask.assignees,
	userStore.houseUsers,
))
// Household text: the template renders it as plain escaped text.
const instructions = computed(() => props.item.task.instructions || props.item.task.description || '')

/**
 * The planned day of work that is not simply due today, straight from the
 * cycle block and never recomputed here: the missed day with the end of
 * its window where that is a later day (C1c), or the day a still-open
 * window belongs to.
 */
const plannedLabel = computed(() => {
	const planned = cycle.value?.centreOn
	if (props.variant === 'due' || !planned) {
		return ''
	}
	const date = formatDayKey(planned)
	const end = cycle.value.windowEndOn
	return props.variant === 'overdue' && end && end !== planned
		? t('chorestrike', 'planned {date}, until {end}', { date, end: formatDayKey(end) })
		: t('chorestrike', 'planned {date}', { date })
})
const plannedTestId = computed(() => (props.variant === 'overdue' ? 'catchup-planned' : `today-open-window-planned-${id.value}`))

/** Whole house-local days since the window ended; 0 on the same day. */
const overdueDays = computed(() => {
	if (props.variant !== 'overdue' || !cycle.value?.windowEndOn) {
		return 0
	}
	return daysBetweenKeys(cycle.value.windowEndOn, todayKeyInZone(houseStore.timezone)) ?? 0
})

const checklistSummary = computed(() => {
	const items = checklistStore.getTaskItems(props.item.task.id)
	if (items.length === 0) {
		return t('chorestrike', 'Checklist')
	}
	const checked = checklistStore.getRoomTaskProgress(id.value).filter((entry) => entry.isChecked).length
	return t('chorestrike', 'Checklist ({done}/{total})', { done: checked, total: items.length })
})

// The next cycle starts without ticks (ADR-021 decision 3): an open
// checklist reads its progress again. Optional, the next expand reads it
// anyway.
watch(justCompleted, (completed) => {
	if (completed && expanded.value) {
		checklistStore.fetchRoomTaskProgress(id.value).catch(() => {})
	}
})

async function toggleExpand() {
	if (expanded.value) {
		expanded.value = false
		return
	}
	expanded.value = true
	try {
		await Promise.all([
			checklistStore.fetchTaskItems(props.item.task.id),
			checklistStore.fetchRoomTaskProgress(id.value),
		])
		// Items deleted since the row was read: collapse again instead of
		// showing an empty box.
		if (checklistStore.getTaskItems(props.item.task.id).length === 0) {
			expanded.value = false
		}
	} catch (error) {
		showError(t('chorestrike', 'Could not load the checklist'))
		logger.error('Failed to load checklist', error, { component: 'TodayTaskRow', action: 'toggleExpand', roomTaskId: id.value })
	}
}

async function toggleChecklistItem({ itemId, roomTaskId }) {
	try {
		await checklistStore.toggleItem(roomTaskId, itemId)
	} catch (error) {
		showErrorWithReference(t('chorestrike', 'Could not update the checklist'), error)
		logger.error('Failed to toggle checklist item', error, { component: 'TodayTaskRow', action: 'toggleChecklistItem', roomTaskId, itemId })
	}
}
</script>

<style scoped>
.task-row {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	padding: 0.5rem 0.25rem;
	border-bottom: 1px solid var(--color-border);
}

.task-row.overdue {
	padding-inline: 0.75rem;
	border-bottom-color: var(--color-element-error);
}

.today-row-group:last-child .task-row {
	border-bottom: none;
}

.task-row.completed {
	opacity: 0.6;
}

.completed-check {
	display: flex;
	flex-shrink: 0;
	align-items: center;
	justify-content: center;
	width: 44px;
	height: 44px;
	color: var(--color-success-text);
}

.task-info {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 0.1rem;
	min-width: 0;
}

.task-title {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 0.5rem;
}

.task-name {
	font-weight: 600;
	color: var(--color-main-text);
	overflow-wrap: anywhere;
}

.task-planned {
	margin: 0.15rem 0 0;
	font-size: 0.85rem;
	color: var(--color-main-text);
}

.task-instructions {
	margin: 0.15rem 0 0;
	font-size: 0.9rem;
	line-height: 1.4;
	color: var(--color-main-text);
	white-space: pre-line;
}

.overdue-badge {
	flex-shrink: 0;
	padding: 0.15rem 0.5rem;
	border: 1px solid var(--color-element-error);
	border-radius: 10px;
	background: var(--color-main-background);
	color: var(--color-error-text);
	font-size: 0.8rem;
	font-weight: 700;
}

.checklist-toggle {
	display: flex;
	flex-shrink: 0;
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

.checklist-toggle:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.today-checklist {
	margin: 0.25rem 0 0.5rem;
}

@media (max-width: 600px) {
	.task-row {
		flex-wrap: wrap;
	}

	/* The text keeps most of the line next to the Done button; the
	   checklist toggle wraps below instead of squeezing the text to a
	   sliver (CS-PLAN-087 RF-087-08). */
	.task-info {
		flex-basis: 60%;
	}

	.checklist-toggle {
		margin-inline-start: auto;
	}
}
</style>
