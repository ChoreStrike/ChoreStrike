/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
import { computed, ref } from 'vue'
import { showCompletionError } from '../utils/errorToast.js'
import { logger } from '../utils/logger.js'

/**
 * The completion flow every task surface shares (WP3 §3.6, Q-074-02): a
 * task that is the actor's alone, or nobody's, completes directly; a task
 * of several people, or of someone else, first opens the participation
 * dialog with the split the server would credit by default — equal shares
 * of the assignees. A failure is a visible toast, never a logger-only line
 * (CS-PLAN-066 §3.5).
 *
 * The calling view renders `ParticipationDialog` while `participation` is
 * set and passes the stores it already holds.
 *
 * @param {object} deps The view's collaborators.
 * @param {object} deps.roomTasksStore Room-task store (`completeRoomTask`).
 * @param {object} deps.userStore Household user store (identity, members).
 * @param {string} deps.view View name for the log context.
 * @param {(roomTaskId: number) => void} [deps.onCompleted] Runs after a committed completion.
 * @return {object} The dialog state and the flow's entry points.
 */
export function useTaskCompletion({ roomTasksStore, userStore, view, onCompleted }) {
	// The dialog target while open: `{ roomTaskId, assignees }`.
	const participation = ref(null)

	const actorUserId = computed(() => userStore.currentUser?.nextcloudUserId ?? null)

	/**
	 * Candidates of the open dialog: the task's assignees (the dialog never
	 * opens for unassigned work). Names fall back to user ids.
	 */
	const participationMembers = computed(() => {
		if (!participation.value) {
			return []
		}
		const namesByUser = {}
		;(userStore.houseUsers ?? []).forEach((member) => {
			namesByUser[member.nextcloudUserId] = member.displayName
		})
		return (participation.value.assignees ?? []).map((userId) => ({ userId, displayName: namesByUser[userId] ?? userId }))
	})

	/**
	 * More than one assignee, or a sole one who is not the acting user. An
	 * unresolved identity opens the dialog too: the safe default never
	 * fires a participant-less completion into someone else's work.
	 * Unassigned work is the actor's.
	 * @param {string[]} assignees The task's assignees.
	 * @return {boolean}
	 */
	function needsParticipationDialog(assignees) {
		if (assignees.length === 1) {
			return actorUserId.value === null || assignees[0] !== actorUserId.value
		}
		return assignees.length > 1
	}

	/**
	 * The dialog needs the actor's id when it is created (the actor joins
	 * a foreign split); member names load alongside and fall back to ids.
	 * @param {object} target The dialog target.
	 */
	async function openParticipation(target) {
		if (!userStore.currentUser) {
			await userStore.initializeCurrentUser().catch(() => {})
		}
		if ((userStore.houseUsers ?? []).length === 0) {
			userStore.fetchHouseUsers().catch(() => {})
		}
		participation.value = target
	}

	async function performComplete(roomTaskId, options = {}) {
		try {
			// A participant-less completion omits the options: the server
			// credits the default split.
			if (Object.keys(options).length > 0) {
				await roomTasksStore.completeRoomTask(roomTaskId, options)
			} else {
				await roomTasksStore.completeRoomTask(roomTaskId)
			}
			onCompleted?.(roomTaskId)
		} catch (error) {
			showCompletionError(error)
			logger.error('Failed to complete task', error, { view, action: 'completeTask', roomTaskId })
		}
	}

	/**
	 * Complete a task, through the dialog where the work needs a review.
	 * @param {{roomTaskId: number, assignees: string[]}} target The shown row.
	 * @param {?object} [claimAllocation] A confirmed overdue claim: its reviewed sole-participant split completes directly.
	 */
	async function requestCompletion(target, claimAllocation = null) {
		if (claimAllocation) {
			await performComplete(target.roomTaskId, claimAllocation)
			return
		}
		if (needsParticipationDialog(target.assignees ?? [])) {
			await openParticipation(target)
			return
		}
		await performComplete(target.roomTaskId)
	}

	async function confirmParticipation(payload) {
		const target = participation.value
		participation.value = null
		if (target) {
			await performComplete(target.roomTaskId, payload)
		}
	}

	function closeParticipation() {
		participation.value = null
	}

	return { participation, participationMembers, actorUserId, requestCompletion, confirmParticipation, closeParticipation }
}
