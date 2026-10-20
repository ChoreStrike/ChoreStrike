<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<form class="task-edit-form" @submit.prevent="submit">
		<!-- Template Selection (only when creating): recurrence presets under
		     an honest "Repeat" label (CS-PLAN-063 D4) + catalog prefill -->
		<div v-if="!isEditing" class="form-section">
			<h3>{{ t('chorestrike', 'Start from Template') }}</h3>
			<RecurrencePresetSelector
				:selected-template="selectedTemplate"
				@select="applyTemplate"
				@clear="clearTemplate" />

			<div v-if="catalogTemplates.length > 0" class="form-group">
				<label for="catalog-prefill">{{ t('chorestrike', 'Prefill from catalog') }}</label>
				<select id="catalog-prefill"
					v-model="appliedCatalogTemplateId"
					class="form-input"
					data-testid="task-catalog-prefill">
					<option :value="null">
						{{ t('chorestrike', 'No prefill') }}
					</option>
					<option v-for="tpl in catalogTemplates" :key="tpl.id" :value="tpl.id">
						{{ tpl.name }}
					</option>
				</select>
				<p class="visibility-hint">
					{{ t('chorestrike', 'Suggestions for the selected room type — everything stays editable') }}
				</p>
			</div>
		</div>

		<div class="form-section">
			<h3>{{ t('chorestrike', 'Basic Information') }}</h3>

			<div class="form-group">
				<label for="name">{{ t('chorestrike', 'Task Name') }} *</label>
				<input id="name"
					v-model.trim="form.name"
					type="text"
					class="form-input"
					data-testid="task-name-input"
					required
					:placeholder="t('chorestrike', 'e.g., Vacuum living room')">
			</div>

			<div class="form-group">
				<label for="description">{{ t('chorestrike', 'Description') }}</label>
				<textarea id="description"
					v-model.trim="form.description"
					class="form-input"
					data-testid="task-description-input"
					rows="2"
					:placeholder="t('chorestrike', 'Optional description...')" />
			</div>

			<div class="form-group">
				<label for="instructions">{{ t('chorestrike', 'Instructions') }}</label>
				<textarea id="instructions"
					v-model.trim="form.instructions"
					class="form-input"
					data-testid="task-instructions-input"
					rows="3"
					maxlength="2000"
					:placeholder="t('chorestrike', 'Optional step-by-step instructions...')" />
			</div>
		</div>

		<div class="form-section">
			<h3>{{ t('chorestrike', 'Task Properties') }}</h3>

			<div class="form-row">
				<div class="form-group half">
					<label for="energyCost">{{ t('chorestrike', 'Energy Cost') }}</label>
					<select id="energyCost"
						v-model.number="form.energyCost"
						class="form-input"
						data-testid="task-energy-select">
						<option v-for="n in 5" :key="n" :value="n">
							{{ '★'.repeat(n) }} ({{ t('chorestrike', energyLabels[n]) }})
						</option>
					</select>
				</div>

				<div class="form-group half">
					<label for="priority">{{ t('chorestrike', 'Priority') }}</label>
					<select id="priority"
						v-model.number="form.priority"
						class="form-input"
						data-testid="task-priority-select">
						<option :value="3">
							🔴 {{ t('chorestrike', 'High') }}
						</option>
						<option :value="2">
							🟡 {{ t('chorestrike', 'Normal') }}
						</option>
						<option :value="1">
							🟢 {{ t('chorestrike', 'Low') }}
						</option>
					</select>
				</div>
			</div>

			<div class="form-row">
				<div class="form-group half">
					<label for="estimatedMinutes">{{ t('chorestrike', 'Estimated Time (minutes)') }}</label>
					<input id="estimatedMinutes"
						v-model.number="form.estimatedMinutes"
						type="number"
						class="form-input"
						min="1"
						max="480">
				</div>

				<div class="form-group half">
					<label for="category">{{ t('chorestrike', 'Category') }}</label>
					<select id="category" v-model="form.category" class="form-input">
						<option value="cleaning">
							{{ t('chorestrike', 'Cleaning') }}
						</option>
						<option value="kitchen">
							{{ t('chorestrike', 'Kitchen') }}
						</option>
						<option value="bathroom">
							{{ t('chorestrike', 'Bathroom') }}
						</option>
						<option value="organizing">
							{{ t('chorestrike', 'Organizing') }}
						</option>
						<option value="maintenance">
							{{ t('chorestrike', 'Maintenance') }}
						</option>
						<option value="other">
							{{ t('chorestrike', 'Other') }}
						</option>
					</select>
				</div>
			</div>
		</div>

		<div class="form-section">
			<h3>{{ t('chorestrike', 'Schedule & Recurrence') }}</h3>

			<!-- Creation due date (CS-PLAN-063 §3.5): the explicit first due
			     date, house-local calendar day. Empty = tomorrow default. -->
			<div v-if="!isEditing" class="form-group">
				<label for="nextDue">{{ t('chorestrike', 'First due date') }}</label>
				<input id="nextDue"
					v-model="nextDue"
					type="date"
					class="form-input"
					data-testid="task-next-due-input">
				<p class="visibility-hint">
					{{ t('chorestrike', 'Leave empty to start tomorrow') }}
				</p>
			</div>

			<div class="form-row">
				<div class="form-group half">
					<label for="frequencyType">{{ t('chorestrike', 'Frequency') }}</label>
					<select id="frequencyType"
						v-model="form.frequencyType"
						class="form-input"
						data-testid="task-frequency-type">
						<option value="daily">
							{{ t('chorestrike', 'Daily') }}
						</option>
						<option value="weekly">
							{{ t('chorestrike', 'Weekly') }}
						</option>
						<option value="monthly">
							{{ t('chorestrike', 'Monthly') }}
						</option>
					</select>
				</div>

				<div class="form-group half">
					<label for="frequencyValue">{{ t('chorestrike', 'Every') }}</label>
					<div class="input-with-suffix">
						<input id="frequencyValue"
							v-model.number="form.frequencyValue"
							type="number"
							class="form-input"
							data-testid="task-frequency-value"
							min="1"
							max="52">
						<span class="suffix">{{ frequencySuffix }}</span>
					</div>
				</div>
			</div>

			<!-- Scheduling Mode -->
			<div class="form-group">
				<label>{{ t('chorestrike', 'Scheduling Mode') }}</label>
				<div class="radio-group">
					<label class="radio-label">
						<input v-model="form.schedulingMode" type="radio" value="dynamic">
						<span class="radio-text">
							<strong>{{ t('chorestrike', 'Flexible') }}</strong>
							<small>{{ t('chorestrike', 'Next due shifts based on when you complete the task') }}</small>
						</span>
					</label>
					<label class="radio-label">
						<input v-model="form.schedulingMode" type="radio" value="static">
						<span class="radio-text">
							<strong>{{ t('chorestrike', 'Fixed schedule') }}</strong>
							<small>{{ t('chorestrike', 'Fixed schedule (e.g., every Monday 18:00)') }}</small>
						</span>
					</label>
				</div>
			</div>

			<!-- Static Schedule Options -->
			<div v-if="form.schedulingMode === 'static'" class="static-schedule-options">
				<div class="form-group">
					<label for="fixedScheduleTime">{{ t('chorestrike', 'Time of Day') }}</label>
					<input id="fixedScheduleTime"
						v-model="form.fixedScheduleTime"
						type="time"
						class="form-input"
						required>
				</div>

				<div v-if="form.frequencyType === 'weekly'" class="form-group">
					<label>{{ t('chorestrike', 'Days of Week') }}</label>
					<div class="weekday-selector">
						<label v-for="day in weekdays" :key="day.value" class="weekday-chip">
							<input
								v-model="selectedWeekdays"
								type="checkbox"
								:value="day.value"
								class="cs-visually-hidden">
							<span>{{ day.label }}</span>
						</label>
					</div>
					<p v-if="selectedWeekdays.length === 0" class="field-error">
						{{ t('chorestrike', 'Please select at least one day') }}
					</p>
				</div>
			</div>

			<!-- Flex Window Options -->
			<div class="form-group">
				<label for="flexDays">{{ t('chorestrike', 'Flex Days') }}</label>
				<div class="flex-days-input">
					<input id="flexDays"
						v-model.number="form.flexDays"
						type="number"
						class="form-input"
						min="0"
						max="7"
						data-testid="task-flex-days-input">
					<span class="flex-days-hint">
						{{ t('chorestrike', 'Flexible scheduling window in days') }}
					</span>
				</div>
				<p v-if="form.flexDays > 0" class="flex-days-info">
					<Icon name="Info" :size="14" />
					{{ t('chorestrike', 'Task can be completed {days} days before or after ideal date', { days: form.flexDays }) }}
				</p>
			</div>
		</div>

		<!-- Where & Who (creation only: every custom task is room-scoped and
		     may be assigned right away; editing only touches the definition) -->
		<div v-if="!isEditing" class="form-section">
			<h3>{{ t('chorestrike', 'Where & Who') }}</h3>

			<div class="form-group">
				<label>{{ t('chorestrike', 'Room') }} *</label>
				<RoomPicker v-model="selectedRoomId" :preset-room-id="presetRoomId" />
			</div>

			<div class="form-group">
				<label>{{ t('chorestrike', 'Assigned members') }}</label>
				<ErrorState v-if="membersError"
					data-testid="member-load-error"
					:message="t('chorestrike', 'Failed to load members')"
					@retry="loadMembers" />
				<p v-else-if="membersLoading">
					{{ t('chorestrike', 'Loading...') }}
				</p>
				<AssigneeChips v-else v-model="assigneeUserIds" :members="houseMembers" />
			</div>
		</div>

		<div class="form-section">
			<h3>{{ t('chorestrike', 'Visibility') }}</h3>

			<div class="form-group">
				<label for="visibilityType">{{ t('chorestrike', 'Visibility') }}</label>
				<select id="visibilityType"
					v-model="form.visibilityType"
					class="form-input"
					data-testid="task-visibility-select">
					<option value="public">
						{{ t('chorestrike', 'Public') }}
					</option>
					<option value="private">
						{{ t('chorestrike', 'Private') }}
					</option>
					<option v-if="isOwner" value="admin-only">
						{{ t('chorestrike', 'Admins only') }}
					</option>
				</select>
				<p class="visibility-hint" data-testid="task-visibility-hint">
					{{ visibilityHint }}
				</p>
			</div>
		</div>

		<!-- Concise creation preview (CS-PLAN-063 §3.5) -->
		<div v-if="!isEditing" class="form-section preview-section" data-testid="task-preview">
			<h3>{{ t('chorestrike', 'Preview') }}</h3>
			<ul class="preview-list">
				<li>
					<span class="preview-label">{{ t('chorestrike', 'Room') }}</span>
					<span>{{ previewRoomPath }}</span>
				</li>
				<li>
					<span class="preview-label">{{ t('chorestrike', 'Repeat') }}</span>
					<span>{{ previewRecurrence }}</span>
				</li>
				<li>
					<span class="preview-label">{{ t('chorestrike', 'First due') }}</span>
					<span>{{ previewDueWindow }}</span>
				</li>
				<li>
					<span class="preview-label">{{ t('chorestrike', 'Assigned') }}</span>
					<span>{{ previewAssignees }}</span>
				</li>
				<li>
					<span class="preview-label">{{ t('chorestrike', 'Visibility') }}</span>
					<span>{{ form.visibilityType === 'private' ? t('chorestrike', 'Private') : form.visibilityType === 'admin-only' ? t('chorestrike', 'Admins only') : t('chorestrike', 'Public') }}</span>
				</li>
			</ul>
		</div>

		<div class="form-actions">
			<button type="button" class="secondary-button" @click="$emit('cancel')">
				<Icon name="X" :size="16" class="icon" />
				{{ t('chorestrike', 'Cancel') }}
			</button>
			<button type="submit"
				class="primary-button"
				data-testid="save-task-button"
				:disabled="!isValid || loading">
				<Icon name="Save" :size="16" class="icon" />
				{{ isEditing ? t('chorestrike', 'Update Task') : t('chorestrike', 'Create Task') }}
			</button>
		</div>
	</form>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import ErrorState from '../Common/ErrorState.vue'
