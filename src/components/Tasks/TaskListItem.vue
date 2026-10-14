<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div
		class="task-item"
		data-testid="task-item"
		:class="{ overdue: isOverdue(item.roomTask), completed: !item.roomTask.isActive }">
		<!-- Row selection exists only for the bulk-assign flow
		     (CS-PLAN-042). Children have no available bulk action,
		     so the non-actionable checkbox is hidden entirely
		     (CS-PLAN-066 §3.5 role controls). -->
		<div v-if="userStore.canManageTasks" class="task-select">
			<input
				type="checkbox"
				:data-testid="`select-task-${item.roomTask.id}`"
				:checked="selected"
				:aria-label="t('chorestrike', 'Select task')"
				@change="$emit('toggle-select', item.roomTask.id)">
		</div>
		<div class="task-main">
			<div class="task-status">
				<button
					v-if="item.roomTask.isActive"
					class="complete-button"
					data-testid="complete-task-button"
					:aria-label="t('chorestrike', 'Done')"
					:title="t('chorestrike', 'Mark as complete')"
					@click="$emit('complete', item.roomTask.id)">
					<Icon name="CheckCircle" :size="20" />
					<span>{{ t('chorestrike', 'Done') }}</span>
				</button>
				<span v-else class="completed-check">
					<Icon name="CheckCircle" :size="20" />
				</span>
				<button
					v-if="canClaim"
					class="claim-button"
					data-testid="claim-overdue-button"
					:aria-label="t('chorestrike', 'Claim & complete')"
					:title="t('chorestrike', 'Claim & complete')"
					@click="$emit('claim', item)">
					<Icon name="UserPlus" :size="16" />
				</button>
			</div>

			<div class="task-content">
				<div class="task-header">
					<h4 class="task-name">
						{{ item.task.name }}
					</h4>
					<span v-if="item.task.visibilityType === 'private'"
						class="visibility-icon"
						data-testid="visibility-lock-icon"
						:title="t('chorestrike', 'Private task')">
						<Icon name="Lock" :size="14" />
					</span>
					<span
						v-if="item.task.visibilityType === 'admin-only'"
						class="visibility-icon admin-only"
						data-testid="visibility-crown-icon"
						:title="t('chorestrike', 'Admin-only task')">
						<Icon name="Crown" :size="14" />
					</span>
					<span class="priority-badge" :class="priorityClass(item.task.priority)">
						{{ priorityLabel(item.task.priority) }}
					</span>
				</div>

				<p v-if="item.task.description" class="task-description">
					{{ item.task.description }}
				</p>

				<div class="task-meta">
					<span class="meta-item energy">
						<Icon name="Zap" :size="16" /> {{ item.task.energyCost }}
					</span>
					<span class="meta-item time">
						<Icon name="Clock" :size="16" /> {{ item.task.estimatedMinutes }}m
					</span>
					<span class="meta-item frequency">
						<Icon name="RotateCcw" :size="16" /> {{ frequencyLabel(item.task) }}
					</span>
					<span class="meta-item assigned" data-testid="task-assignees">
						<Icon name="Users" :size="16" />
						{{ assignmentLabel(item.roomTask.assignees, userStore.houseUsers) }}
					</span>
					<span v-if="assigneeCount > 1" class="meta-item teamwork-hint">
						<Icon name="Sparkles" :size="16" />
						{{ t('chorestrike', 'Teamwork bonus') }}
					</span>
				</div>

				<div class="task-due" :class="dueClass(item.roomTask, houseStore.timezone)">
					{{ dueText(item.roomTask, houseStore.timezone) }}
				</div>

				<!-- Flex Window Display -->
				<FlexWindowDisplay
					v-if="item.task.flexDays > 0"
					:earliest="flexWindow.earliest"
					:ideal="flexWindow.ideal"
					:latest="flexWindow.latest"
					:flex-days="item.task.flexDays"
					:compact="true"
					:show-status="true" />
			</div>

			<div class="task-actions">
				<button
					v-if="item.roomTask.isActive && userStore.canManageTasks"
					class="action-btn"
					:aria-label="t('chorestrike', 'Edit')"
					:title="t('chorestrike', 'Edit')"
					@click="$emit('edit', item.roomTask)">
					<Icon name="Edit" :size="16" />
				</button>
				<button
					class="action-btn"
					:aria-label="t('chorestrike', 'Show details')"
					:title="t('chorestrike', 'Show details')"
					@click="$emit('toggle-expand', item)">
					<Icon :name="expanded ? 'ChevronUp' : 'ChevronDown'" :size="16" />
				</button>
				<button
					v-if="userStore.canManageTasks"
					class="action-btn delete"
					:aria-label="t('chorestrike', 'Remove')"
					:title="t('chorestrike', 'Remove')"
					@click="$emit('delete', item.roomTask.id)">
					<Icon name="Trash" :size="16" />
				</button>
			</div>
		</div>

		<!-- Expanded Details with Checklist -->
		<TaskListDetails
			v-if="expanded"
			:item="item"
			:is-house-owner="userStore.isHouseOwner"
			:can-manage-tasks="userStore.canManageTasks"
			:members="userStore.houseUsers || []"
			:assignee-draft="assigneeDraft"
			@toggle-assignee="(...args) => $emit('toggle-assignee', ...args)" />
	</div>
