<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="task-list" data-testid="room-task-list">
		<!-- Skeleton Loading State -->
		<div v-if="loading" class="skeleton-loading">
			<div v-for="i in 5" :key="i" class="skeleton-task-item">
				<div class="skeleton-task-status" />
				<div class="skeleton-task-content">
					<div class="skeleton-task-header">
						<div class="skeleton-task-name" />
						<div class="skeleton-priority" />
					</div>
					<div class="skeleton-task-meta">
						<div class="skeleton-meta-item" />
						<div class="skeleton-meta-item" />
						<div class="skeleton-meta-item" />
					</div>
				</div>
			</div>
		</div>

		<div v-else-if="!roomTasks || roomTasks.length === 0" class="empty-state">
			<div class="empty-icon">
				<Icon name="List" :size="48" />
			</div>
			<p>{{ t('chorestrike', 'No tasks yet') }}</p>
			<p class="empty-hint">
				{{ t('chorestrike', 'Add your first task to get started') }}
			</p>
		</div>

		<div v-else class="task-items">
			<div
				v-for="item in sortedTasks"
				:key="item.roomTask.id"
				class="task-item"
				:class="{ overdue: isOverdue(item.roomTask), completed: !item.roomTask.isActive }">
				<div class="task-main">
					<div class="task-status">
						<button
							v-if="item.roomTask.isActive"
							class="complete-button"
							data-testid="complete-task-button"
							:title="t('chorestrike', 'Mark as complete')"
							@click="$emit('complete', item.roomTask.id)">
							<Icon name="Circle" :size="20" />
						</button>
						<span v-else class="completed-check">
							<Icon name="CheckCircle" :size="20" />
						</span>
						<button
							v-if="canClaim(item)"
							class="claim-button"
							data-testid="claim-overdue-button"
							:title="t('chorestrike', 'Claim & complete')"
							@click="openClaimDialog(item)">
							<Icon name="UserPlus" :size="16" />
						</button>
					</div>

					<div class="task-content">
						<div class="task-header">
							<h4 class="task-name">
								{{ item.task.name }}
							</h4>
							<span v-if="item.task.visibilityType === 'private' || item.task.isPrivate" class="visibility-icon" :title="t('chorestrike', 'Private task')">
								<Icon name="Lock" :size="14" />
							</span>
							<span v-if="item.task.visibilityType === 'admin-only'" class="visibility-icon admin-only" :title="t('chorestrike', 'Admin-only task')">
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
							<span v-if="assigneeCount(item.roomTask) > 0" class="meta-item assigned">
								<Icon name="Users" :size="16" />
								{{ assigneeCount(item.roomTask) > 1
									? t('chorestrike', '{count} assigned', { count: assigneeCount(item.roomTask) })
									: t('chorestrike', 'Assigned') }}
							</span>
							<span v-if="assigneeCount(item.roomTask) > 1" class="meta-item teamwork-hint">
								<Icon name="Sparkles" :size="16" />
								{{ t('chorestrike', 'Teamwork bonus') }}
							</span>
						</div>

						<div class="task-due" :class="dueClass(item.roomTask)">
							{{ dueText(item.roomTask) }}
						</div>

						<!-- Flex Window Display -->
						<FlexWindowDisplay
							v-if="item.task.flexDays > 0"
							:earliest="getEarliestDate(item)"
							:ideal="getIdealDate(item)"
							:latest="getLatestDate(item)"
							:flex-days="item.task.flexDays"
							:compact="true"
							:show-status="true" />
					</div>

					<div class="task-actions">
						<button
							v-if="item.roomTask.isActive"
							class="action-btn"
							:title="t('chorestrike', 'Edit')"
							@click="$emit('edit', item.roomTask)">
							<Icon name="Edit" :size="16" />
						</button>
						<button
							class="action-btn"
							:title="t('chorestrike', 'Show details')"
							@click="toggleExpand(item)">
							<Icon :name="isExpanded(item.roomTask.id) ? 'ChevronUp' : 'ChevronDown'" :size="16" />
						</button>
						<button
							class="action-btn delete"
							:title="t('chorestrike', 'Remove')"
							@click="$emit('delete', item.roomTask.id)">
							<Icon name="Trash" :size="16" />
						</button>
					</div>
				</div>

				<!-- Expanded Details with Checklist -->
				<div v-if="isExpanded(item.roomTask.id)" class="task-details">
					<!-- Assignee multi-select (multi-assign) -->
					<div class="assignee-picker">
						<label class="assignee-label">{{ t('chorestrike', 'Assigned members') }}</label>
						<div class="assignee-chips">
							<label
								v-for="member in houseMembers"
								:key="member.nextcloudUserId"
								class="assignee-chip"
								:class="{ selected: isAssignee(item.roomTask.id, member.nextcloudUserId) }">
								<input
									type="checkbox"
									:checked="isAssignee(item.roomTask.id, member.nextcloudUserId)"
									@change="toggleAssignee(item.roomTask.id, member.nextcloudUserId)">
								<span>{{ member.displayName }}</span>
							</label>
							<p v-if="houseMembers.length === 0" class="field-error">
								{{ t('chorestrike', 'No house members found') }}
							</p>
						</div>
					</div>

					<TaskChecklist
						:items="getTaskItems(item.task.id)"
						:progress="getRoomTaskProgress(item.roomTask.id)"
						:can-edit="false"
						:readonly="!item.roomTask.isActive"
						:room-task-id="item.roomTask.id"
						@toggle="handleToggleChecklistItem" />
				</div>
			</div>
		</div>

		<OverdueClaimDialog
			:visible="claimDialogVisible"
			:assignee-name="claimAssigneeName"
			@confirm="confirmClaim"
			@cancel="claimDialogVisible = false" />
	</div>
