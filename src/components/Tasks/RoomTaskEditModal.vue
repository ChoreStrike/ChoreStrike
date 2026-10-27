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

		<div v-if="canRecordLastDone" class="form-group">
			<label for="room-task-last-done">{{ t('chorestrike', 'Last done on') }}</label>
			<input id="room-task-last-done"
				v-model="lastDone"
				type="date"
				class="form-input"
				:max="todayKey"
				aria-describedby="room-task-last-done-hint"
				data-testid="room-task-last-done-input">
			<p id="room-task-last-done-hint" class="field-hint">
				{{ t('chorestrike', 'Optional. Shows as "Last cleaned" for the room. A task that repeats after completion is then next due one interval later.') }}
			</p>
			<p v-if="lastDoneRefusal"
				class="field-error"
				role="alert"
				data-testid="room-task-last-done-refusal">
				{{ lastDoneRefusal }}
			</p>
		</div>

		<div class="form-group">
			<label>{{ t('chorestrike', 'Assigned members') }}</label>
			<ErrorState v-if="membersError"
				data-testid="member-load-error"
				:message="t('chorestrike', 'Failed to load members')"
				@retry="loadMembers" />
			<p v-else-if="membersLoading">
				{{ t('chorestrike', 'Loading…') }}
			</p>
			<AssigneeChips v-else v-model="assigneeUserIds" :members="houseMembers" />
		</div>

		<div class="form-actions cs-dialog-actions">
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
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { computed, onMounted, ref } from 'vue'
import ErrorState from '../Common/ErrorState.vue'
import AssigneeChips from './AssigneeChips.vue'
import { useHouseStore } from '../../store/house.js'
import { useRoomTasksStore } from '../../store/roomTasks.js'
import { useUserStore } from '../../store/user.js'
import { dateKeyInZone } from '../../utils/dateKeys.js'
import { logger } from '../../utils/logger.js'

export default {
	name: 'RoomTaskEditModal',

	components: {
		AssigneeChips,
		ErrorState,
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
		const userStore = useUserStore()
		const saving = ref(false)
		// CS-PLAN-068: the assignee chips render ONLY from the members prop
		// (AssigneeChips performs no fetch of its own) — without this the
		// edit modal could never change assignments.
		const houseMembers = computed(() => userStore.houseUsers)
		const membersError = ref(false)
		const membersLoading = ref(false)
		const loadMembers = async () => {
			membersError.value = false
			membersLoading.value = true
			try {
				await userStore.fetchHouseUsers()
			} catch {
				// The store logs the failure; the form owns visible recovery.
				membersError.value = true
			} finally {
				membersLoading.value = false
			}
		}
		onMounted(() => {
			if (!userStore.houseUsers.length) {
				loadMembers()
			}
		})

		// House-local day keys (CS-PLAN-057): the picker default and the
		// change-detection baseline bucket the stored instant in the
		// household's timezone, matching the backend's reinterpretation of
		// the submitted Y-m-d key.
		const dueDate = ref(props.roomTask.nextDue ? dateKeyInZone(props.roomTask.nextDue, houseStore.timezone) : '')
		const assigneeUserIds = ref([...(props.roomTask.assignees || [])])
		const taskName = props.task?.name || t('chorestrike', 'Edit task')
		// FEAT-UX-045 (CS-PLAN-088 D-088-20): adults may record the day the
		// task was last done — today or earlier in the household's zone.
		const canRecordLastDone = computed(() => userStore.currentUser?.role !== 'child')
		const todayKey = dateKeyInZone(new Date(), houseStore.timezone)
		const lastDone = ref('')
		const lastDoneRefusal = ref('')

		const recordLastDone = async () => {
			lastDoneRefusal.value = ''
			try {
				await roomTasksStore.recordLastDone(props.roomTask.id, lastDone.value)
				return true
			} catch (error) {
				// A coded refusal (overdue, child day limit, date) keeps the
				// dialog open with its reason inline — never server text in a toast.
				if (['CONFLICT', 'VALIDATION_ERROR'].includes(error?.code) && error?.message) {
					lastDoneRefusal.value = error.message
				} else {
					showError(t('chorestrike', 'Could not record the last done date'))
				}
				return false
			}
		}

		const save = async () => {
			saving.value = true
			try {
				// Captured first: the last-done write may move the due date
				// and refresh the row; only an explicit picker change follows.
				const originalDue = props.roomTask.nextDue ? dateKeyInZone(props.roomTask.nextDue, houseStore.timezone) : ''
				if (lastDone.value && !(await recordLastDone())) {
					return
				}
				if (dueDate.value && dueDate.value !== originalDue) {
					await roomTasksStore.updateRoomTaskDueDate(props.roomTask.id, dueDate.value)
				}

				const originalAssignees = [...(props.roomTask.assignees || [])]
				const changedAssignees = originalAssignees.length !== assigneeUserIds.value.length
					|| originalAssignees.some((id) => !assigneeUserIds.value.includes(id))
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
			canRecordLastDone,
			todayKey,
			lastDone,
			lastDoneRefusal,
			assigneeUserIds,
			houseMembers,
			membersError,
			membersLoading,
			loadMembers,
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

.field-hint {
	margin-top: 0.4rem;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.field-error {
	margin-top: 0.5rem;
	color: var(--color-error-text);
	font-size: 0.85rem;
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
