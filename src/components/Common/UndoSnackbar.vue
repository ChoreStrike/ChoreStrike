<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div
		v-if="visible"
		class="undo-snackbar"
		data-testid="undo-snackbar"
		role="status">
		<span class="snackbar-message">{{ t('chorestrike', 'Task completed') }}</span>
		<button
			class="snackbar-undo"
			data-testid="undo-snackbar-undo-button"
			:disabled="undoing"
			@click="handleUndo">
			{{ t('chorestrike', 'Undo') }}
		</button>
		<button
			class="snackbar-dismiss"
			data-testid="undo-snackbar-dismiss-button"
			:aria-label="t('chorestrike', 'Dismiss')"
			@click="hide">
			×
		</button>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { showError } from '@nextcloud/dialogs'
import { useRoomTasksStore } from '../../store/roomTasks.js'
import { logger } from '../../utils/logger.js'

const SNACKBAR_TIMEOUT_MS = 5000

/**
 * Global "Task completed" snackbar with an Undo action (CS-PLAN-041).
 *
 * Listens for successful completions on the event bus and offers a ~5 s
 * undo window backed by the uncomplete/reactivate endpoints. Undo hides the
 * bar immediately (perceived instant revert) and restores the task via the
 * roomTasks store; a failed undo surfaces an error toast and leaves the
 * server-truth (completed) state in place. Dismissal or timeout hides the
 * bar without side effects.
 */
export default {
	name: 'UndoSnackbar',

	data() {
		return {
			roomTaskId: null,
			visible: false,
			undoing: false,
			timerId: null,
		}
	},

	mounted() {
		this.handler = ({ roomTaskId }) => this.show(roomTaskId)
		subscribe('chorestrike:task-completed', this.handler)
	},

	unmounted() {
		unsubscribe('chorestrike:task-completed', this.handler)
		this.clearTimer()
	},

	methods: {
		t,

		show(roomTaskId) {
			this.roomTaskId = roomTaskId
			this.visible = true
			this.restartTimer()
		},

		hide() {
			this.visible = false
			this.roomTaskId = null
			this.clearTimer()
		},

		restartTimer() {
			this.clearTimer()
			this.timerId = setTimeout(this.hide, SNACKBAR_TIMEOUT_MS)
		},

		clearTimer() {
			if (this.timerId !== null) {
				clearTimeout(this.timerId)
				this.timerId = null
			}
		},

		async handleUndo() {
			const roomTasksStore = useRoomTasksStore()
			const roomTaskId = this.roomTaskId

			// Perceived instant revert: drop the bar before the call.
			this.hide()
			if (roomTaskId === null) return

			this.undoing = true
			try {
				await roomTasksStore.undoRoomTaskCompletion(roomTaskId)
			} catch (error) {
				showError(t('chorestrike', 'Could not undo the completion'))
				logger.error('Failed to undo task completion', error, { component: 'UndoSnackbar', action: 'handleUndo', roomTaskId })
			} finally {
				this.undoing = false
			}
		},
	},
}
</script>

<style scoped>
.undo-snackbar {
	position: fixed;
	bottom: 1.5rem;
	inset-inline-start: 50%;
	transform: translateX(-50%);
	z-index: 2000;
	display: flex;
	align-items: center;
	gap: 0.75rem;
	padding: 0.75rem 1rem;
	background: var(--color-main-text);
	color: var(--color-main-background);
	border-radius: 8px;
	box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
	min-width: 260px;
	max-width: min(480px, calc(100vw - 2rem));
}

.snackbar-message {
	flex: 1;
	font-size: 0.95rem;
}

.snackbar-undo {
	min-height: 44px;
	padding: 0.4rem 1rem;
	background: transparent;
	color: inherit;
	border: 1px solid currentcolor;
	border-radius: 6px;
	cursor: pointer;
	font-weight: 600;
	font-size: 0.9rem;
}

.snackbar-undo:hover:not(:disabled) {
	background: rgba(255, 255, 255, 0.15);
}

.snackbar-undo:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.snackbar-dismiss {
	width: 44px;
	height: 44px;
	background: transparent;
	color: inherit;
	border: none;
	font-size: 1.4rem;
	line-height: 1;
	cursor: pointer;
}

.snackbar-dismiss:hover {
	opacity: 0.8;
}
</style>
