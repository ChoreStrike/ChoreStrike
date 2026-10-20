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
			<Icon
				name="CheckCircle"
				:size="20"
				class="snackbar-check"
				aria-hidden="true"
				:data-testid="`undo-snackbar-check-${entry.roomTaskId}`" />
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
import Icon from './Icon.vue'
import { showErrorWithReference } from '../../utils/errorToast.js'

const SNACKBAR_TIMEOUT_MS = 5000
const MAX_QUEUE_ENTRIES = 3

let entryCounter = 0

/**
 * Global "Task completed" snackbar queue with per-entry Undo actions
 * (CS-PLAN-041, UX-015; WP3 completion-targeted undo).
 *
 * Listens for successful completions on the event bus and offers a ~5 s
 * undo window per completion — every entry keeps its own countdown and is
 * individually undoable/dismissible. Each entry carries the completion
 * identity (`completionId` + `expectedVersion`) the WP3 targeted
 * correction contract requires: undo runs
 * `POST /completions/{id}/undo` on the EXACT completion (an undo unit),
 * never a room-task-latest lookup. The entry drops out immediately
 * (perceived instant revert); a failed undo surfaces an error toast while
 * the server truth (completed) stays. When the queue exceeds
 * MAX_QUEUE_ENTRIES the oldest entry is evicted. No reward internals are
 * shown — the copy stays "Task completed / Undo".
 */
export default {
	name: 'UndoSnackbar',

	components: { Icon },

	data() {
		return {
			entries: [],
		}
	},

	mounted() {
		this.timers = new Map()
		this.handler = (payload) => this.show(payload)
		subscribe('chorestrike:task-completed', this.handler)
	},

	unmounted() {
		unsubscribe('chorestrike:task-completed', this.handler)
		this.timers.forEach((timerId) => clearTimeout(timerId))
		this.timers.clear()
	},

	methods: {
		t,

		show(payload) {
			const key = `undo-${++entryCounter}`
			this.entries.push({
				key,
				roomTaskId: payload.roomTaskId,
				completionId: payload.completionId ?? null,
				expectedVersion: payload.expectedVersion ?? 0,
			})
			while (this.entries.length > MAX_QUEUE_ENTRIES) {
				this.dismiss(this.entries[0].key)
			}

			this.timers.set(
				key,
				setTimeout(() => this.dismiss(key), SNACKBAR_TIMEOUT_MS),
			)
		},

		dismiss(key) {
			this.entries = this.entries.filter((entry) => entry.key !== key)
			if (this.timers.has(key)) {
				clearTimeout(this.timers.get(key))
				this.timers.delete(key)
			}
		},

		async handleUndo(entry) {
			const roomTasksStore = useRoomTasksStore()

			if (entry.completionId == null) {
				// Without the completion identity no targeted undo can run —
				// surface the failure instead of undoing a guessed target.
				showError(t('chorestrike', 'Could not undo the completion'))
				logger.error('Undo blocked: completion identity missing', undefined, { component: 'UndoSnackbar', action: 'handleUndo', roomTaskId: entry.roomTaskId })
				this.dismiss(entry.key)
				return
			}

			// Perceived instant revert: drop the entry before the call.
			this.dismiss(entry.key)

			try {
				await roomTasksStore.undoRoomTaskCompletion(entry.completionId, entry.expectedVersion)
			} catch (error) {
				showErrorWithReference(t('chorestrike', 'Could not undo the completion'), error)
				logger.error('Failed to undo task completion', error, { component: 'UndoSnackbar', action: 'handleUndo', completionId: entry.completionId })
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

/* Always-on completion acknowledgement (CS-PLAN-084 D-084-03): a short
 * check-mark pop; reduced motion keeps the static mark. */
.snackbar-check {
	flex-shrink: 0;
	animation: snackbar-check-pop 320ms ease-out;
}

@keyframes snackbar-check-pop {
	0% { transform: scale(0.4); opacity: 0; }
	60% { transform: scale(1.15); opacity: 1; }
	100% { transform: scale(1); }
}

@media (prefers-reduced-motion: reduce) {
	.snackbar-check {
		animation: none;
	}
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
