<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div
		v-if="entries.length"
		class="undo-queue"
		data-testid="undo-snackbar">
		<div
			v-for="entry in entries"
			:key="entry.key"
			class="undo-snackbar"
			:data-testid="`undo-snackbar-entry-${entry.roomTaskId}`"
			role="status"
			aria-live="polite">
			<span class="snackbar-message">{{ t('chorestrike', 'Task completed') }}</span>
			<button
				class="snackbar-undo"
				:data-testid="`undo-snackbar-undo-button-${entry.roomTaskId}`"
				@click="handleUndo(entry)">
				{{ t('chorestrike', 'Undo') }}
			</button>
			<button
				class="snackbar-dismiss"
				:data-testid="`undo-snackbar-dismiss-button-${entry.roomTaskId}`"
				:aria-label="t('chorestrike', 'Dismiss')"
				@click="dismiss(entry.key)">
				×
			</button>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { showError } from '@nextcloud/dialogs'
import { useRoomTasksStore } from '../../store/roomTasks.js'
import { logger } from '../../utils/logger.js'

const SNACKBAR_TIMEOUT_MS = 5000
const MAX_QUEUE_ENTRIES = 3

let entryCounter = 0

/**
 * Global "Task completed" snackbar queue with per-entry Undo actions
 * (CS-PLAN-041, UX-015 resolved by CS-PLAN-064 §3.5).
 *
 * Listens for successful completions on the event bus and offers a ~5 s
 * undo window per completion — every entry keeps its own countdown and is
 * individually undoable/dismissible, so a second completion no longer
 * destroys the first undo action. Undoing calls the one idempotent undo
 * aggregate (`POST /room-tasks/{id}/undo`) through the store; the entry
 * drops out immediately (perceived instant revert) and a failed undo
 * surfaces an error toast while the server truth (completed) stays. When
 * the queue exceeds MAX_QUEUE_ENTRIES the oldest entry is evicted. No
 * reward internals are shown — the copy stays "Task completed / Undo".
 */
export default {
	name: 'UndoSnackbar',

	data() {
		return {
			entries: [],
		}
	},

	mounted() {
		this.timers = new Map()
		this.handler = ({ roomTaskId }) => this.show(roomTaskId)
		subscribe('chorestrike:task-completed', this.handler)
	},

	unmounted() {
		unsubscribe('chorestrike:task-completed', this.handler)
		this.timers.forEach(timerId => clearTimeout(timerId))
		this.timers.clear()
	},

	methods: {
		t,

		show(roomTaskId) {
			const key = `undo-${++entryCounter}`
			this.entries.push({ key, roomTaskId })
			while (this.entries.length > MAX_QUEUE_ENTRIES) {
				this.dismiss(this.entries[0].key)
			}

			this.timers.set(
				key,
				setTimeout(() => this.dismiss(key), SNACKBAR_TIMEOUT_MS),
			)
		},

		dismiss(key) {
			this.entries = this.entries.filter(entry => entry.key !== key)
			if (this.timers.has(key)) {
				clearTimeout(this.timers.get(key))
				this.timers.delete(key)
			}
		},

		async handleUndo(entry) {
			const roomTasksStore = useRoomTasksStore()

			// Perceived instant revert: drop the entry before the call.
			this.dismiss(entry.key)

			try {
				await roomTasksStore.undoRoomTaskCompletion(entry.roomTaskId)
			} catch (error) {
				showError(t('chorestrike', 'Could not undo the completion'))
				logger.error('Failed to undo task completion', error, { component: 'UndoSnackbar', action: 'handleUndo', roomTaskId: entry.roomTaskId })
			}
		},
	},
}
</script>

<style scoped>
.undo-queue {
	position: fixed;
	bottom: 1.5rem;
	inset-inline-start: 50%;
	transform: translateX(-50%);
	z-index: 2000;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0.5rem;
	max-width: min(480px, calc(100vw - 2rem));
}

.undo-snackbar {
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
