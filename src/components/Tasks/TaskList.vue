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

		<EmptyState
			v-else-if="!roomTasks || roomTasks.length === 0"
			icon="List"
			:message="t('chorestrike', 'No tasks yet')"
			:hint="t('chorestrike', 'Add your first task to get started')" />

		<div v-else class="task-list-body">
			<TaskListBulkBar
				v-if="userStore.canManageTasks && selectedRoomTaskIds.length > 0"
				:selectedCount="selectedRoomTaskIds.length"
				:members="houseMembers"
				:assigning="bulkAssigning"
				:clearSignal="bulkClearSignal"
				@assign="handleBulkAssign"
				@clear="clearSelection" />

			<div class="task-items">
				<TaskListItem
					v-for="item in sortedTasks"
					:key="item.roomTask.id"
					:item="item"
					:selected="isSelected(item.roomTask.id)"
					:expanded="isExpanded(item.roomTask.id)"
					:assigneeDraft="assigneeDraft[item.roomTask.id] || []"
					:compact="compact"
					@complete="$emit('complete', $event)"
					@edit="$emit('edit', $event)"
					@delete="$emit('delete', $event)"
					@toggleExpand="toggleExpand"
					@toggleSelect="toggleSelected"
					@claim="openClaimDialog"
					@toggleAssignee="toggleAssignee" />
			</div>

			<OverdueClaimDialog
				:visible="claimDialogVisible"
				:assigneeName="claimAssigneeName"
				@confirm="confirmClaim"
				@cancel="claimDialogVisible = false" />
		</div>
	</div>
</template>

<script>
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import EmptyState from '../Common/EmptyState.vue'
import OverdueClaimDialog from './OverdueClaimDialog.vue'
import TaskListBulkBar from './TaskListBulkBar.vue'
import TaskListItem from './TaskListItem.vue'
import { useChecklistStore } from '../../store/checklist.js'
import { useRoomTasksStore } from '../../store/roomTasks.js'
import { useUserStore } from '../../store/user.js'
import { logger } from '../../utils/logger.js'
import { compareTaskItems, isOverdue } from '../../utils/taskDisplay.js'

/**
 * TaskList orchestrator (CS-PLAN-067 §3.4): sorting, the bulk-assign state
 * machine, the optimistic assignee drafts (CS-PLAN-065 §3.5), the claim
 * dialog and the lazy expansion (assignee draft seeding, member pre-fetch
 * and checklist loading). Row presentation lives in TaskListItem, the
 * bulk bar in TaskListBulkBar, expanded details in TaskListDetails, and
 * the shared display helpers in utils/taskDisplay.js.
 */
