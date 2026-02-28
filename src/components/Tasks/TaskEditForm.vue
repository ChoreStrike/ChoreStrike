<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<form class="task-edit-form" @submit.prevent="submit">
		<div class="form-section">
			<h3>{{ t('chorestrike', 'Basic Information') }}</h3>
			
			<div class="form-group">
				<label for="name">{{ t('chorestrike', 'Task Name') }} *</label>
				<input id="name"
					v-model.trim="form.name"
					type="text"
					class="form-input"
					required
					:placeholder="t('chorestrike', 'e.g., Vacuum living room')">
				</div>

			<div class="form-group">
				<label for="description">{{ t('chorestrike', 'Description') }}</label>
				<textarea id="description"
					v-model.trim="form.description"
					class="form-input"
					rows="2"
					:placeholder="t('chorestrike', 'Optional description...')">
				</textarea>
			</div>
		</div>

		<div class="form-section">
			<h3>{{ t('chorestrike', 'Task Properties') }}</h3>
			
			<div class="form-row">
				<div class="form-group half">
					<label for="energyCost">{{ t('chorestrike', 'Energy Cost') }}</label>
					<select id="energyCost" v-model.number="form.energyCost" class="form-input">
						<option v-for="n in 5" :key="n" :value="n">
							{{ '★'.repeat(n) }} ({{ t('chorestrike', energyLabels[n]) }})
						</option>
					</select>
				</div>

				<div class="form-group half">
					<label for="priority">{{ t('chorestrike', 'Priority') }}</label>
					<select id="priority" v-model.number="form.priority" class="form-input">
						<option :value="3">🔴 {{ t('chorestrike', 'High') }}</option>
						<option :value="2">🟡 {{ t('chorestrike', 'Normal') }}</option>
						<option :value="1">🟢 {{ t('chorestrike', 'Low') }}</option>
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
						<option value="cleaning">{{ t('chorestrike', 'Cleaning') }}</option>
						<option value="kitchen">{{ t('chorestrike', 'Kitchen') }}</option>
						<option value="bathroom">{{ t('chorestrike', 'Bathroom') }}</option>
						<option value="organizing">{{ t('chorestrike', 'Organizing') }}</option>
						<option value="maintenance">{{ t('chorestrike', 'Maintenance') }}</option>
						<option value="other">{{ t('chorestrike', 'Other') }}</option>
					</select>
				</div>
			</div>
		</div>

		<div class="form-section">
			<h3>{{ t('chorestrike', 'Schedule & Recurrence') }}</h3>
			
			<div class="form-row">
				<div class="form-group half">
					<label for="frequencyType">{{ t('chorestrike', 'Frequency') }}</label>
					<select id="frequencyType" v-model="form.frequencyType" class="form-input">
						<option value="daily">{{ t('chorestrike', 'Daily') }}</option>
						<option value="weekly">{{ t('chorestrike', 'Weekly') }}</option>
						<option value="monthly">{{ t('chorestrike', 'Monthly') }}</option>
					</select>
				</div>

				<div class="form-group half">
					<label for="frequencyValue">{{ t('chorestrike', 'Every') }}</label>
					<div class="input-with-suffix">
						<input id="frequencyValue"
							v-model.number="form.frequencyValue"
							type="number"
							class="form-input"
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
						<input type="radio" v-model="form.schedulingMode" value="dynamic">
						<span class="radio-text">
							<strong>{{ t('chorestrike', 'Dynamic') }}</strong>
							<small>{{ t('chorestrike', 'Next due shifts based on when you complete the task') }}</small>
						</span>
					</label>
					<label class="radio-label">
						<input type="radio" v-model="form.schedulingMode" value="static">
						<span class="radio-text">
							<strong>{{ t('chorestrike', 'Static') }}</strong>
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
							<input type="checkbox" :value="day.value" v-model="selectedWeekdays">
							<span>{{ day.label }}</span>
						</label>
					</div>
					<p v-if="selectedWeekdays.length === 0" class="field-error">
						{{ t('chorestrike', 'Please select at least one day') }}
					</p>
				</div>
			</div>
		</div>

		<div class="form-section">
			<h3>{{ t('chorestrike', 'Scope & Assignment') }}</h3>
			
			<div class="form-group">
				<label for="scopeType">{{ t('chorestrike', 'Scope') }}</label>
				<select id="scopeType" v-model="form.scopeType" class="form-input">
					<option value="room">{{ t('chorestrike', 'Single Room') }}</option>
					<option value="floor">{{ t('chorestrike', 'Whole Floor') }}</option>
					<option value="house">{{ t('chorestrike', 'Entire House') }}</option>
				</select>
			</div>
		</div>

		<div class="form-actions">
			<button type="button" class="secondary-button" @click="$emit('cancel')">
				<Icon name="X" :size="16" class="icon" />
				{{ t('chorestrike', 'Cancel') }}
			</button>
			<button type="submit" class="primary-button" :disabled="!isValid || loading">
				<Icon name="Save" :size="16" class="icon" />
				{{ isEditing ? t('chorestrike', 'Update Task') : t('chorestrike', 'Create Task') }}
			</button>
		</div>
	</form>
