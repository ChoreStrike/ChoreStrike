/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
import { showCompletionError } from '../utils/errorToast.js'
import { logger } from '../utils/logger.js'

/**
 * The completion every task surface shares (CS-PLAN-091 WP-B2, ADR-021
 * decisions 4 and 10). Holding the button completes everything at once:
 * the server credits the assignees in equal shares, or the recorder for
 * unassigned work, and marks every sub-task done. A short tap opens the
 * row's `CompletionPicker` instead, whose confirmed split arrives here.
 * Nobody has to take part to record work. A failure is a visible toast,
 * never a logger-only line (CS-PLAN-066 §3.5).
 *
 * @param {object} deps The view's collaborators.
 * @param {object} deps.roomTasksStore Room-task store (`completeRoomTask`).
 * @param {string} deps.view View name for the log context.
 * @param {(roomTaskId: number) => void} [deps.onCompleted] Runs after a committed completion.
 * @return {{requestCompletion: (roomTaskId: number, split?: ?object) => Promise<void>}}
 */
export function useTaskCompletion({ roomTasksStore, view, onCompleted }) {
	/**
	 * @param {number} roomTaskId The shown row.
	 * @param {?object} [split] The picker's reviewed split; absent, the server credits the default one.
	 */
	async function requestCompletion(roomTaskId, split = null) {
		try {
			if (split) {
				await roomTasksStore.completeRoomTask(roomTaskId, split)
			} else {
				await roomTasksStore.completeRoomTask(roomTaskId)
			}
			onCompleted?.(roomTaskId)
		} catch (error) {
			showCompletionError(error)
			logger.error('Failed to complete task', error, { view, action: 'completeTask', roomTaskId })
		}
	}

	return { requestCompletion }
}
