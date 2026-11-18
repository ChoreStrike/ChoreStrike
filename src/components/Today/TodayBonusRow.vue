<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="bonus-row-group">
		<div
			class="bonus-row"
			:class="{ 'cs-just-completed': roomTasksStore.wasJustCompleted(id) }"
			data-testid="today-bonus-item">
			<HoldButton
				v-if="canHold"
				:iconSize="22"
				:busy="roomTasksStore.isCompleting(id)"
				:data-testid="`today-bonus-complete-${id}`"
				:aria-label="t('chorestrike', 'Mark “{name}” as done', { name: item.task.name })"
				:title="t('chorestrike', 'Mark as done')"
				tappable
				@tap="picking = true"
				@confirm="emit('complete', item)" />
			<!-- Only once whom a hold credits is known and counted: never while the viewer is still unknown. -->
			<span v-else-if="holders.length > 0" class="counted-check" :data-testid="`today-bonus-counted-${id}`">
				<Icon name="CheckCircle" :size="22" />
			</span>

			<div class="bonus-info">
				<span class="bonus-name">{{ item.task.name }}</span>
				<TaskMeta
					:energy="taskEnergy(item)"
					:recurrence="t('chorestrike', 'Bonus task')"
					:location="location"
					:assignees="assigneeLabel" />
				<p v-if="countedLabel" class="bonus-counted" :data-testid="`today-bonus-counted-for-${id}`">
					{{ countedLabel }}
				</p>
			</div>

			<!-- Whoever a hold would credit is counted for today: someone
			     else can still be credited through the picker. -->
			<button
				v-if="!canHold && someoneLeft"
				type="button"
				class="bonus-other"
				:data-testid="`today-bonus-other-${id}`"
				@click="picking = true">
				{{ t('chorestrike', 'Someone else') }}
			</button>
		</div>

		<CompletionPicker
			v-if="picking"
			:roomTaskId="id"
			:taskId="item.task.id"
			:assignees="pickable"
			:unavailable="credited"
			:unavailableNote="t('chorestrike', 'counted today')"
			withoutSubTasks
			@close="picking = false"
			@confirm="completeAs" />
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import HoldButton from '../Common/HoldButton.vue'
import Icon from '../Common/Icon.vue'
import CompletionPicker from '../Tasks/CompletionPicker.vue'
import TaskMeta from '../Tasks/TaskMeta.vue'
import { useRoomsStore } from '../../store/rooms.js'
import { useRoomTasksStore } from '../../store/roomTasks.js'
import { useUserStore } from '../../store/user.js'
import { assignmentLabel, locationLabel, taskEnergy } from '../../utils/taskDisplay.js'

/**
 * One bonus task on Today (CS-PLAN-091 WP-B4, ADR-021 decision 8): never
 * due, always available, and it pays each person once per day. The row
 * says whom it already counted for today (`creditedToday` from the server)
 * and offers it to the others only; the server decides in the end.
 *
 * Holding credits the assignees, or the viewer for an unassigned task; a
 * short tap opens the picker. A bonus task has no sub-tasks to tick: it
 * has no cycle.
 */
const props = defineProps({
	/** Bonus row `{ roomTask, task, room, assignees, cycle, creditedToday }`. */
	item: { type: Object, required: true },
})

const emit = defineEmits(['complete'])

const roomTasksStore = useRoomTasksStore()
const roomsStore = useRoomsStore()
const userStore = useUserStore()

const picking = ref(false)

const id = computed(() => props.item.roomTask.id)
const credited = computed(() => props.item.creditedToday ?? [])
const assignees = computed(() => props.item.assignees ?? props.item.roomTask.assignees ?? [])
const myUserId = computed(() => userStore.currentUser?.nextcloudUserId ?? null)

/** Who a hold would credit: the assignees, or the viewer for an unassigned task. */
const holders = computed(() => (assignees.value.length > 0 || myUserId.value === null ? assignees.value : [myUserId.value]))
const canHold = computed(() => holders.value.length > 0 && holders.value.every((userId) => !credited.value.includes(userId)))
/** The picker opens with those of them ticked who are not counted yet. */
const pickable = computed(() => holders.value.filter((userId) => !credited.value.includes(userId)))
/** Unknown members (the list is not read yet) leave the offer open. */
const someoneLeft = computed(() => {
	const members = userStore.houseUsers ?? []
	return members.length === 0 || members.some((member) => !credited.value.includes(member.nextcloudUserId))
})

const location = computed(() => locationLabel(props.item.room, roomsStore.getFloorById(props.item.room?.floorId)))
const assigneeLabel = computed(() => assignmentLabel(assignees.value, userStore.houseUsers))
const countedLabel = computed(() => {
	if (credited.value.length === 0) {
		return ''
	}
	const names = credited.value.map((userId) => (userStore.houseUsers ?? [])
		.find((member) => member.nextcloudUserId === userId)?.displayName ?? userId)
	return t('chorestrike', 'Counted today for {names}', { names: names.join(', ') })
})

function completeAs(split) {
	picking.value = false
	emit('complete', props.item, split)
}
</script>

<style scoped>
.bonus-row {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	padding: 0.5rem 0.25rem;
	border-bottom: 1px solid var(--color-border);
}

.bonus-row-group:last-child .bonus-row {
	border-bottom: none;
}

.counted-check {
	display: flex;
	flex-shrink: 0;
	align-items: center;
	justify-content: center;
	width: 44px;
	height: 44px;
	color: var(--color-success-text);
}

.bonus-info {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 0.1rem;
	min-width: 0;
}

.bonus-name {
	font-weight: 600;
	color: var(--color-main-text);
	overflow-wrap: anywhere;
}

.bonus-counted {
	margin: 0.15rem 0 0;
	font-size: 0.85rem;
	color: var(--color-main-text);
}

.bonus-other {
	flex-shrink: 0;
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

.bonus-other:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

@media (max-width: 600px) {
	.bonus-row {
		flex-wrap: wrap;
	}

	.bonus-info {
		flex-basis: 60%;
	}

	.bonus-other {
		margin-inline-start: auto;
	}
}
</style>
