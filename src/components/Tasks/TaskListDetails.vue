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
				:modelValue="assigneeDraft"
				@update:modelValue="onAssigneeDraftChange" />
		</div>

		<!-- Essential onboarding slice (WP8 §3.6): how actual work
		     shares split the total reward. -->
		<HelpEntry variant="context" topic="agreements" />

		<TaskChecklist
			:items="checklistStore.getTaskItems(item.task.id)"
			:progress="checklistStore.getRoomTaskProgress(item.roomTask.id)"
			:canEdit="isHouseOwner"
			:readonly="!item.roomTask.isActive"
			:roomTaskId="item.roomTask.id"
			:taskId="item.task.id"
			@toggle="handleToggleChecklistItem"
			@add="handleAddChecklistItem"
			@update="handleUpdateChecklistItem"
			@delete="handleDeleteChecklistItem"
			@reorder="handleReorderChecklistItems" />

		<!-- Occurrence agreements (CS-PLAN-070 WP6 §3.5 on the CS-PLAN-073
		     WP2 commands): minimal OWNER entry points. The identity
		     (occurrence key, shown version, selection token) comes straight
		     from the row's occurrence block; a 409 keeps the drafts. -->
		<div
			v-if="isHouseOwner && occurrence && occurrence.fulfilled !== true"
			class="agreements"
			data-testid="occurrence-agreement">
			<h4 class="agreements-title">
				{{ t('chorestrike', 'Adjust occurrence') }}
			</h4>
			<p data-testid="agreement-target-date">
				{{ t('chorestrike', 'planned {date}', { date: occurrence.workDate || occurrence.plannedDate }) }}
			</p>

			<div v-if="amendAvailable" class="agreement-form" data-testid="agreement-amend">
				<label :for="`amend-date-${item.roomTask.id}`" class="agreement-label">
					{{ t('chorestrike', 'Move this occurrence to') }}
				</label>
				<input
					:id="`amend-date-${item.roomTask.id}`"
					v-model="amendDraft.date"
					type="date"
					class="agreement-input"
					data-testid="agreement-amend-date">
				<button
					type="button"
					class="agreement-button"
					data-testid="agreement-amend-submit"
					:disabled="!amendDraft.date || !consent || submitting !== null"
					@click="submitAmend">
					{{ t('chorestrike', 'Record amendment') }}
				</button>
			</div>

			<div v-if="catchUpAvailable" class="agreement-form" data-testid="agreement-catchup">
				<label :for="`catchup-date-${item.roomTask.id}`" class="agreement-label">
					{{ t('chorestrike', 'Agree a catch-up appointment on') }}
				</label>
				<input
					:id="`catchup-date-${item.roomTask.id}`"
					v-model="catchUpDraft.workDate"
					type="date"
					class="agreement-input"
					data-testid="agreement-catchup-date">
				<button
					type="button"
					class="agreement-button"
					data-testid="agreement-catchup-submit"
					:disabled="!catchUpDraft.workDate || !consent || submitting !== null"
					@click="submitCatchUp">
					{{ t('chorestrike', 'Record catch-up') }}
				</button>
			</div>

			<div v-if="amendAvailable" class="agreement-form" data-testid="agreement-handover">
				<label :for="`handover-member-${item.roomTask.id}`" class="agreement-label">
					{{ t('chorestrike', 'Hand this occurrence to') }}
				</label>
				<select
					:id="`handover-member-${item.roomTask.id}`"
					v-model="handoverDraft"
					class="agreement-input"
					data-testid="agreement-handover-member">
					<option value="" disabled>
						{{ t('chorestrike', 'Choose a member') }}
					</option>
					<option v-for="member in members" :key="member.nextcloudUserId" :value="member.nextcloudUserId">
						{{ member.displayName }}
					</option>
				</select>
				<p>{{ t('chorestrike', 'This person takes full responsibility for this occurrence only. Future assignments stay unchanged.') }}</p>
				<button
					type="button"
					class="agreement-button"
					data-testid="agreement-handover-submit"
					:disabled="!handoverDraft || !consent || submitting !== null"
					@click="submitHandover">
					{{ t('chorestrike', 'Record agreed handover') }}
				</button>
			</div>

			<div v-if="restartAvailable" class="agreement-form" data-testid="agreement-restart">
				<label :for="`restart-date-${item.roomTask.id}`" class="agreement-label">
					{{ t('chorestrike', 'Restart the linked interval work on') }}
				</label>
				<input
					:id="`restart-date-${item.roomTask.id}`"
					v-model="restartDraft.workDate"
					type="date"
					class="agreement-input"
					data-testid="agreement-restart-date">
				<button
					type="button"
					class="agreement-button"
					data-testid="agreement-restart-submit"
					:disabled="!restartDraft.workDate || !consent || submitting !== null"
					@click="submitRestart">
					{{ t('chorestrike', 'Record restart') }}
				</button>
			</div>

			<label class="agreement-consent">
				<input
					v-model="consent"
					type="checkbox"
					data-testid="agreement-consent">
				<span>{{ t('chorestrike', 'The affected people have agreed to this change.') }}</span>
			</label>

			<p
				v-if="conflict && !occurrenceReplaced"
				class="agreement-note"
				data-testid="agreement-conflict"
				role="alert">
				{{ t('chorestrike', 'The occurrence changed in the meantime. Your entries were kept — review and send again.') }}
			</p>
			<p
				v-if="occurrenceReplaced"
				class="agreement-note"
				data-testid="agreement-replaced"
				role="alert">
				{{ t('chorestrike', 'A different occurrence is now displayed. Choose a new agreement and confirm consent again.') }}
			</p>
		</div>
	</div>
