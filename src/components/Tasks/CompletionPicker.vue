<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<!-- The dialog builds its rows once: it opens when the people are read. -->
	<ParticipationDialog
		v-if="peopleReady"
		:members="members"
		:preselected="preselected"
		:unavailable="unavailable"
		:unavailableNote="unavailableNote"
		:hint="t('chorestrike', 'Tick everyone who did the work. Their shares are equal unless you change them.')"
		:confirmLabel="t('chorestrike', 'Done')"
		@close="$emit('close')"
		@confirm="$emit('confirm', $event)">
		<div v-if="!withoutSubTasks && items.length > 0" class="picker-subtasks" data-testid="picker-subtasks">
			<TaskChecklist
				:items="items"
				:progress="checklistStore.getRoomTaskProgress(roomTaskId)"
				:roomTaskId="roomTaskId"
				@toggle="toggle" />
			<p class="picker-note" data-testid="picker-subtasks-note">
				{{ t('chorestrike', 'Ticks are saved at once. “Done” completes the task with every sub-task.') }}
			</p>
		</div>
	</ParticipationDialog>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed, onMounted, ref } from 'vue'
import ParticipationDialog from '../Common/ParticipationDialog.vue'
import TaskChecklist from './TaskChecklist.vue'
import { useChecklistStore } from '../../store/checklist.js'
import { useUserStore } from '../../store/user.js'
import { showRefusal } from '../../utils/errorToast.js'
import { logger } from '../../utils/logger.js'

/**
 * What a short tap on the Done button opens (CS-PLAN-091 WP-B2): who did
 * the work and, for a task with sub-tasks, which of them are done. A tick
 * is saved at once and leaves the task open (D-089-02); confirming emits
 * the split, and the completion marks every sub-task done on the server
 * (ADR-021 decision 4). Anyone in the household can be credited, the
 * recorder need not take part; the server refuses a member who may not
 * see the task.
 */
const props = defineProps({
	roomTaskId: { type: Number, required: true },
	taskId: { type: Number, required: true },
	/** The task's assignees: who a hold would credit. */
	assignees: { type: Array, default: () => [] },
	/** User ids that cannot be credited, with the reason shown next to them. */
	unavailable: { type: Array, default: () => [] },
	unavailableNote: { type: String, default: '' },
	/** The task is known to have no sub-tasks to tick (none defined, or a bonus task, which has no cycle): nothing is read. */
	withoutSubTasks: { type: Boolean, default: false },
})

defineEmits(['close', 'confirm'])

const checklistStore = useChecklistStore()
const userStore = useUserStore()

const items = computed(() => checklistStore.getTaskItems(props.taskId))
const members = computed(() => (userStore.houseUsers ?? []).map((member) => ({
	userId: member.nextcloudUserId,
	displayName: member.displayName ?? member.nextcloudUserId,
})))
// Unassigned work is the recorder's until someone else is ticked.
const preselected = computed(() => {
	const actorUserId = userStore.currentUser?.nextcloudUserId ?? userStore.userId ?? null
	return props.assignees.length > 0 || actorUserId === null ? props.assignees : [actorUserId]
})
const peopleReady = ref(Boolean(userStore.currentUser) && (userStore.houseUsers ?? []).length > 0)

/**
 * The household and the recorder, read where the view has not yet (or its
 * read failed): an empty picker could credit nobody.
 */
async function loadPeople() {
	const reads = []
	if (!userStore.currentUser) {
		reads.push(userStore.initializeCurrentUser())
	}
	if ((userStore.houseUsers ?? []).length === 0) {
		reads.push(userStore.fetchHouseUsers())
	}
	// The user store logs a failed read; the dialog opens with what there is.
	await Promise.allSettled(reads)
	peopleReady.value = true
}

onMounted(async () => {
	if (!peopleReady.value) {
		loadPeople()
	}
	if (props.withoutSubTasks) {
		return
	}
	try {
		// The ticks are read only for a task that has sub-tasks.
		const defined = await checklistStore.fetchTaskItems(props.taskId)
		if ((defined ?? []).length > 0) {
			await checklistStore.fetchRoomTaskProgress(props.roomTaskId)
		}
	} catch (error) {
		// The people can still be picked; the sub-tasks are done with the task.
		logger.warn('Sub-tasks of the picker did not load', {
			component: 'CompletionPicker',
			roomTaskId: props.roomTaskId,
			status: error?.status,
			errorCode: error?.code,
		})
	}
})

async function toggle({ itemId, roomTaskId }) {
	try {
		await checklistStore.toggleItem(roomTaskId, itemId)
	} catch (error) {
		// The server's reason, as the opened row shows it; the store logged the failure.
		showRefusal(error, t('chorestrike', 'Could not update the checklist'))
	}
}
</script>

<style scoped>
.picker-subtasks {
	margin-block-start: 0.5rem;
	padding-block-start: 0.5rem;
	border-block-start: 1px solid var(--color-border);
}

.picker-note {
	margin: 0.5rem 0 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}
</style>
