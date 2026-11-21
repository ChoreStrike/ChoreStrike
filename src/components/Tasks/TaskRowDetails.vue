<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="task-row-details" data-testid="task-row-details">
		<dl class="facts">
			<dt>{{ t('chorestrike', 'Rhythm') }}</dt>
			<dd>{{ rhythm }}</dd>
			<dt>{{ t('chorestrike', 'Window') }}</dt>
			<dd data-testid="task-row-window">
				{{ windowLabel }}
			</dd>
			<dt>{{ t('chorestrike', 'Last done') }}</dt>
			<dd>{{ lastDone }}</dd>
			<dt>{{ t('chorestrike', 'Assigned') }}</dt>
			<dd>{{ assigned }}</dd>
		</dl>
		<p v-if="text" class="task-text">
			{{ text }}
		</p>
		<ErrorState v-if="loadFailed" :message="t('chorestrike', 'Could not load the sub-tasks')" @retry="load" />
		<TaskChecklist v-else-if="checklistItems.length > 0"
			:items="checklistItems"
			:progress="checklistStore.getRoomTaskProgress(roomTask.id)"
			:readonly="!active"
			:roomTaskId="roomTask.id"
			@toggle="toggle" />
		<div v-if="active" class="row-actions">
			<PostponeButton :roomTaskId="roomTask.id" :cycle="cycle" :flexDays="task.flexDays ?? 0" />
			<button type="button"
				class="row-action"
				data-testid="task-record-other"
				@click="$emit('record')">
				{{ t('chorestrike', 'For someone else') }}
			</button>
			<button v-if="userStore.canManageTasks"
				type="button"
				class="row-action"
				data-testid="task-edit-button"
				@click="$emit('edit')">
				{{ t('chorestrike', 'Edit') }}
			</button>
			<SleepControl v-if="userStore.canManageTasks" :roomTaskId="roomTask.id" :cycle="cycle" />
		</div>
		<TaskHistory :roomTaskId="roomTask.id" />
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed, onMounted, ref, watch } from 'vue'
import ErrorState from '../Common/ErrorState.vue'
import PostponeButton from './PostponeButton.vue'
import SleepControl from './SleepControl.vue'
import TaskChecklist from './TaskChecklist.vue'
import TaskHistory from './TaskHistory.vue'
import { useChecklistStore } from '../../store/checklist.js'
import { useHouseStore } from '../../store/house.js'
import { useRoomTasksStore } from '../../store/roomTasks.js'
import { useUserStore } from '../../store/user.js'
import { dateKeyInZone, formatDayKey } from '../../utils/dateKeys.js'
import { showRefusal } from '../../utils/errorToast.js'
import { asleepLabel, memberNames, recurrenceLabel } from '../../utils/taskDisplay.js'

/**
 * The opened part of the one task row (CS-PLAN-091 WP-B6, FEAT-UX-050):
 * the task's facts, its sub-tasks to tick, postpone, record for someone
 * else, edit and sleep, and the history. There is no other details view.
 */
const props = defineProps({
	roomTask: { type: Object, required: true },
	task: { type: Object, required: true },
	/** The row's `cycle` block. */
	cycle: { type: Object, default: null },
	/** The assignees' user ids. */
	assignees: { type: Array, default: () => [] },
	/** How many sub-tasks the row knows of; unknown where its read does not count them. */
	subTaskCount: { type: Number, default: null },
})
defineEmits(['record', 'edit'])

const checklistStore = useChecklistStore()
const houseStore = useHouseStore()
const userStore = useUserStore()
const roomTasksStore = useRoomTasksStore()

const active = computed(() => props.roomTask.isActive !== false)
const checklistItems = computed(() => checklistStore.getTaskItems(props.task.id))
const rhythm = computed(() => (props.task.isVoluntary ? t('chorestrike', 'Bonus task') : recurrenceLabel(props.task)))
const lastDone = computed(() => (props.roomTask.lastCompleted
	? formatDayKey(dateKeyInZone(props.roomTask.lastCompleted, houseStore.timezone))
	: t('chorestrike', 'Not done yet')))
const assigned = computed(() => (props.assignees.length > 0 ? memberNames(props.assignees, userStore.houseUsers) : t('chorestrike', 'Unassigned')))
// Household text: the template renders it as plain escaped text.
const text = computed(() => [props.task.description, props.task.instructions].filter(Boolean).join('\n\n'))

const windowLabel = computed(() => {
	if (props.cycle?.state === 'asleep') {
		return asleepLabel(props.cycle)
	}
	if (!props.cycle?.windowStartOn) {
		return '—'
	}
	const from = formatDayKey(props.cycle.windowStartOn)
	return props.cycle.windowStartOn === props.cycle.windowEndOn ? from : `${from} – ${formatDayKey(props.cycle.windowEndOn)}`
})

const loadFailed = ref(false)

/**
 * Read the sub-tasks and, where there are any, their ticks; a task the
 * row knows to have none asks nothing. A failure offers a retry (the
 * store logged it).
 */
async function load() {
	loadFailed.value = false
	if (props.subTaskCount === 0) {
		return
	}
	try {
		const items = await checklistStore.fetchTaskItems(props.task.id)
		if (items.length > 0) {
			await checklistStore.fetchRoomTaskProgress(props.roomTask.id)
		}
	} catch {
		loadFailed.value = true
	}
}

onMounted(load)

// The next cycle starts without ticks (ADR-021 decision 3): the open row
// reads its progress again. The store logged a failed read.
watch(() => roomTasksStore.wasJustCompleted(props.roomTask.id), (completed) => {
	if (completed && checklistItems.value.length > 0) {
		checklistStore.fetchRoomTaskProgress(props.roomTask.id).catch(() => {})
	}
})

async function toggle({ itemId, roomTaskId }) {
	try {
		await checklistStore.toggleItem(roomTaskId, itemId)
	} catch (error) {
		showRefusal(error, t('chorestrike', 'Could not update the checklist'))
	}
}
</script>

<style scoped>
.task-row-details {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	padding: 0.5rem 0.75rem 0.75rem;
}

.facts {
	display: grid;
	grid-template-columns: max-content minmax(0, 1fr);
	gap: 0.25rem 0.75rem;
	margin: 0;
	font-size: 0.85rem;
}

.facts dt {
	color: var(--color-text-maxcontrast);
}

.facts dd {
	margin: 0;
	overflow-wrap: anywhere;
}

.task-text {
	margin: 0;
	font-size: 0.9rem;
	white-space: pre-line;
}

.row-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
}

.row-action {
	min-height: 44px;
	padding: 0 0.9rem;
	border: 1px solid var(--color-text-maxcontrast);
	border-radius: 22px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.row-action:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}
</style>