export default {
	name: 'TaskList',

	components: {
		EmptyState,
		TaskListItem,
		TaskListBulkBar,
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

		/** Dense rows for long lists (CS-PLAN-084 WP-D). */
		compact: {
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
			return [...tasks].sort(compareTaskItems)
		})

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
					} catch {
						// non-fatal: picker will show an empty hint
					}
				}
				// Load checklist data
				await checklistStore.fetchTaskItems(item.task.id)
				await checklistStore.fetchRoomTaskProgress(item.roomTask.id)
			}
		}

		const isExpanded = (roomTaskId) => {
			return expandedTaskId.value === roomTaskId
		}

		const houseMembers = computed(() => userStore.houseUsers || [])

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
			} catch {
				// Revert the draft on failure so the UI matches the server state
				const previous = next.includes(userId)
					? next.filter((id) => id !== userId)
					: [...next, userId]
				assigneeDraft.value = { ...assigneeDraft.value, [roomTaskId]: previous }
			}
		}

		// --- Overdue claim dialog (CS-PLAN-060 §3.4) ---

		const claimDialogVisible = ref(false)
		const claimAssigneeId = ref('')
		const claimRoomTaskId = ref(null)

		// Resolved reactively so a dialog opened before the member list
		// loaded picks up the display name once the read lands.
		const claimAssigneeName = computed(() => {
			const member = (userStore.houseUsers || []).find((u) => u.nextcloudUserId === claimAssigneeId.value)
			return member?.displayName || claimAssigneeId.value || t('chorestrike', 'another member')
		})

		const currentUserId = computed(() => userStore.currentUser?.nextcloudUserId || userStore.userId)

		const canClaimFromItem = (item) => {
			if (!item.roomTask.isActive) {
				return false
			}
			if (!isOverdue(item.roomTask)) {
				return false
			}
			if ((item.task.visibilityType || 'public') !== 'public') {
				return false
			}
			const assignees = item.roomTask.assignees || []
			if (assignees.length === 0) {
				return false
			}
			return !assignees.includes(currentUserId.value)
		}

		const openClaimDialog = (item) => {
			if (!canClaimFromItem(item)) {
				return
			}
			claimRoomTaskId.value = item.roomTask.id
			claimAssigneeId.value = (item.roomTask.assignees || [])[0] || ''
			claimDialogVisible.value = true
		}

		// The confirmed claim IS the reviewed allocation: the claimer did the
		// whole overdue work, so it completes as sole participant without a
		// second participation dialog (CS-PLAN-084 WP-G, RF-080-14 path).
		const confirmClaim = async () => {
			claimDialogVisible.value = false
			if (!claimRoomTaskId.value) {
				return
			}
			if (!currentUserId.value) {
				// Unresolved identity: the participation dialog's safe path decides.
				emit('complete', claimRoomTaskId.value)
				return
			}
			emit('complete', claimRoomTaskId.value, {
				participants: [{ userId: currentUserId.value, sharePct: 100 }],
				allocationReviewed: true,
			})
		}

		// --- Bulk assignment selection (CS-PLAN-042, UX-008) ---

		const selectedRoomTaskIds = ref([])
		const bulkAssigning = ref(false)
		// Bump signal: resets the bulk bar's local target draft when the
		// selection clears (the bar owns only that input's state).
		const bulkClearSignal = ref(0)

		const isSelected = (roomTaskId) => selectedRoomTaskIds.value.includes(roomTaskId)

		const toggleSelected = async (roomTaskId) => {
			if (isSelected(roomTaskId)) {
				selectedRoomTaskIds.value = selectedRoomTaskIds.value.filter((id) => id !== roomTaskId)
			} else {
				selectedRoomTaskIds.value = [...selectedRoomTaskIds.value, roomTaskId]
				// Lazy-load house members for the target dropdown
				if ((userStore.houseUsers || []).length === 0) {
					try {
						await userStore.fetchHouseUsers()
					} catch {
						// non-fatal: dropdown will show an empty list
					}
				}
			}
		}

		const clearSelection = () => {
			selectedRoomTaskIds.value = []
			bulkClearSignal.value++
		}

		/**
		 * One bulk call for the whole selection (CR-SVC-61). The server skips
		 * foreign/missing ids per item; the diff between requested and
		 * returned ids drives the partial-success toast.
		 *
		 * @param {string} targetUserId The picked target member's user ID.
		 */
		const handleBulkAssign = async (targetUserId) => {
			const requested = [...selectedRoomTaskIds.value]
			if (!targetUserId || requested.length === 0) {
				return
			}

			bulkAssigning.value = true
			try {
				const assigned = await roomTasksStore.bulkAssignTasks(requested, targetUserId)
				const assignedIds = new Set((assigned || []).map((rt) => rt.id))
				const skippedCount = requested.length - assignedIds.size

				if (skippedCount === 0) {
					showSuccess(t('chorestrike', '{count} task(s) assigned', { count: requested.length }))
				} else if (assignedIds.size > 0) {
					showWarning(t('chorestrike', '{assigned} task(s) assigned, {skipped} skipped', {
						assigned: assignedIds.size,
						skipped: skippedCount,
					}))
				} else {
					showError(t('chorestrike', 'No tasks were assigned'))
				}
				clearSelection()
			} catch (error) {
				showError(t('chorestrike', 'Failed to assign tasks'))
				logger.error('Bulk assign failed', error, { component: 'TaskList', action: 'handleBulkAssign' })
			} finally {
				bulkAssigning.value = false
			}
		}

		return {
			sortedTasks,
			expandedTaskId,
			toggleExpand,
			isExpanded,
			houseMembers,
			assigneeDraft,
			toggleAssignee,
			selectedRoomTaskIds,
			bulkAssigning,
			bulkClearSignal,
			isSelected,
			toggleSelected,
			clearSelection,
			handleBulkAssign,
			openClaimDialog,
			confirmClaim,
			claimDialogVisible,
			claimAssigneeName,
			userStore,
			t,
		}
	},
}
</script>

<style scoped>
.task-list {
	width: 100%;
}

.task-list-body {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.task-items {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
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