</template>

<script>
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import HelpEntry from '../Help/HelpEntry.vue'
import AssigneeChips from './AssigneeChips.vue'
import TaskChecklist from './TaskChecklist.vue'
import { useChecklistStore } from '../../store/checklist.js'
import { useSchedulingStore } from '../../store/scheduling.js'
import { logger } from '../../utils/logger.js'

/**
 * The expanded details surface of a TaskList row (CS-PLAN-067 §3.4): the
 * assignee multi-select (reuse of AssigneeChips, no per-row member fetch —
 * members arrive via the preloaded list) and the TaskChecklist wiring.
 *
 * CS-PLAN-070 WP6 §3.5 adds the OWNER agreement entry points: amend
 * (single-occurrence date move), catch-up (agreed work date on the
 * current miss) and restart (linked interval restart after a neutral
 * suspension). Every command sends a fresh command UUID, the SHOWN
 * occurrence identity and version, and the explicit consent attestation;
 * a 409 PRESERVES the drafts.
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
		HelpEntry,
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

	emits: ['toggleAssignee'],

	setup(props, { emit }) {
		const checklistStore = useChecklistStore()
		const schedulingStore = useSchedulingStore()

		/**
		 * Diff the chips value to the single toggled member and report it —
		 * the draft update + optimistic persist lives in TaskList.
		 *
		 * @param {string[]} next The chips' full next user-ID array.
		 */
		const onAssigneeDraftChange = (next) => {
			const previous = props.assigneeDraft
			const added = next.find((id) => !previous.includes(id))
			const removed = previous.find((id) => !next.includes(id))
			const toggled = added ?? removed
			if (toggled !== undefined) {
				emit('toggleAssignee', props.item.roomTask.id, toggled)
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
			schedulingStore,
			onAssigneeDraftChange,
			handleToggleChecklistItem,
			handleAddChecklistItem,
			handleUpdateChecklistItem,
			handleDeleteChecklistItem,
			handleReorderChecklistItems,
			t,
		}
	},

	data() {
		return {
			consent: false,
			submitting: null,
			conflict: false,
			occurrenceReplaced: false,
			amendDraft: { date: '' },
			handoverDraft: '',
			catchUpDraft: { workDate: '' },
			restartDraft: { workDate: '' },
		}
	},

	computed: {
		/**
		 * The occurrence block of the delivered read (daily/overdue rows
		 * carry it; definition-only rows do not).
		 * @return {?object}
		 */
		occurrence() {
			return this.item.occurrence ?? this.item.roomTask?.occurrence ?? null
		},

		amendAvailable() {
			return this.occurrence !== null && this.occurrence.suspended !== true
		},

		/**
		 * Catch-up applies to the CURRENT unfulfilled miss only (C5d) —
		 * the delivered overdue selection (classification 'late').
		 * @return {boolean}
		 */
		catchUpAvailable() {
			return this.occurrence?.classification === 'late'
		},

		/**
		 * Restart applies only to neutrally suspended interval-cadence
		 * work; the task's schedulingMode carries the cadence fact.
		 * @return {boolean}
		 */
		restartAvailable() {
			return this.occurrence?.suspended === true
				&& this.item.task?.schedulingMode === 'dynamic'
		},
	},

	watch: {
		'occurrence.occurrenceId': function(next, previous) {
			if (next === previous) {
				return
			}
			for (const mode of ['amend', 'handover', 'catchup', 'restart']) {
				this.resetDraft(mode)
			}
			this.occurrenceReplaced = true
		},
	},

	methods: {
		/**
		 * Run one agreement command: 409 keeps the draft and surfaces the
		 * conflict note; any other failure toasts; success refreshes the
		 * projections (the store already re-read the readers) and clears
		 * the used draft.
		 * @param {string} mode 'amend' | 'handover' | 'catchup' | 'restart'.
		 * @param {function(): Promise<object>} command The store call.
		 */
		async runAgreement(mode, command) {
			this.submitting = mode
			this.conflict = false
			try {
				const result = await command()
				if (result?.refreshFailed) {
					showWarning(t('chorestrike', 'Some views could not be refreshed'))
				} else {
					showSuccess(t('chorestrike', 'The agreement was recorded.'))
				}
				this.resetDraft(mode)
				this.occurrenceReplaced = false
			} catch (error) {
				if (error?.status === 409 || error?.response?.status === 409) {
					this.conflict = true
				} else {
					showError(t('chorestrike', 'The agreement could not be recorded.'))
					logger.error('Failed to record the occurrence agreement', error, { component: 'TaskListDetails', action: 'runAgreement', mode })
				}
			} finally {
				this.submitting = null
			}
		},

		resetDraft(mode) {
			if (mode === 'amend') {
				this.amendDraft = { date: '' }
			} else if (mode === 'handover') {
				this.handoverDraft = ''
			} else if (mode === 'catchup') {
				this.catchUpDraft = { workDate: '' }
			} else {
				this.restartDraft = { workDate: '' }
			}
			this.consent = false
		},

		submitAmend() {
			if (!this.amendDraft.date || !this.consent) {
				return
			}
			return this.runAgreement('amend', () => this.schedulingStore.amendOccurrence({
				roomTaskId: this.item.roomTask.id,
				occurrence: this.occurrence,
				date: this.amendDraft.date,
				consent: this.consent,
			}))
		},

		submitHandover() {
			if (!this.handoverDraft || !this.consent || this.submitting !== null) {
				return
			}
			return this.runAgreement('handover', () => this.schedulingStore.amendOccurrence({
				roomTaskId: this.item.roomTask.id,
				occurrence: this.occurrence,
				participants: [{ userId: this.handoverDraft, role: 'responsible', sharePct: 100 }],
				consent: this.consent,
			}))
		},

		submitCatchUp() {
			if (!this.catchUpDraft.workDate || !this.consent) {
				return
			}
			return this.runAgreement('catchup', () => this.schedulingStore.catchUpOccurrence({
				roomTaskId: this.item.roomTask.id,
				occurrence: this.occurrence,
				workDate: this.catchUpDraft.workDate,
				consent: this.consent,
			}))
		},

		submitRestart() {
			if (!this.restartDraft.workDate || !this.consent) {
				return
			}
			return this.runAgreement('restart', () => this.schedulingStore.restartOccurrence({
				roomTaskId: this.item.roomTask.id,
				occurrence: this.occurrence,
				workDate: this.restartDraft.workDate,
				consent: this.consent,
			}))
		},
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

.agreements {
	margin-top: 1rem;
	padding: 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.agreements-title {
	margin: 0;
	font-size: 0.95rem;
}

.agreement-form {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	flex-wrap: wrap;
}

.agreement-label {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.agreement-input {
	padding: 0.3rem 0.5rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	min-height: 44px;
}

.agreement-button {
	min-height: 44px;
	padding: 0.35rem 0.9rem;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 0.85rem;
	font-weight: 600;
}

.agreement-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.agreement-consent {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	font-size: 0.85rem;
	color: var(--color-main-text);
	cursor: pointer;
}

.agreement-consent input {
	min-width: 44px;
	min-height: 44px;
}

.agreement-note {
	margin: 0;
	font-size: 0.85rem;
	padding: 0.4rem 0.6rem;
	border-inline-start: 3px solid var(--color-element-warning);
	color: var(--color-main-text);
}
</style>
