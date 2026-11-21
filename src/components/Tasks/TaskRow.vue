<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="task-row-group" :class="[variant, { open: expanded, overdue: overdueRow }]">
		<div class="task-row"
			:class="[variant, { completed: done, overdue: overdueRow, 'cs-just-completed': justCompleted }]"
			:data-testid="variant === 'list' ? 'task-item' : 'today-task-item'">
			<HoldButton
				v-if="!done"
				:iconSize="22"
				:busy="roomTasksStore.isCompleting(id)"
				:data-testid="variant === 'list' ? 'complete-task-button' : `today-complete-button-${id}`"
				:aria-label="t('chorestrike', 'Mark “{name}” as done', { name: task.name })"
				:title="t('chorestrike', 'Mark as done')"
				tappable
				@tap="picking = true"
				@confirm="$emit('complete', item)" />
			<span v-else class="completed-check" :data-testid="`today-completed-check-${id}`">
				<Icon name="CheckCircle" :size="22" />
			</span>

			<div class="task-info">
				<span class="task-title">
					<span class="task-name">{{ task.name }}</span>
					<Icon v-if="task.visibilityType === VISIBILITY_PRIVATE"
						name="Lock"
						:size="14"
						class="visibility-icon"
						data-testid="visibility-lock-icon"
						:title="t('chorestrike', 'Private task')" />
					<Icon v-if="task.visibilityType === VISIBILITY_ADMIN_ONLY"
						name="Crown"
						:size="14"
						class="visibility-icon"
						data-testid="visibility-crown-icon"
						:title="t('chorestrike', 'Admin-only task')" />
					<span v-if="overdueDays > 0" class="overdue-badge" data-testid="today-overdue-days">
						{{ daysLabel(overdueDays) }}
					</span>
				</span>
				<TaskMeta :energy="taskEnergy(item)"
					:recurrence="recurrenceLabel(task)"
					:location="location"
					:assignees="assignmentLabel(assignees, userStore.houseUsers)" />
				<p v-if="plannedLabel" class="task-planned" :data-testid="variant === 'overdue' ? 'catchup-planned' : `today-open-window-planned-${id}`">
					{{ plannedLabel }}
				</p>
				<p v-if="variant === 'list'" class="task-due" :class="dueClass(dueRoomTask, houseStore.timezone)">
					{{ dueText(dueRoomTask, houseStore.timezone) }}
				</p>
			</div>

			<button type="button"
				class="expand-toggle"
				:aria-expanded="expanded ? 'true' : 'false'"
				:aria-controls="detailsId"
				:aria-label="t('chorestrike', 'Details of “{name}”', { name: task.name })"
				data-testid="task-expand"
				@click="expanded = !expanded">
				<ExpandChevron :open="expanded" />
			</button>
		</div>

		<TaskRowDetails v-if="expanded"
			:id="detailsId"
			:roomTask="roomTask"
			:task="task"
			:cycle="cycle"
			:assignees="assignees"
			:subTaskCount="item.checklistItemCount"
			@record="picking = true"
			@edit="editing = true" />

		<!-- A short tap on Done, or "For someone else": who did it, which sub-tasks are done. -->
		<CompletionPicker v-if="picking"
			:roomTaskId="id"
			:taskId="task.id"
			:assignees="assignees"
			:withoutSubTasks="item.checklistItemCount === 0"
			@close="picking = false"
			@confirm="completeAs" />
		<TaskForm v-if="editing" :item="formItem" @close="editing = false" />
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import ExpandChevron from '../Common/ExpandChevron.vue'
import HoldButton from '../Common/HoldButton.vue'
import Icon from '../Common/Icon.vue'
import CompletionPicker from './CompletionPicker.vue'
import TaskForm from './TaskForm.vue'
import TaskMeta from './TaskMeta.vue'
import TaskRowDetails from './TaskRowDetails.vue'
import { VISIBILITY_ADMIN_ONLY, VISIBILITY_PRIVATE } from '../../constants/taskVisibility.js'
import { useHouseStore } from '../../store/house.js'
import { useRoomsStore } from '../../store/rooms.js'
import { useRoomTasksStore } from '../../store/roomTasks.js'
import { useUserStore } from '../../store/user.js'
import { daysBetweenKeys, formatDayKey, todayKeyInZone } from '../../utils/dateKeys.js'
import { assignmentLabel, cycleOf, daysLabel, dueClass, dueText, locationLabel, recurrenceLabel, taskEnergy } from '../../utils/taskDisplay.js'

/**
 * The one task row (CS-PLAN-091 WP-B6, FEAT-UX-040): the same row on
 * Today and in the task list. Holding Done completes the task; a short tap
 * opens the picker for who did it and which sub-tasks are done. A tap on
 * the arrow opens the row in place with everything else
 * ({@link TaskRowDetails}); editing opens the one form.
 *
 * A row whose completion just succeeded shows the check mark for as long
 * as the store marks it, also when the task recurs and stays active
 * (D-090-30).
 */
