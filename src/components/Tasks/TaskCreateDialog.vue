<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<Modal persistent
		:aria-label="t('chorestrike', 'New Task')"
		@close="$emit('close')">
		<!-- Inside a room the templates come first, the custom form one step
		     behind them (CS-PLAN-090 D-090-12). -->
		<TaskTemplatePicker
			v-if="room && mode === 'template'"
			:roomId="room.id"
			:roomType="room.roomType || 'generic'"
			data-testid="add-task-picker"
			@custom="mode = 'custom'"
			@created="$emit('close')"
			@error="handleTemplateError" />
		<TaskEditForm
			v-else
			:presetRoomId="room?.id"
			:initialRoomId="prefill.roomId"
			:initialFloorId="prefill.floorId"
			:initialAssigneeIds="prefill.assigneeIds"
			:loading="creating"
			@submit="handleAddTask"
			@cancel="$emit('close')" />
	</Modal>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import Modal from '../Common/Modal.vue'
import TaskEditForm from './TaskEditForm.vue'
import TaskTemplatePicker from './TaskTemplatePicker.vue'
import { useRoomTasksStore } from '../../store/roomTasks.js'
import { logger } from '../../utils/logger.js'

/**
 * The one dialog that creates a task (CS-PLAN-090 WP-C). Without a room it
 * is the form, prefilled from the list's filters; opened from a room
 * heading it starts with that room's templates.
 */
export default {
	name: 'TaskCreateDialog',

	components: { Modal, TaskEditForm, TaskTemplatePicker },

	props: {
		/** The room the task is created in, or null for a free choice. */
		room: {
			type: Object,
			default: null,
		},

		/** Form defaults without a room: `{ roomId, floorId, assigneeIds }`. */
		prefill: {
			type: Object,
			default: () => ({ roomId: null, floorId: null, assigneeIds: [] }),
		},
	},

	emits: ['close'],

	setup() {
		return { roomTasksStore: useRoomTasksStore() }
	},

	data() {
		return { mode: 'template', creating: false }
	},

	methods: {
		t,

		/**
		 * The atomic creation aggregate (CS-PLAN-063 §3.5): exactly one
		 * request. On failure the dialog stays open with the input kept (no
		 * partial success, no orphan definition). The store reconciles the
		 * response into the instance and definition stores, so no re-fetch
		 * follows (CS-PLAN-067 §3.4).
		 * @param {object} taskData Form payload: definition + roomId + assigneeUserIds + firstDate.
		 */
		async handleAddTask(taskData) {
			const { roomId, assigneeUserIds, firstDate, ...definition } = taskData
			const targetRoomId = this.room ? this.room.id : roomId
			this.creating = true
			try {
				await this.roomTasksStore.createTaskAggregate({
					roomId: targetRoomId,
					task: definition,
					assigneeUserIds: assigneeUserIds || [],
					firstDate: firstDate || null,
				})
				showSuccess(t('chorestrike', 'Task created'))
				this.$emit('close')
			} catch (error) {
				showError(t('chorestrike', 'Failed to create task'))
				logger.error('Failed to create task', error, { component: 'TaskCreateDialog', action: 'handleAddTask', roomId: targetRoomId })
			} finally {
				this.creating = false
			}
		},

		handleTemplateError(error) {
			// The picker shows its own failure state; this toast backs it up.
			showError(t('chorestrike', 'Failed to create task'))
			logger.error('Failed to create task from template', error, { component: 'TaskCreateDialog', action: 'handleTemplateError', roomId: this.room?.id })
		},
	},
}
</script>