import AssigneeChips from './AssigneeChips.vue'
import RecurrencePresetSelector from './RecurrencePresetSelector.vue'
import RoomPicker from './RoomPicker.vue'
import { VISIBILITY_ADMIN_ONLY, VISIBILITY_PRIVATE, VISIBILITY_PUBLIC } from '../../constants/taskVisibility.js'
import { useRoomsStore } from '../../store/rooms.js'
import { useSetupStore } from '../../store/setup.js'
import { useUserStore } from '../../store/user.js'
import { logger } from '../../utils/logger.js'
import { Icon } from '../Common/index.js'

export default {
	name: 'TaskEditForm',

	components: {
		Icon,
		RecurrencePresetSelector,
		RoomPicker,
		AssigneeChips,
		ErrorState,
	},

	props: {
		task: {
			type: Object,
			default: null,
		},
		loading: {
			type: Boolean,
			default: false,
		},
		// Fixed room for creation from a room's context (locked picker)
		presetRoomId: {
			type: Number,
			default: null,
		},
	},

	emits: ['submit', 'cancel'],

	setup(props, { emit }) {
		const isEditing = computed(() => !!props.task)
		const userStore = useUserStore()
		const roomsStore = useRoomsStore()
		const setupStore = useSetupStore()
		const isOwner = computed(() => userStore.isHouseOwner)
		// CS-PLAN-068: the assignee chips render ONLY from the members prop
		// (AssigneeChips performs no fetch of its own) — without this the
		// creation form could never assign anyone.
		const houseMembers = computed(() => userStore.houseUsers)
		const membersError = ref(false)
		const membersLoading = ref(false)
		const loadMembers = async () => {
			membersError.value = false
			membersLoading.value = true
			try {
				await userStore.fetchHouseUsers()
			} catch {
				// The store logs the failure; the form owns visible recovery.
				membersError.value = true
			} finally {
				membersLoading.value = false
			}
		}
		onMounted(() => {
			if (!userStore.houseUsers.length) {
				loadMembers()
			}
		})

		const energyLabels = {
			1: 'Quick',
			2: 'Easy',
			3: 'Medium',
			4: 'Hard',
			5: 'Major',
		}

		const weekdays = [
			{ value: 'monday', label: t('chorestrike', 'Mon') },
			{ value: 'tuesday', label: t('chorestrike', 'Tue') },
			{ value: 'wednesday', label: t('chorestrike', 'Wed') },
			{ value: 'thursday', label: t('chorestrike', 'Thu') },
			{ value: 'friday', label: t('chorestrike', 'Fri') },
			{ value: 'saturday', label: t('chorestrike', 'Sat') },
			{ value: 'sunday', label: t('chorestrike', 'Sun') },
		]

		// Parse fixedScheduleDays from task or default to empty
		const parseWeekdays = (daysJson) => {
			if (!daysJson) {
				return []
			}
			try {
				const parsed = typeof daysJson === 'string' ? JSON.parse(daysJson) : daysJson
				return Array.isArray(parsed) ? parsed : []
			} catch {
				return []
			}
		}

		// The selected recurrence preset id (honest wiring, CS-PLAN-063 D4).
		const selectedTemplate = ref(null)

		const form = reactive({
			name: '',
			description: '',
			instructions: '',
			energyCost: 2,
			priority: 2,
			estimatedMinutes: 15,
			category: 'cleaning',
			frequencyType: 'weekly',
			frequencyValue: 1,
			recurrenceMode: 'recurring',
			schedulingMode: 'dynamic',
			fixedScheduleTime: '',
			flexDays: 0,
			visibilityType: VISIBILITY_PUBLIC,
		})

		// Creation-only due date (kept out of the editable-definition form
		// payload — it is a room-task property, CS-PLAN-063 §3.5).
		const nextDue = ref('')

		const selectedWeekdays = ref([])
		const selectedRoomId = ref(props.presetRoomId)
		const assigneeUserIds = ref([])

		// Catalog prefill (CS-PLAN-063 D4): templates for the selected
		// room's type, from the same GET /setup/catalog surface the
		// TaskTemplatePicker consumes.
		const appliedCatalogTemplateId = ref(null)
		const catalogTemplates = ref([])

		const loadCatalogForRoom = async (roomId) => {
			catalogTemplates.value = []
			appliedCatalogTemplateId.value = null
			const room = roomsStore.getRoomById(roomId)
			if (!room) {
				return
			}
			try {
				if (!setupStore.hasCatalog) {
					await setupStore.fetchCatalog()
				}
				const roomType = room.roomType || 'generic'
				catalogTemplates.value = (setupStore.templates || []).filter((tpl) => {
					const types = tpl.roomTypes || []
					return types.includes(roomType)
				})
			} catch (error) {
				logger.error('Catalog prefill load failed', error, { component: 'TaskEditForm', action: 'loadCatalogForRoom', roomId })
			}
		}

		watch(selectedRoomId, (roomId) => {
			if (!isEditing.value) {
				loadCatalogForRoom(roomId)
			}
		}, { immediate: true })

		// Apply the recurrence preset's OWN shape only (CS-PLAN-063 D4):
		// frequency from the preset, one-time maps to the `once` recurrence.
		const applyTemplate = (preset) => {
			selectedTemplate.value = preset?.id ?? null
			if (preset?.recurrence) {
				form.frequencyType = preset.recurrence
				form.frequencyValue = 1
				form.recurrenceMode = 'recurring'
				form.schedulingMode = 'dynamic'
			} else {
				form.recurrenceMode = 'once'
			}
		}

		const clearTemplate = () => {
			selectedTemplate.value = null
		}

		// Catalog prefill fills supported definition fields (D4) — the level
		// key matches the TaskTemplatePicker default (putzteufel).
		const applyCatalogTemplate = (templateId) => {
			const tpl = catalogTemplates.value.find((t) => t.id === templateId)
			if (!tpl) {
				return
			}
			form.name = tpl.name || form.name
			form.category = tpl.category || form.category
			form.energyCost = tpl.energyCost || form.energyCost
			form.priority = tpl.priority || form.priority
			const level = (tpl.levels || {}).putzteufel || Object.values(tpl.levels || {})[0]
			if (level) {
				form.frequencyType = level.frequencyType || form.frequencyType
				form.frequencyValue = level.frequencyValue || form.frequencyValue
				form.estimatedMinutes = level.estimatedMinutes || form.estimatedMinutes
			}
			form.instructions = tpl.instructions || form.instructions
			selectedTemplate.value = null
		}

		watch(appliedCatalogTemplateId, (templateId) => {
			if (templateId) {
				applyCatalogTemplate(templateId)
			}
		})

		// Initialize form from task prop when editing
		watch(() => props.task, (newTask) => {
			if (newTask) {
				form.name = newTask.name || ''
				form.description = newTask.description || ''
				form.instructions = newTask.instructions || ''
				form.energyCost = newTask.energyCost || 2
				form.priority = newTask.priority || 2
				form.estimatedMinutes = newTask.estimatedMinutes || 15
				form.category = newTask.category || 'cleaning'
				form.frequencyType = newTask.frequencyType || 'weekly'
				form.frequencyValue = newTask.frequencyValue || 1
				form.recurrenceMode = newTask.recurrenceMode || 'recurring'
				form.schedulingMode = newTask.schedulingMode || 'dynamic'
				form.fixedScheduleTime = newTask.fixedScheduleTime || ''
				form.flexDays = newTask.flexDays || 0
				form.visibilityType = newTask.visibilityType || VISIBILITY_PUBLIC
				selectedWeekdays.value = parseWeekdays(newTask.fixedScheduleDays)
			}
		}, { immediate: true })

		const frequencySuffix = computed(() => {
			const suffixes = {
				daily: form.frequencyValue === 1 ? t('chorestrike', 'day') : t('chorestrike', 'days'),
				weekly: form.frequencyValue === 1 ? t('chorestrike', 'week') : t('chorestrike', 'weeks'),
				monthly: form.frequencyValue === 1 ? t('chorestrike', 'month') : t('chorestrike', 'months'),
			}
			return suffixes[form.frequencyType] || ''
		})

		const isValid = computed(() => {
			if (!form.name?.trim()) {
				return false
			}
			if (!isEditing.value && !selectedRoomId.value) {
				return false
			}
			if (form.schedulingMode === 'static' && !form.fixedScheduleTime) {
				return false
			}
			if (form.schedulingMode === 'static' && form.frequencyType === 'weekly' && selectedWeekdays.value.length === 0) {
				return false
			}
			return true
		})

		const visibilityHint = computed(() => {
			switch (form.visibilityType) {
			// Real matrix (CS-PLAN-061 §3.2, D2): private tasks are visible
			// to their creator AND the explicitly assigned members.
				case VISIBILITY_PRIVATE:
					return t('chorestrike', 'Only you and the assigned members can see and complete this task. Assigned members can also edit it.')
				case VISIBILITY_ADMIN_ONLY:
					return t('chorestrike', 'Only house owners can see and complete this task')
				default:
					return t('chorestrike', 'All house members can see and complete this task')
			}
		})

		// Concise preview (CS-PLAN-063 §3.5): room path, recurrence summary,
		// due/flex window, assignees — read-only reflections of the form.
		const previewRoomPath = computed(() => {
			const room = roomsStore.getRoomById(selectedRoomId.value)
			if (!room) {
				return t('chorestrike', 'No room selected')
			}
			const floor = roomsStore.getFloorById(room.floorId)
			return floor ? `${floor.name} → ${room.name}` : room.name
		})

		const previewRecurrence = computed(() => {
			if (form.recurrenceMode === 'once') {
				return t('chorestrike', 'One-time task')
			}
			const frequencyLabel = form.frequencyType === 'daily' ? 'Daily' : form.frequencyType === 'weekly' ? 'Weekly' : 'Monthly'
			// Plural phrases reuse the established per-type vocabulary
			// ('Every {n} weeks' &c.) so translations stay natural.
			const everyKey = form.frequencyType === 'daily'
				? 'Every {n} days'
				: form.frequencyType === 'monthly' ? 'Every {n} months' : 'Every {n} weeks'
			const frequency = form.frequencyValue === 1
				? t('chorestrike', frequencyLabel)
				: t('chorestrike', everyKey, { n: form.frequencyValue })
			if (form.schedulingMode === 'static') {
				const days = selectedWeekdays.value.length > 0 ? ` (${selectedWeekdays.value.join(', ')})` : ''
				const time = form.fixedScheduleTime ? ` ${form.fixedScheduleTime}` : ''
				return `${frequency}${time}${days}`
			}
			return frequency
		})

		const previewDueWindow = computed(() => {
			const due = nextDue.value || t('chorestrike', 'tomorrow')
			if (form.flexDays > 0) {
				return t('chorestrike', '{due} (±{days} days)', { due, days: form.flexDays })
			}
			return due
		})

		const previewAssignees = computed(() => {
			if (assigneeUserIds.value.length === 0) {
				return t('chorestrike', 'Unassigned')
			}
			return assigneeUserIds.value.join(', ')
		})

		const submit = () => {
			const data = {
				...form,
				fixedScheduleDays: form.schedulingMode === 'static' && form.frequencyType === 'weekly'
					? JSON.stringify(selectedWeekdays.value)
					: null,
			}
			if (!isEditing.value) {
				data.roomId = selectedRoomId.value
				data.assigneeUserIds = [...assigneeUserIds.value]
				data.nextDue = nextDue.value || null
			}
			emit('submit', data)
		}

		return {
			form,
			nextDue,
			isEditing,
			isOwner,
			houseMembers,
			membersError,
			membersLoading,
			loadMembers,
			selectedTemplate,
			selectedRoomId,
			assigneeUserIds,
			energyLabels,
			weekdays,
			selectedWeekdays,
			frequencySuffix,
			isValid,
			visibilityHint,
			catalogTemplates,
			appliedCatalogTemplateId,
			previewRoomPath,
			previewRecurrence,
			previewDueWindow,
			previewAssignees,
			submit,
			applyTemplate,
			clearTemplate,
			t,
		}
	},
}
</script>