</template>

<script>
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { Icon } from '../Common/index.js'
import FlexWindowDisplay from './FlexWindowDisplay.vue'
import TaskListDetails from './TaskListDetails.vue'
import { useUserStore } from '../../store/user.js'
import { useHouseStore } from '../../store/house.js'
import {
	assignmentLabel,
	isOverdue,
	priorityClass,
	priorityLabel,
	frequencyLabel,
	dueClass,
	dueText,
	flexWindowKeys,
} from '../../utils/taskDisplay.js'

/**
 * One TaskList row (CS-PLAN-067 §3.4): status/claim actions, definition
 * surface, due/flex-window presentation and the expand toggle. Display
 * helpers live in utils/taskDisplay.js; the expanded details surface lives
 * in TaskListDetails. Pure presentation — list state (selection, drafts,
 * expansion) stays owned by the TaskList orchestrator.
 */
export default {
	name: 'TaskListItem',

	components: {
		Icon,
		FlexWindowDisplay,
		TaskListDetails,
	},

	props: {
		/** Enriched row: { roomTask, task }. */
		item: {
			type: Object,
			required: true,
		},
		/** Whether this row is part of the bulk-assign selection. */
		selected: {
			type: Boolean,
			default: false,
		},
		/** Whether the expanded details are shown. */
		expanded: {
			type: Boolean,
			default: false,
		},
		/** Optimistic assignee draft (user-ID array) for this row. */
		assigneeDraft: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['complete', 'edit', 'delete', 'toggle-expand', 'toggle-select', 'claim', 'toggle-assignee'],

	setup(props) {
		const userStore = useUserStore()
		const houseStore = useHouseStore()

		const assigneeCount = computed(() => (props.item.roomTask.assignees || []).length)

		// Claimable: active, overdue, public, assigned to someone else
		// (CS-PLAN-060 §3.4). Same rule as before the extraction.
		const canClaim = computed(() => {
			const roomTask = props.item.roomTask
			if (!roomTask.isActive) return false
			if (!isOverdue(roomTask)) return false
			if ((props.item.task.visibilityType || 'public') !== 'public') return false
			const assignees = roomTask.assignees || []
			if (assignees.length === 0) return false
			const currentUserId = userStore.currentUser?.nextcloudUserId || userStore.userId
			return !assignees.includes(currentUserId)
		})

		const flexWindow = computed(() => flexWindowKeys(props.item))

		return {
			assignmentLabel,
			userStore,
			houseStore,
			assigneeCount,
			canClaim,
			flexWindow,
			isOverdue,
			priorityClass,
			priorityLabel,
			frequencyLabel,
			dueClass,
			dueText,
			t,
		}
	},
}
</script>

<style scoped>
.task-item {
	background: var(--color-background-hover);
	border-radius: 8px;
	padding: 1rem;
	border: 2px solid transparent;
	transition: all 0.2s ease;
}

.task-item:hover {
	border-color: var(--color-border);
}

.task-item.overdue {
	border-color: var(--color-error, #e74c3c);
	background: var(--color-error-hover, #fdf2f2);
}

.task-item.completed {
	opacity: 0.6;
	background: var(--color-background-dark);
}

.task-select {
	flex-shrink: 0;
	display: flex;
	align-items: flex-start;
	padding-top: 0.35rem;
}

.task-select input[type='checkbox'] {
	width: 16px;
	height: 16px;
	cursor: pointer;
	accent-color: var(--color-primary);
}

.task-main {
	display: flex;
	gap: 1rem;
	align-items: flex-start;
}

.task-status {
	flex-shrink: 0;
}

.complete-button {
	min-inline-size: 44px;
	min-block-size: 44px;
	padding: 0.5rem;
	gap: 0.35rem;
	border-radius: 8px;
	border: 2px solid var(--color-primary-element);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	cursor: pointer;
	font-size: 14px;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: all 0.2s ease;
}

.complete-button:hover {
	background: var(--color-primary-element-hover);
}

.completed-check {
	width: 44px;
	height: 44px;
	border-radius: 50%;
	background: var(--color-success);
	color: var(--color-success-text);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 14px;
}

.task-content {
	flex: 1;
	min-width: 0;
}

.task-header {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-bottom: 0.25rem;
}

.task-name {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
	color: var(--color-main-text);
}

.priority-badge {
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 500;
	text-transform: uppercase;
}

.priority-badge.low {
	background: var(--color-success-hover, #d5f4e6);
	color: var(--color-success, #27ae60);
}

.priority-badge.medium {
	background: var(--color-warning-hover, #fef9e7);
	color: var(--color-warning, #f39c12);
}

.priority-badge.high {
	background: var(--color-error-hover, #fdedec);
	color: var(--color-error, #e74c3c);
}

.task-description {
	margin: 0.25rem 0;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	line-height: 1.4;
}

.task-meta {
	display: flex;
	flex-wrap: wrap;
	gap: 0.75rem;
	margin-top: 0.5rem;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.task-due {
	margin-top: 0.5rem;
	font-size: 13px;
	font-weight: 500;
}

.task-due.overdue {
	color: var(--color-error, #e74c3c);
}

.task-due.today {
	color: var(--color-warning, #f39c12);
}

.task-due.completed {
	color: var(--color-success, #27ae60);
}

.task-actions {
	display: flex;
	gap: 0.25rem;
	flex-shrink: 0;
}

.action-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	min-width: 44px;
	min-height: 44px;
	padding: 0.25rem;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-text-maxcontrast);
	border-radius: 8px;
	cursor: pointer;
}

.action-btn:hover {
	background: var(--color-background-hover);
}

.action-btn:focus-visible,
.complete-button:focus-visible,
.claim-button:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.assigned {
	color: var(--color-main-text);
	overflow-wrap: anywhere;
}

.visibility-icon {
	display: inline-flex;
	align-items: center;
	color: var(--color-text-maxcontrast);
}

.visibility-icon.admin-only {
	color: var(--cs-tier-gold, #f0a500);
}

.teamwork-hint {
	color: var(--color-primary);
	font-weight: 500;
}

.claim-button {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 44px;
	height: 44px;
	margin-top: 0.25rem;
	border-radius: 50%;
	border: 1px solid var(--color-primary);
	background: transparent;
	color: var(--color-primary);
	cursor: pointer;
	transition: all 0.2s ease;
}

.claim-button:hover {
	background: var(--color-primary);
	color: var(--color-primary-text);
}

@media (max-width: 600px) {
	.task-main {
		flex-wrap: wrap;
	}

	.task-actions {
		width: 100%;
		justify-content: flex-end;
	}
}
</style>
