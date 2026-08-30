<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<form class="room-task-edit-modal" data-testid="room-task-edit-modal" @submit.prevent="save">
		<h3>{{ taskName }}</h3>

		<div class="form-group">
			<label for="room-task-due">{{ t('chorestrike', 'Due Date') }}</label>
			<input id="room-task-due"
				v-model="dueDate"
				type="date"
				class="form-input"
				data-testid="room-task-due-input"
				required>
		</div>

		<div class="form-group">
			<label>{{ t('chorestrike', 'Assigned members') }}</label>
			<AssigneeChips v-model="assigneeUserIds" />
		</div>

		<div class="form-actions">
			<button type="button"
				class="secondary-button"
				data-testid="room-task-edit-cancel"
				@click="$emit('cancel')">
				{{ t('chorestrike', 'Cancel') }}
			</button>
			<button type="submit"
				class="primary-button"
				data-testid="room-task-edit-save"
				:disabled="saving">
				{{ t('chorestrike', 'Save') }}
			</button>
		</div>
	</form>
</template>

<script>
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import { useRoomTasksStore } from '../../store/roomTasks.js'
import { useHouseStore } from '../../store/house.js'
import { dateKeyInZone } from '../../utils/dateKeys.js'
import { logger } from '../../utils/logger.js'
import AssigneeChips from './AssigneeChips.vue'

export default {
	name: 'RoomTaskEditModal',

	components: {
		AssigneeChips,
	},

	props: {
		// Room task row incl. `assignees` (from the room task list)
		roomTask: {
			type: Object,
			required: true,
		},
		// Task definition (for the modal title)
		task: {
			type: Object,
			default: null,
		},
	},

	emits: ['cancel', 'saved'],

	setup(props, { emit }) {
		const roomTasksStore = useRoomTasksStore()
		const houseStore = useHouseStore()
		const saving = ref(false)

		// House-local day keys (CS-PLAN-057): the picker default and the
		// change-detection baseline bucket the stored instant in the
		// household's timezone, matching the backend's reinterpretation of
		// the submitted Y-m-d key.
		const dueDate = ref(props.roomTask.nextDue ? dateKeyInZone(props.roomTask.nextDue, houseStore.timezone) : '')
		const assigneeUserIds = ref([...(props.roomTask.assignees || [])])
		const taskName = props.task?.name || t('chorestrike', 'Edit task')

		const save = async () => {
			saving.value = true
			try {
				const originalDue = props.roomTask.nextDue ? dateKeyInZone(props.roomTask.nextDue, houseStore.timezone) : ''
				if (dueDate.value && dueDate.value !== originalDue) {
					await roomTasksStore.updateRoomTaskDueDate(props.roomTask.id, dueDate.value)
				}

				const originalAssignees = [...(props.roomTask.assignees || [])]
				const changedAssignees = originalAssignees.length !== assigneeUserIds.value.length
					|| originalAssignees.some(id => !assigneeUserIds.value.includes(id))
				if (changedAssignees) {
					try {
						await roomTasksStore.setAssignees(props.roomTask.id, [...assigneeUserIds.value])
					} catch (error) {
						showWarning(t('chorestrike', 'Due date saved, but assigning members failed'))
						logger.error('Failed to set assignees', error, { component: 'RoomTaskEditModal', action: 'save', roomTaskId: props.roomTask.id })
						emit('saved')
						return
					}
				}

				showSuccess(t('chorestrike', 'Task updated'))
				emit('saved')
			} catch (error) {
				showError(t('chorestrike', 'Failed to update task'))
				logger.error('Failed to update room task', error, { component: 'RoomTaskEditModal', action: 'save', roomTaskId: props.roomTask.id })
			} finally {
				saving.value = false
			}
		}

		return {
			taskName,
			dueDate,
			assigneeUserIds,
			saving,
			save,
			t,
		}
	},
}
</script>

<style scoped>
.room-task-edit-modal {
	padding: 1.5rem;
}

.room-task-edit-modal h3 {
	margin: 0 0 1rem 0;
	font-size: 1.2rem;
	color: var(--color-main-text);
}

.form-group {
	margin-bottom: 1rem;
}

.form-group label {
	display: block;
	margin-bottom: 0.4rem;
	font-size: 0.9rem;
	font-weight: 600;
}

.form-input {
	width: 100%;
	padding: 0.6rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.95rem;
}

.form-actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.75rem;
	margin-top: 1.5rem;
	padding-top: 1rem;
	border-top: 1px solid var(--color-border);
}

.primary-button,
.secondary-button {
	min-height: 44px;
	padding: 0.6rem 1.2rem;
	border: none;
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.95rem;
}

.primary-button {
	background: var(--color-primary);
	color: var(--color-primary-text);
}

.primary-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.secondary-button {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}
</style>
