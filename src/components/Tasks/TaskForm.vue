<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<Modal persistent :aria-label="title" @close="$emit('close')">
		<form class="task-form" data-testid="task-form" @submit.prevent="save()">
			<h2 class="form-title">
				{{ title }}
			</h2>
			<TaskTemplateFill v-if="!state.task" :preferredRoomType="roomType" @fill="state.fill" />

			<label class="field">
				<span>{{ t('chorestrike', 'Name') }} *</span>
				<input v-model="state.details.name"
					type="text"
					required
					data-testid="task-name-input"
					:placeholder="t('chorestrike', 'e.g., Vacuum living room')">
			</label>
			<div class="field" role="group" aria-labelledby="task-form-room">
				<span id="task-form-room">{{ t('chorestrike', 'Floor and room') }} *</span>
				<RoomPicker v-model="state.roomId.value" :initialFloorId="prefill.floorId" />
			</div>
			<div class="field">
				<label for="energyCost">{{ t('chorestrike', 'Energy · 1 energy is about 10 minutes') }}</label>
				<TaskEnergyField v-model="state.details.energyCost" />
			</div>
			<div class="field" role="group" aria-labelledby="task-form-assignees">
				<span id="task-form-assignees">{{ t('chorestrike', 'Assigned members') }}</span>
				<ErrorState v-if="membersError" :message="t('chorestrike', 'Failed to load members')" @retry="loadMembers" />
				<AssigneeChips v-else v-model="state.assigneeUserIds.value" :members="userStore.houseUsers" />
			</div>

			<TaskRhythmField v-if="!state.details.isVoluntary" v-model="state.rhythm.value" :stored="state.storedRhythm" />
			<TaskWindowNote v-if="!state.details.isVoluntary"
				v-model:firstDate="state.firstDate.value"
				:locked="!state.firstDateEditable"
				:roomTask="state.roomTask"
				:rhythm="state.rhythm.value"
				:storedRhythm="state.storedRhythm" />

			<ErrorState v-if="state.subTasksFailed.value" :message="t('chorestrike', 'Could not load the sub-tasks')" @retry="state.loadSubTasks" />
			<SubTaskEditor v-else v-model="state.subTasks.value" />

			<label class="switch">
				<input v-model="state.details.isVoluntary" type="checkbox" data-testid="task-bonus-switch">
				<span>
					<span class="switch-label">{{ t('chorestrike', 'Bonus task') }}</span>
					<span class="hint">{{ t('chorestrike', 'Voluntary and always available, never overdue') }}</span>
				</span>
			</label>
			<label class="switch">
				<input v-model="state.sleeping.value" type="checkbox" data-testid="task-sleeping-switch">
				<span>
					<span class="switch-label">{{ t('chorestrike', 'Sleeping') }}</span>
					<span class="hint">{{ t('chorestrike', 'Rests until it is woken, for example in winter') }}</span>
				</span>
			</label>
			<label v-if="state.sleeping.value" class="field">
				<span>{{ t('chorestrike', 'Wake up on (optional)') }}</span>
				<input v-model="state.wakeOn.value"
					type="date"
					:min="tomorrow"
					data-testid="task-wake-on">
			</label>

			<TaskFormMore v-model="detailsModel" :categories="state.categories.value" :isOwner="userStore.isHouseOwner" />
			<p v-if="state.sharedRooms.value > 1" class="hint" data-testid="task-shared-hint">
				{{ t('chorestrike', 'This task is used in {count} rooms.', { count: state.sharedRooms.value }) }}
			</p>

			<div class="form-actions">
				<button v-if="state.task"
					type="button"
					class="danger"
					data-testid="task-delete-button"
					@click="confirmingDelete = true">
					{{ t('chorestrike', 'Delete task') }}
				</button>
				<button type="button" data-testid="task-cancel-button" @click="$emit('close')">
					{{ t('chorestrike', 'Cancel') }}
				</button>
				<button type="submit"
					class="primary"
					data-testid="save-task-button"
					:disabled="!state.valid.value"
					:aria-disabled="saving ? 'true' : undefined">
					{{ t('chorestrike', 'Save') }}
				</button>
			</div>
		</form>
		<TaskScopeDialog v-if="askingScope" @choose="save" @cancel="askingScope = false" />
		<ConfirmDialog v-if="confirmingDelete"
			:title="t('chorestrike', 'Delete this task?')"
			:description="t('chorestrike', 'What was done stays in the history.')"
			:loading="saving"
			@confirm="remove"
			@cancel="confirmingDelete = false" />
	</Modal>
</template>

<script>
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import ConfirmDialog from '../Common/ConfirmDialog.vue'
import ErrorState from '../Common/ErrorState.vue'
import Modal from '../Common/Modal.vue'
import AssigneeChips from './AssigneeChips.vue'
import RoomPicker from './RoomPicker.vue'
import SubTaskEditor from './SubTaskEditor.vue'
import TaskEnergyField from './TaskEnergyField.vue'
import TaskFormMore from './TaskFormMore.vue'
import TaskRhythmField from './TaskRhythmField.vue'
import TaskScopeDialog from './TaskScopeDialog.vue'
import TaskTemplateFill from './TaskTemplateFill.vue'
import TaskWindowNote from './TaskWindowNote.vue'
import { useTaskFormState } from '../../composables/useTaskFormState.js'
import { useHouseStore } from '../../store/house.js'
import { useRoomsStore } from '../../store/rooms.js'
import { useRoomTasksStore } from '../../store/roomTasks.js'
import { useTaskFormStore } from '../../store/taskForm.js'
import { useUserStore } from '../../store/user.js'
import { addDaysToKey, todayKeyInZone } from '../../utils/dateKeys.js'
import { showRefusal } from '../../utils/errorToast.js'