const props = defineProps({
	/** `{ roomTask, task }`, on Today with `room`, `assignees`, `cycle` and `checklistItemCount`. */
	item: { type: Object, required: true },
	/** The section of Today (`due`, `overdue`, `possible`), or `list` in the task list. */
	variant: { type: String, default: 'due' },
})
const emit = defineEmits(['complete'])

const roomTasksStore = useRoomTasksStore()
const roomsStore = useRoomsStore()
const userStore = useUserStore()
const houseStore = useHouseStore()

const expanded = ref(false)
const picking = ref(false)
const editing = ref(false)

const task = computed(() => props.item.task)
const roomTask = computed(() => props.item.roomTask)
const id = computed(() => roomTask.value.id)
const detailsId = computed(() => `task-row-details-${id.value}`)
const cycle = computed(() => cycleOf(props.item) ?? null)
const assignees = computed(() => props.item.assignees ?? roomTask.value.assignees ?? [])
const justCompleted = computed(() => roomTasksStore.wasJustCompleted(id.value))
const done = computed(() => !roomTask.value.isActive || justCompleted.value)
// The list marks the row as its due text reads it: the cycle, else the stored status.
const dueRoomTask = computed(() => ({ ...roomTask.value, cycle: cycle.value }))
const overdueRow = computed(() => props.variant === 'overdue' || (props.variant === 'list' && dueClass(dueRoomTask.value, houseStore.timezone) === 'overdue'))
/** The form edits the room task with what the row knows of it. */
const formItem = computed(() => ({ task: task.value, roomTask: { ...roomTask.value, assignees: assignees.value, cycle: cycle.value } }))

const location = computed(() => {
	const room = props.item.room ?? roomsStore.getRoomById(roomTask.value.roomId)
	return locationLabel(room, roomsStore.getFloorById(room?.floorId))
})

/**
 * The planned day of work on Today that is not simply due today, straight
 * from the cycle block: the missed day with the end of its window where
 * that is a later day (C1c), or the day a still-open window belongs to.
 */
const plannedLabel = computed(() => {
	const planned = cycle.value?.centreOn
	if (!['overdue', 'possible'].includes(props.variant) || !planned) {
		return ''
	}
	const date = formatDayKey(planned)
	const end = cycle.value.windowEndOn
	return props.variant === 'overdue' && end && end !== planned
		? t('chorestrike', 'planned {date}, until {end}', { date, end: formatDayKey(end) })
		: t('chorestrike', 'planned {date}', { date })
})

/** Whole house-local days since the window ended; 0 on the same day. */
const overdueDays = computed(() => {
	if (props.variant !== 'overdue' || !cycle.value?.windowEndOn) {
		return 0
	}
	return daysBetweenKeys(cycle.value.windowEndOn, todayKeyInZone(houseStore.timezone)) ?? 0
})

function completeAs(split) {
	picking.value = false
	emit('complete', props.item, split)
}
</script>

<style scoped>
/* Every task is a card of its own, so a section reads as a list (GUI review of WP-B6). */
.task-row-group {
	margin-block: 0.4rem;
	border: 1px solid var(--color-border);
	border-radius: 12px;
	background: var(--color-main-background);
}

.task-row {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	padding: 0.5rem 0.75rem;
}

.task-row-group.open {
	border-color: var(--color-primary-element-light-hover);
	background: var(--color-primary-element-light);
}

.task-row-group.overdue {
	border-color: var(--color-element-error);
}

/* Inside the red block of Today the cards keep a gap to its edge. */
.task-row-group.overdue:not(.list) {
	margin-inline: 0.5rem;
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
	gap: 0.5rem;
}

.task-name {
	font-weight: 600;
	color: var(--color-main-text);
	overflow-wrap: anywhere;
}

.visibility-icon {
	color: var(--color-text-maxcontrast);
}

.overdue-badge {
	flex-shrink: 0;
	margin-inline-start: auto;
	padding: 0.15rem 0.5rem;
	border: 1px solid var(--color-element-error);
	border-radius: 10px;
	background: var(--color-main-background);
	color: var(--color-error-text);
	font-size: 0.8rem;
	font-weight: 700;
}

.task-planned,
.task-due {
	margin: 0.15rem 0 0;
	font-size: 0.85rem;
	color: var(--color-main-text);
}

.task-due.overdue {
	color: var(--color-error-text);
}

.expand-toggle {
	display: flex;
	flex-shrink: 0;
	align-items: center;
	justify-content: center;
	min-width: 44px;
	min-height: 44px;
	border: none;
	border-radius: 22px;
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
}

.expand-toggle:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}
</style>