</template>

<script>
import { computed, reactive, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { Icon } from '../Common'

export default {
	name: 'TaskEditForm',
	
	components: {
		Icon,
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
	},

	emits: ['submit', 'cancel'],

	setup(props, { emit }) {
		const isEditing = computed(() => !!props.task)

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
			if (!daysJson) return []
			try {
				return JSON.parse(daysJson)
			} catch {
				return []
			}
		}

		const form = reactive({
			name: '',
			description: '',
			energyCost: 2,
			priority: 2,
			estimatedMinutes: 15,
			category: 'cleaning',
			frequencyType: 'weekly',
			frequencyValue: 1,
			schedulingMode: 'dynamic',
			fixedScheduleTime: '',
			scopeType: 'room',
		})

		const selectedWeekdays = ref([])

		// Initialize form from task prop when editing
		watch(() => props.task, (newTask) => {
			if (newTask) {
				form.name = newTask.name || ''
				form.description = newTask.description || ''
				form.energyCost = newTask.energyCost || 2
				form.priority = newTask.priority || 2
				form.estimatedMinutes = newTask.estimatedMinutes || 15
				form.category = newTask.category || 'cleaning'
				form.frequencyType = newTask.frequencyType || 'weekly'
				form.frequencyValue = newTask.frequencyValue || 1
				form.schedulingMode = newTask.schedulingMode || 'dynamic'
				form.fixedScheduleTime = newTask.fixedScheduleTime || ''
				form.scopeType = newTask.scopeType || 'room'
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
			if (!form.name?.trim()) return false
			if (form.schedulingMode === 'static' && !form.fixedScheduleTime) return false
			if (form.schedulingMode === 'static' && form.frequencyType === 'weekly' && selectedWeekdays.value.length === 0) return false
			return true
		})

		const submit = () => {
			const data = {
				...form,
				fixedScheduleDays: form.schedulingMode === 'static' && form.frequencyType === 'weekly'
					? JSON.stringify(selectedWeekdays.value)
					: null,
			}
			emit('submit', data)
		}

		return {
			form,
			isEditing,
			energyLabels,
			weekdays,
			selectedWeekdays,
			frequencySuffix,
			isValid,
			submit,
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
	border-color: var(--color-primary);
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
	border-color: var(--color-primary);
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
	border: 1px solid var(--color-border);
	border-radius: 20px;
	cursor: pointer;
	transition: all 0.2s;
	background: var(--color-main-background);
}

.weekday-chip:hover {
	border-color: var(--color-primary);
}

.weekday-chip input[type="checkbox"] {
	display: none;
}

.weekday-chip input:checked + span {
	color: var(--color-primary);
	font-weight: 600;
}

.weekday-chip:has(input:checked) {
	background: var(--color-primary-light);
	border-color: var(--color-primary);
}

.field-error {
	color: var(--color-error);
	font-size: 0.85rem;
	margin-top: 0.5rem;
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
	color: #fff;
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
