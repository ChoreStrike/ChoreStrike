<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<!-- Expanded Details with Checklist -->
	<div class="task-details">
		<!-- Assignee multi-select (multi-assign) — owners and
		     members only; children have the execution surface -->
		<div v-if="canManageTasks" class="assignee-picker">
			<label class="assignee-label">{{ t('chorestrike', 'Assigned members') }}</label>
			<AssigneeChips
				:members="members"
				:model-value="assigneeDraft"
				@update:model-value="onAssigneeDraftChange" />
		</div>

		<TaskChecklist
			:items="checklistStore.getTaskItems(item.task.id)"
			:progress="checklistStore.getRoomTaskProgress(item.roomTask.id)"
			:can-edit="isHouseOwner"
			:readonly="!item.roomTask.isActive"
			:room-task-id="item.roomTask.id"
			:task-id="item.task.id"
			@toggle="handleToggleChecklistItem"
			@add="handleAddChecklistItem"
			@update="handleUpdateChecklistItem"
			@delete="handleDeleteChecklistItem"
			@reorder="handleReorderChecklistItems" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import AssigneeChips from './AssigneeChips.vue'
import TaskChecklist from './TaskChecklist.vue'
import { useChecklistStore } from '../../store/checklist.js'
import { logger } from '../../utils/logger.js'

/**
 * The expanded details surface of a TaskList row (CS-PLAN-067 §3.4): the
 * assignee multi-select (reuse of AssigneeChips, no per-row member fetch —
 * members arrive via the preloaded list) and the TaskChecklist wiring.
 *
 * The optimistic assignee DRAFT stays owned by the TaskList orchestrator
 * (CS-PLAN-065 §3.5): this component diffs the AssigneeChips value change
 * back to the single toggled user id and reports it, preserving the exact
 * interaction (one toggle → one setAssignees call → rollback on failure).
 */
export default {
	name: 'TaskListDetails',

	components: {
		AssigneeChips,
		TaskChecklist,
	},

	props: {
		/** Enriched row: { roomTask, task }. */
		item: {
			type: Object,
			required: true,
		},
		isHouseOwner: {
			type: Boolean,
			default: false,
		},
		canManageTasks: {
			type: Boolean,
			default: false,
		},
		/** Preloaded house members for the picker (no per-row fetch). */
		members: {
			type: Array,
			default: () => [],
		},
		/** Optimistic assignee draft (user-ID array) owned by TaskList. */
		assigneeDraft: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['toggle-assignee'],

	setup(props, { emit }) {
		const checklistStore = useChecklistStore()

		/**
		 * Diff the chips value to the single toggled member and report it —
		 * the draft update + optimistic persist lives in TaskList.
		 *
		 * @param {string[]} next The chips' full next user-ID array.
		 */
		const onAssigneeDraftChange = (next) => {
			const previous = props.assigneeDraft
			const added = next.find(id => !previous.includes(id))
			const removed = previous.find(id => !next.includes(id))
			const toggled = added ?? removed
			if (toggled !== undefined) {
				emit('toggle-assignee', props.item.roomTask.id, toggled)
			}
		}

		const handleToggleChecklistItem = async ({ itemId, roomTaskId }) => {
			await checklistStore.toggleItem(roomTaskId, itemId)
		}

		// --- Checklist editor wiring (CS-PLAN-042, UX-009) — owner-only via canEdit ---

		const handleAddChecklistItem = async ({ label, taskId }) => {
			try {
				await checklistStore.addItem(taskId, { label })
			} catch (error) {
				showError(t('chorestrike', 'Failed to add sub-task'))
				logger.error('Failed to add checklist item', error, { component: 'TaskListDetails', action: 'handleAddChecklistItem', taskId })
			}
		}

		const handleUpdateChecklistItem = async ({ itemId, label }) => {
			try {
				await checklistStore.updateItem(itemId, label)
			} catch (error) {
				showError(t('chorestrike', 'Failed to update sub-task'))
				logger.error('Failed to update checklist item', error, { component: 'TaskListDetails', action: 'handleUpdateChecklistItem', itemId })
			}
		}

		const handleDeleteChecklistItem = async ({ itemId, taskId }) => {
			try {
				await checklistStore.deleteItem(itemId, taskId)
			} catch (error) {
				showError(t('chorestrike', 'Failed to delete sub-task'))
				logger.error('Failed to delete checklist item', error, { component: 'TaskListDetails', action: 'handleDeleteChecklistItem', itemId })
			}
		}

		const handleReorderChecklistItems = async ({ itemIds, taskId }) => {
			try {
				await checklistStore.reorderItems(taskId, itemIds)
			} catch (error) {
				showError(t('chorestrike', 'Failed to reorder sub-tasks'))
				logger.error('Failed to reorder checklist items', error, { component: 'TaskListDetails', action: 'handleReorderChecklistItems', taskId })
			}
		}

		return {
			checklistStore,
			onAssigneeDraftChange,
			handleToggleChecklistItem,
			handleAddChecklistItem,
			handleUpdateChecklistItem,
			handleDeleteChecklistItem,
			handleReorderChecklistItems,
			t,
		}
	},
}
</script>

<style scoped>
.task-details {
	margin-top: 1rem;
	padding-top: 1rem;
	border-top: 1px solid var(--color-border);
}

.assignee-picker {
	margin-bottom: 1rem;
}

.assignee-label {
	display: block;
	margin-bottom: 0.4rem;
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-main-text);
}
</style>