</template>

<script>
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { Icon } from '../Common/index.js'
import TaskChecklist from './TaskChecklist.vue'
import FlexWindowDisplay from './FlexWindowDisplay.vue'
import OverdueClaimDialog from './OverdueClaimDialog.vue'
import { useChecklistStore } from '../../store/checklist.js'
import { useUserStore } from '../../store/user.js'
import { useRoomTasksStore } from '../../store/roomTasks.js'

export default {
	name: 'TaskList',

	components: {
		Icon,
		TaskChecklist,
		FlexWindowDisplay,
		OverdueClaimDialog,
	},

	props: {
		roomTasks: {
			type: Array,
			default: () => [],
		},
		loading: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['complete', 'edit', 'delete'],

	setup(props, { emit }) {
		const checklistStore = useChecklistStore()
		const userStore = useUserStore()
		const roomTasksStore = useRoomTasksStore()
		const expandedTaskId = ref(null)
		// Local draft of assignee user IDs per expanded room task (optimistic)
		const assigneeDraft = ref({})

		const sortedTasks = computed(() => {
			const tasks = props.roomTasks || []
			return [...tasks].sort((a, b) => {
				// Sort by due date
				const dateA = new Date(a.roomTask.nextDue)
				const dateB = new Date(b.roomTask.nextDue)
				if (dateA.getTime() !== dateB.getTime()) return dateA.getTime() - dateB.getTime()
				// Then by priority (high first)
				return b.task.priority - a.task.priority
			})
		})

		const isOverdue = (roomTask) => {
			if (!roomTask.isActive) return false
			return new Date(roomTask.nextDue) < new Date()
		}

		const priorityClass = (priority) => {
			return { 1: 'low', 2: 'medium', 3: 'high' }[priority] || 'medium'
		}

		const priorityLabel = (priority) => {
			return { 1: t('chorestrike', 'Low'), 2: t('chorestrike', 'Medium'), 3: t('chorestrike', 'High') }[priority]
		}

		const frequencyLabel = (task) => {
			const labels = {
				daily: t('chorestrike', 'Daily'),
				weekly: task.frequencyValue === 1 ? t('chorestrike', 'Weekly') : t('chorestrike', 'Every {n} weeks', { n: task.frequencyValue }),
				monthly: task.frequencyValue === 1 ? t('chorestrike', 'Monthly') : t('chorestrike', 'Every {n} months', { n: task.frequencyValue }),
				custom: t('chorestrike', 'Custom'),
			}
			return labels[task.frequencyType] || task.frequencyType
		}

		const dueClass = (roomTask) => {
			if (!roomTask.isActive) return 'completed'
			const due = new Date(roomTask.nextDue)
			const now = new Date()
			if (due < now) return 'overdue'
			const today = new Date()
			today.setHours(0, 0, 0, 0)
			const tomorrow = new Date(today)
			tomorrow.setDate(tomorrow.getDate() + 1)
			if (due >= today && due < tomorrow) return 'today'
			return 'upcoming'
		}

		const dueText = (roomTask) => {
			if (!roomTask.isActive) return t('chorestrike', 'Completed')
			const due = new Date(roomTask.nextDue)
			const now = new Date()
			const diffDays = Math.ceil((due - now) / (1000 * 60 * 60 * 24))
			if (diffDays < 0) return t('chorestrike', 'Overdue by {days} days', { days: Math.abs(diffDays) })
			if (diffDays === 0) return t('chorestrike', 'Due today')
			if (diffDays === 1) return t('chorestrike', 'Due tomorrow')
			return t('chorestrike', 'Due in {days} days', { days: diffDays })
		}

		const toggleExpand = async (item) => {
			if (expandedTaskId.value === item.roomTask.id) {
				expandedTaskId.value = null
			} else {
				expandedTaskId.value = item.roomTask.id
				// Seed the assignee draft from the current room task assignees
				assigneeDraft.value = {
					...assigneeDraft.value,
					[item.roomTask.id]: [...(item.roomTask.assignees || [])],
				}
				// Lazy-load house members for the assignee picker
				if ((userStore.houseUsers || []).length === 0) {
					try {
						await userStore.fetchHouseUsers()
					} catch (error) {
						// non-fatal: picker will show an empty hint
					}
				}
				// Load checklist data
				await checklistStore.fetchTaskItems(item.task.id)
				await checklistStore.fetchRoomTaskProgress(item.roomTask.id)
			}
		}

		const houseMembers = computed(() => userStore.houseUsers || [])

		const isAssignee = (roomTaskId, userId) => {
			const draft = assigneeDraft.value[roomTaskId] || []
			return draft.includes(userId)
		}

		const toggleAssignee = async (roomTaskId, userId) => {
			const current = new Set(assigneeDraft.value[roomTaskId] || [])
			if (current.has(userId)) {
				current.delete(userId)
			} else {
				current.add(userId)
			}
			const next = [...current]
			assigneeDraft.value = { ...assigneeDraft.value, [roomTaskId]: next }
			try {
				await roomTasksStore.setAssignees(roomTaskId, next)
			} catch (error) {
				// Revert the draft on failure so the UI matches the server state
				const previous = next.includes(userId)
					? next.filter(id => id !== userId)
					: [...next, userId]
				assigneeDraft.value = { ...assigneeDraft.value, [roomTaskId]: previous }
			}
		}

		const assigneeCount = (roomTask) => {
			const ids = roomTask.assignees || (roomTask.assignedToUserId ? [roomTask.assignedToUserId] : [])
			return ids.length
		}

		const claimDialogVisible = ref(false)
		const claimAssigneeName = ref('')
		const claimRoomTaskId = ref(null)

		const currentUserId = computed(() => userStore.currentUser?.nextcloudUserId || userStore.userId)

		const canClaim = (item) => {
			if (!item.roomTask.isActive) return false
			if (!isOverdue(item.roomTask)) return false
			const visibility = item.task.visibilityType || (item.task.isPrivate ? 'private' : 'public')
			if (visibility !== 'public') return false
			const mergedAssignees = [...(item.roomTask.assignees || [])]
			if (item.roomTask.assignedToUserId && !mergedAssignees.includes(item.roomTask.assignedToUserId)) {
				mergedAssignees.push(item.roomTask.assignedToUserId)
			}
			if (mergedAssignees.length === 0) return false
			return !mergedAssignees.includes(currentUserId.value)
		}

		const openClaimDialog = (item) => {
			claimRoomTaskId.value = item.roomTask.id
			const assigneeId = item.roomTask.assignedToUserId
			const member = (userStore.houseUsers || []).find(u => u.nextcloudUserId === assigneeId)
			claimAssigneeName.value = member?.displayName || assigneeId || t('chorestrike', 'another member')
			claimDialogVisible.value = true
		}

		const confirmClaim = async () => {
			claimDialogVisible.value = false
			if (claimRoomTaskId.value) {
				emit('complete', claimRoomTaskId.value)
			}
		}

		const isExpanded = (roomTaskId) => {
			return expandedTaskId.value === roomTaskId
		}

		const getTaskItems = (taskId) => {
			return checklistStore.getTaskItems(taskId)
		}

		const getRoomTaskProgress = (roomTaskId) => {
			return checklistStore.getRoomTaskProgress(roomTaskId)
		}

		const handleToggleChecklistItem = async ({ itemId, roomTaskId }) => {
			await checklistStore.toggleItem(roomTaskId, itemId)
		}

		/**
		 * Calculate earliest date for flex window
		 * @param {object} item - Task item with roomTask and task
		 * @return {string|null} ISO date string or null
		 */
		const getEarliestDate = (item) => {
			const ideal = item.roomTask.idealDueDate || item.roomTask.nextDue
			if (!ideal || item.task.flexDays <= 0) return null
			const date = new Date(ideal)
			date.setDate(date.getDate() - item.task.flexDays)
			return date.toISOString()
		}

		/**
		 * Get ideal due date for flex window
		 * @param {object} item - Task item with roomTask and task
		 * @return {string|null} ISO date string or null
		 */
		const getIdealDate = (item) => {
			return item.roomTask.idealDueDate || item.roomTask.nextDue
		}

		/**
		 * Calculate latest date for flex window
		 * @param {object} item - Task item with roomTask and task
		 * @return {string|null} ISO date string or null
		 */
		const getLatestDate = (item) => {
			const ideal = item.roomTask.idealDueDate || item.roomTask.nextDue
			if (!ideal || item.task.flexDays <= 0) return null
			const date = new Date(ideal)
			date.setDate(date.getDate() + item.task.flexDays)
			return date.toISOString()
		}

		return {
			sortedTasks,
			expandedTaskId,
			isOverdue,
			priorityClass,
			priorityLabel,
			frequencyLabel,
			dueClass,
			dueText,
			toggleExpand,
			isExpanded,
			getTaskItems,
			getRoomTaskProgress,
			handleToggleChecklistItem,
			getEarliestDate,
			getIdealDate,
			getLatestDate,
			houseMembers,
			isAssignee,
			toggleAssignee,
			assigneeCount,
			canClaim,
			openClaimDialog,
			confirmClaim,
			claimDialogVisible,
			claimAssigneeName,
			t,
		}
	},
}
</script>

<style scoped>
.task-list {
	width: 100%;
}

.loading-state {
	text-align: center;
	padding: 2rem;
	color: var(--color-text-maxcontrast);
}

.loading-spinner {
	width: 32px;
	height: 32px;
	border: 3px solid var(--color-border);
	border-top: 3px solid var(--color-primary);
	border-radius: 50%;
	animation: spin 1s linear infinite;
	margin: 0 auto 0.5rem;
}

@keyframes spin {
	0% { transform: rotate(0deg); }
	100% { transform: rotate(360deg); }
}

.empty-state {
	text-align: center;
	padding: 3rem 2rem;
	color: var(--color-text-maxcontrast);
}

.empty-icon {
	font-size: 48px;
	margin-bottom: 0.5rem;
}

.empty-hint {
	font-size: 14px;
	opacity: 0.7;
}

.task-items {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

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
	border-color: #e74c3c;
	background: #fdf2f2;
}

.task-item.completed {
	opacity: 0.6;
	background: var(--color-background-dark);
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
	width: 28px;
	height: 28px;
	border-radius: 50%;
	border: 2px solid var(--color-primary);
	background: transparent;
	color: var(--color-primary);
	cursor: pointer;
	font-size: 14px;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: all 0.2s ease;
}

.complete-button:hover {
	background: var(--color-primary);
	color: white;
}

.completed-check {
	width: 28px;
	height: 28px;
	border-radius: 50%;
	background: #2ecc71;
	color: white;
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
	background: #d5f4e6;
	color: #27ae60;
}

.priority-badge.medium {
	background: #fef9e7;
	color: #f39c12;
}

.priority-badge.high {
	background: #fdedec;
	color: #e74c3c;
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
	color: #e74c3c;
}

.task-due.today {
	color: #f39c12;
}

.task-due.completed {
	color: #27ae60;
}

.task-actions {
	display: flex;
	gap: 0.25rem;
	flex-shrink: 0;
}

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

.assignee-chips {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
}

.assignee-chip {
	display: flex;
	align-items: center;
	gap: 0.3rem;
	padding: 0.35rem 0.7rem;
	border: 1px solid var(--color-border);
	border-radius: 20px;
	cursor: pointer;
	background: var(--color-main-background);
	font-size: 0.85rem;
	transition: all 0.2s;
}

.assignee-chip:hover {
	border-color: var(--color-primary);
}

.assignee-chip input[type="checkbox"] {
	display: none;
}

.assignee-chip.selected {
	background: var(--color-primary-light);
	border-color: var(--color-primary);
	color: var(--color-primary);
	font-weight: 600;
}

.field-error {
	color: var(--color-error);
	font-size: 0.85rem;
	margin: 0.5rem 0 0 0;
}

.action-btn {
	padding: 0.25rem;
	background: transparent;
	border: none;
	cursor: pointer;
	opacity: 0.6;
	transition: opacity 0.2s ease;
}

.action-btn:hover {
	opacity: 1;
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
	width: 24px;
	height: 24px;
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
	color: white;
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

/* Skeleton Loading Styles */
.skeleton-loading {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.skeleton-task-item {
	display: flex;
	gap: 1rem;
	padding: 1rem;
	background: var(--color-background);
	border-radius: var(--border-radius-large);
}

.skeleton-task-status {
	width: 24px;
	height: 24px;
	border-radius: 50%;
	background: linear-gradient(90deg, var(--color-background-dark) 25%, var(--color-background) 50%, var(--color-background-dark) 75%);
	background-size: 200% 100%;
	animation: skeleton-loading 1.5s infinite;
}

.skeleton-task-content {
	flex: 1;
}

.skeleton-task-header {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-bottom: 0.5rem;
}

.skeleton-task-name {
	width: 150px;
	height: 16px;
	border-radius: var(--border-radius);
	background: linear-gradient(90deg, var(--color-background-dark) 25%, var(--color-background) 50%, var(--color-background-dark) 75%);
	background-size: 200% 100%;
	animation: skeleton-loading 1.5s infinite;
}

.skeleton-priority {
	width: 60px;
	height: 20px;
	border-radius: var(--border-radius-pill);
	background: linear-gradient(90deg, var(--color-background-dark) 25%, var(--color-background) 50%, var(--color-background-dark) 75%);
	background-size: 200% 100%;
	animation: skeleton-loading 1.5s infinite;
}

.skeleton-task-meta {
	display: flex;
	gap: 0.75rem;
}

.skeleton-meta-item {
	width: 50px;
	height: 12px;
	border-radius: var(--border-radius);
	background: linear-gradient(90deg, var(--color-background-dark) 25%, var(--color-background) 50%, var(--color-background-dark) 75%);
	background-size: 200% 100%;
	animation: skeleton-loading 1.5s infinite;
}

@keyframes skeleton-loading {
	0% {
		background-position: 200% 0;
	}
	100% {
		background-position: -200% 0;
	}
}
</style>