/**
 * The one form for a new and an existing task (CS-PLAN-091 WP-B6,
 * FEAT-UX-040), as a dialog of the task list and of every task row. One
 * save is one atomic write; a definition several rooms share asks *this
 * room* or *all rooms* first. Adults and the owner open it, children
 * never do; the server decides either way.
 */
export default {
	name: 'TaskForm',

	components: { AssigneeChips, ConfirmDialog, ErrorState, Modal, RoomPicker, SubTaskEditor, TaskEnergyField, TaskFormMore, TaskRhythmField, TaskScopeDialog, TaskTemplateFill, TaskWindowNote },

	props: {
		/** `{ roomTask, task }` of the task to edit; null opens a new one. */
		item: { type: Object, default: null },
		/** Defaults of a new task: `{ roomId, floorId, assigneeIds }`. */
		prefill: { type: Object, default: () => ({ roomId: null, floorId: null, assigneeIds: [] }) },
	},

	emits: ['close'],

	setup(props) {
		return {
			state: useTaskFormState(props.item, props.prefill),
			userStore: useUserStore(),
			houseStore: useHouseStore(),
			roomsStore: useRoomsStore(),
			roomTasksStore: useRoomTasksStore(),
			taskFormStore: useTaskFormStore(),
		}
	},

	data() {
		return { saving: false, askingScope: false, confirmingDelete: false, membersError: false }
	},

	computed: {
		title() {
			return this.state.task ? t('chorestrike', 'Edit task') : t('chorestrike', 'New task')
		},

		/** The room type of the chosen room: the templates of that type come first. */
		roomType() {
			return this.roomsStore.getRoomById(this.state.roomId.value)?.roomType ?? null
		},

		tomorrow() {
			return addDaysToKey(todayKeyInZone(this.houseStore.timezone), 1)
		},

		detailsModel: {
			get() {
				return this.state.details
			},

			set(value) {
				Object.assign(this.state.details, value)
			},
		},
	},

	async mounted() {
		// The sub-tasks are read at once, not after the members: a save waits for that read.
		const subTasks = this.state.loadSubTasks()
		if (!this.userStore.houseUsers.length) {
			await this.loadMembers()
		}
		await subTasks
	},

	methods: {
		t,

		async loadMembers() {
			this.membersError = false
			try {
				await this.userStore.fetchHouseUsers()
			} catch {
				// The store logs the failure; the form owns the visible retry.
				this.membersError = true
			}
		},

		/**
		 * Save the form: one request either way. A definition several rooms
		 * share answers SCOPE_REQUIRED until a scope is chosen.
		 * @param {?string} scope `thisRoom` or `allRooms`, once asked.
		 */
		async save(scope = null) {
			// Off while a save runs, never `disabled`: a refusal leaves the focus on the button, inside the dialog.
			if (this.saving) {
				return
			}
			this.askingScope = false
			this.saving = true
			try {
				// A save while the sub-tasks still load waits for them: unknown, what was added would be dropped.
				await this.state.subTasksSettled()
				const fields = this.state.fields()
				if (this.state.task) {
					// The version the form opened with: after a refusal its content is stale, and a fresh version would overwrite the change it missed.
					await this.taskFormStore.save(this.state.roomTask.id, this.state.cycle?.expectedVersion ?? 0, scope ? { ...fields, scope } : fields)
				} else {
					await this.taskFormStore.create(fields)
					showSuccess(t('chorestrike', 'Task created'))
				}
				this.$emit('close')
			} catch (error) {
				if (error?.code === 'SCOPE_REQUIRED') {
					this.askingScope = true
				} else {
					showRefusal(error, this.state.task ? t('chorestrike', 'Could not save the task') : t('chorestrike', 'Failed to create task'))
				}
			} finally {
				this.saving = false
			}
		},

		async remove() {
			this.saving = true
			try {
				await this.roomTasksStore.deleteRoomTask(this.state.roomTask.id)
				this.confirmingDelete = false
				this.$emit('close')
			} catch (error) {
				showRefusal(error, t('chorestrike', 'Failed to delete task'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.task-form {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	max-width: 36rem;
	padding: 1rem;
}

.form-title {
	margin: 0;
	font-size: 1.2rem;
}

.field {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.field input {
	min-height: 44px;
	color: var(--color-main-text);
	font-size: 0.95rem;
}

.switch {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	min-height: 52px;
	border-top: 1px solid var(--color-border);
	cursor: pointer;
}

.switch input {
	width: 20px;
	height: 20px;
	margin: 0;
	accent-color: var(--color-primary-element);
}

.switch > span {
	display: flex;
	flex-direction: column;
}

.hint {
	margin: 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.form-actions {
	position: sticky;
	bottom: 0;
	display: flex;
	flex-wrap: wrap;
	justify-content: flex-end;
	gap: 0.5rem;
	padding: 0.75rem 0;
	border-top: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.form-actions button {
	min-height: 44px;
	padding: 0 1rem;
	border: 1px solid var(--color-border-dark);
	border-radius: 22px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.form-actions .primary {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.form-actions .primary:disabled,
.form-actions .primary[aria-disabled='true'] {
	opacity: 0.5;
	cursor: not-allowed;
}

.form-actions .danger {
	margin-inline-end: auto;
	border-color: var(--color-element-error);
	color: var(--color-error-text);
}
</style>