<style scoped>
.task-edit-form {
	padding: 1.5rem;
	max-width: 600px;
}

.form-section {
	margin-bottom: 1.5rem;
	padding-bottom: 1.5rem;
	border-bottom: 1px solid var(--color-border);
}

.form-section:last-of-type {
	border-bottom: none;
}

.form-section h3 {
	margin: 0 0 1rem 0;
	font-size: 1rem;
	color: var(--color-main-text);
}

.form-group {
	margin-bottom: 1rem;
}

.form-group label {
	display: block;
	margin-bottom: 0.4rem;
	font-size: 0.9rem;
	font-weight: 600;
}

.form-row {
	display: flex;
	gap: 1rem;
}

.form-group.half {
	flex: 1;
}

.form-input {
	width: 100%;
	padding: 0.6rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.95rem;
}

.form-input:focus {
	border-color: var(--color-primary-element);
	outline: none;
}

.input-with-suffix {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.input-with-suffix .form-input {
	width: 80px;
}

.suffix {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

/* Radio Group */
.radio-group {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.radio-label {
	display: flex;
	align-items: flex-start;
	gap: 0.75rem;
	padding: 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	cursor: pointer;
	transition: border-color 0.2s, background-color 0.2s;
}

.radio-label:hover {
	border-color: var(--color-primary-element);
	background: var(--color-background-hover);
}

.radio-label input[type="radio"] {
	margin-top: 0.2rem;
}

.radio-text {
	display: flex;
	flex-direction: column;
	gap: 0.2rem;
}

.radio-text strong {
	font-weight: 600;
}

.radio-text small {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

/* Checkbox label (e.g. private task) */
.checkbox-label {
	display: flex;
	align-items: flex-start;
	gap: 0.6rem;
	cursor: pointer;
}

.checkbox-label input[type="checkbox"] {
	margin-top: 0.2rem;
}

.checkbox-text {
	display: flex;
	flex-direction: column;
	gap: 0.15rem;
}

.checkbox-text small {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.visibility-hint {
	margin-top: 0.4rem;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

/* Static Schedule Options */
.static-schedule-options {
	margin-top: 1rem;
	padding: 1rem;
	background: var(--color-background-dark);
	border-radius: 8px;
}

/* Weekday Selector */
.weekday-selector {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
}

.weekday-chip {
	display: flex;
	align-items: center;
	gap: 0.3rem;
	padding: 0.4rem 0.6rem;
	min-height: 44px;
	border: 1px solid var(--color-border);
	border-radius: 20px;
	cursor: pointer;
	transition: all 0.2s;
	background: var(--color-main-background);
}

.weekday-chip:hover {
	border-color: var(--color-primary-element);
}

.weekday-chip:focus-within {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.weekday-chip input:checked + span {
	color: var(--color-primary-element);
	font-weight: 600;
}

.weekday-chip:has(input:checked) {
	background: var(--color-primary-light);
	border-color: var(--color-primary-element);
}

.field-error {
	color: var(--color-error-text);
	font-size: 0.85rem;
	margin-top: 0.5rem;
}

/* Flex Days Input */
.flex-days-input {
	display: flex;
	align-items: center;
	gap: 0.75rem;
}

.flex-days-input .form-input {
	width: 80px;
}

.flex-days-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.flex-days-info {
	display: flex;
	align-items: center;
	gap: 0.4rem;
	margin-top: 0.5rem;
	color: var(--color-primary-element);
	font-size: 0.85rem;
}

/* Preview block (CS-PLAN-063 §3.5) */
.preview-section {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 1rem;
	margin-bottom: 1.5rem;
}

.preview-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 0.4rem;
}

.preview-list li {
	display: flex;
	gap: 0.75rem;
	font-size: 0.9rem;
}

.preview-label {
	flex: 0 0 90px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

/* Form Actions */
.form-actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.75rem;
	margin-top: 1.5rem;
	padding-top: 1rem;
	border-top: 1px solid var(--color-border);
}

.primary-button,
.secondary-button {
	display: flex;
	align-items: center;
	gap: 0.4rem;
	border: none;
	border-radius: 6px;
	padding: 0.7rem 1rem;
	cursor: pointer;
	font-size: 0.95rem;
	transition: opacity 0.2s;
}

.primary-button {
	background: var(--color-primary);
	color: var(--color-primary-text);
}

.primary-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.secondary-button {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}
</style>
