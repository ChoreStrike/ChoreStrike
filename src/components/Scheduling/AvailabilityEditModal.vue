<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<Modal
		:aria-label="t('chorestrike', 'Edit Availability')"
		data-testid="edit-availability-modal"
		@close="close">
		<div class="edit-form">
			<div class="form-section">
				<h4>{{ t('chorestrike', 'Weekly Availability') }}</h4>
				<div class="availability-toggle-grid">
					<div
						v-for="day in weekDays"
						:key="day.key"
						class="toggle-item">
						<label class="toggle-label">
							<input
								v-model="editForm.availability[day.key]"
								type="checkbox"
								class="toggle-input cs-visually-hidden"
								:data-testid="`availability-${day.key}`">
							<span class="toggle-slider" />
							<span class="toggle-text">{{ day.name }}</span>
						</label>
					</div>
				</div>
			</div>

			<div class="form-section">
				<h4>{{ t('chorestrike', 'Daily Energy Budget') }}</h4>
				<p class="section-description">
					{{ t('chorestrike', 'Set how much energy you have for tasks each day (1-20)') }}
				</p>
				<div class="energy-budget-grid">
					<div
						v-for="day in weekDays"
						:key="`budget-${day.key}`"
						class="budget-input-item">
						<label :for="`budget-${day.key}`">{{ day.name }}</label>
						<div class="input-group">
							<input
								:id="`budget-${day.key}`"
								v-model.number="editForm.energyBudget[day.key]"
								type="number"
								:min="ENERGY_MIN"
								:max="ENERGY_MAX"
								class="number-input"
								:data-testid="`budget-${day.key}-input`">
							<span class="input-suffix">{{ t('chorestrike', 'energy') }}</span>
						</div>
					</div>
				</div>
			</div>

			<!-- Decision D5: the child daily-task limit is owner-only and only
		     editable for OTHER members — the personal endpoints reject it. -->
			<div v-if="!isSelf" class="form-section">
				<h4>{{ t('chorestrike', 'Daily Task Limit') }}</h4>
				<p class="section-description">
					{{ t('chorestrike', 'Limit how many tasks this member sees per day in My Day (1-100)') }}
				</p>
				<div class="limit-row">
					<label class="toggle-label">
						<input
							v-model="limitEnabled"
							type="checkbox"
							class="toggle-input cs-visually-hidden"
							data-testid="limit-enabled-toggle">
						<span class="toggle-slider" />
						<span class="toggle-text">{{ t('chorestrike', 'Limit daily tasks') }}</span>
					</label>
					<div v-if="limitEnabled" class="input-group limit-input-group">
						<input
							v-model.number="limitValue"
							type="number"
							:min="LIMIT_MIN"
							:max="LIMIT_MAX"
							class="number-input"
							data-testid="limit-value-input">
						<span class="input-suffix">{{ t('chorestrike', 'tasks/day') }}</span>
					</div>
				</div>
			</div>

			<div class="form-section">
				<h4>{{ t('chorestrike', 'Quick Templates') }}</h4>
				<div class="template-buttons">
					<button
						type="button"
						class="template-button"
						data-testid="template-weekend-warrior"
						@click="applyTemplate('weekend-warrior')">
						{{ t('chorestrike', 'Weekend Warrior') }}
						<span class="template-desc">{{ t('chorestrike', 'High energy on weekends') }}</span>
					</button>
					<button
						type="button"
						class="template-button"
						data-testid="template-balanced"
						@click="applyTemplate('balanced')">
						{{ t('chorestrike', 'Balanced') }}
						<span class="template-desc">{{ t('chorestrike', 'Even distribution') }}</span>
					</button>
					<button
						type="button"
						class="template-button"
						data-testid="template-minimal"
						@click="applyTemplate('minimal')">
						{{ t('chorestrike', 'Minimal') }}
						<span class="template-desc">{{ t('chorestrike', 'Low energy schedule') }}</span>
					</button>
				</div>
			</div>
		</div>

		<template #footer>
			<div class="modal-footer">
				<button
					class="secondary-button"
					data-testid="cancel-edit-button"
					@click="close">
					{{ t('chorestrike', 'Cancel') }}
				</button>
				<button
					class="primary-button"
					:disabled="saving || !editHasChanges"
					data-testid="save-availability-button"
					@click="saveAvailability">
					{{ saving ? t('chorestrike', 'Saving...') : t('chorestrike', 'Save Changes') }}
				</button>
			</div>
		</template>
	</Modal>
</template>

<script setup>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import Modal from '../Common/Modal.vue'
import { ENERGY_MAX, ENERGY_MIN, MAX_DAILY_TASKS_MAX, MAX_DAILY_TASKS_MIN, weekDays } from '../../constants/scheduling.js'
import { useSchedulingStore } from '../../store/scheduling.js'
import { resolveCurrentUser } from '../../utils/currentUser.js'
import { createDefaultEnergyBudget } from '../../utils/energyBudgets.js'
import { logger } from '../../utils/logger.js'

const props = defineProps({
	member: {
		type: Object,
		required: true,
	},
})

const emit = defineEmits(['close'])

const schedulingStore = useSchedulingStore()

// UX-014: the owner edits any member with this one editor — the current
// user's own week goes through the self-service bulk PUT, other members
// through the owner-only member preferences PUT.
let currentUserId = null
try {
	currentUserId = resolveCurrentUser()?.uid ?? null
} catch (error) {
	currentUserId = null
}
const isSelf = computed(() => props.member.userId === currentUserId)

const LIMIT_MIN = MAX_DAILY_TASKS_MIN
const LIMIT_MAX = MAX_DAILY_TASKS_MAX

const saving = ref(false)
const editForm = ref({
	availability: { ...props.member.weekdayAvailability },
	energyBudget: { ...props.member.energyBudget },
})
// Decision D5: the stored limit is `null` (unlimited) or a finite int.
// Disabled toggle + previously finite stored limit → explicit null is sent;
// otherwise the key is omitted and the stored value stays untouched.
const limitEnabled = ref(Number.isFinite(props.member.maxDailyTasks))
const limitValue = ref(limitEnabled.value ? props.member.maxDailyTasks : '')

const storedLimit = () => (Number.isFinite(props.member.maxDailyTasks) ? props.member.maxDailyTasks : null)

// The limit value the save would persist: null when the toggle is off,
// the edited integer when on (validated before the request fires).
const nextLimit = () => (limitEnabled.value && Number.isInteger(limitValue.value) ? limitValue.value : null)

const limitHasChanges = computed(() => nextLimit() !== storedLimit())

// Effective full week the save would persist (CR-016-14): availability from
// the form, energy from the edited values with unset days defaulting to the
// member's stored budget (empty input = leave unchanged).
const buildWeeklyPayload = () => {
	const storedBudget = props.member.energyBudget ?? {}
	const defaultBudget = createDefaultEnergyBudget()
	const weekdayAvailability = {}
	const energyBudget = {}
	for (const day of weekDays) {
		weekdayAvailability[day.key] = !!editForm.value.availability[day.key]
		const energy = editForm.value.energyBudget[day.key]
		energyBudget[day.key] = Number.isInteger(energy)
			? energy
			: (storedBudget[day.key] ?? defaultBudget[day.key])
	}
	return { weekdayAvailability, energyBudget }
}

// Snapshot comparison against the edited member's stored week so Save is a
// no-op unless something actually changed. The child limit participates
// only for other members (D5: the self path cannot change it).
const editHasChanges = computed(() => {
	const storedAvailability = props.member.weekdayAvailability ?? {}
	const storedBudget = props.member.energyBudget ?? {}
	const defaultBudget = createDefaultEnergyBudget()
	if (!isSelf.value && limitHasChanges.value) {
		return true
	}
	return weekDays.some((day) => {
		if (!!editForm.value.availability[day.key] !== !!storedAvailability[day.key]) {
			return true
		}
		const formEnergy = editForm.value.energyBudget[day.key]
		const next = Number.isInteger(formEnergy)
			? formEnergy
			: (storedBudget[day.key] ?? defaultBudget[day.key])
		return next !== (storedBudget[day.key] ?? defaultBudget[day.key])
	})
})

const close = () => {
	emit('close')
}

const saveAvailability = async () => {
	// Guard: nothing changed — close without firing a redundant PUT.
	if (!editHasChanges.value) {
		close()
		return
	}

	// Validate every provided energy value up front so an out-of-range value
	// aborts with feedback instead of being silently dropped after a misleading
	// success toast. Empty (unset) values are left unchanged.
	const invalid = weekDays.filter((day) => {
		const energy = editForm.value.energyBudget[day.key]
		return energy !== undefined && energy !== ''
			&& (!Number.isInteger(energy) || energy < ENERGY_MIN || energy > ENERGY_MAX)
	})
	if (invalid.length > 0) {
		showError(t('chorestrike', 'Energy values must be between 1 and 20'))
		return
	}

	// Decision D5: validate the limit before firing — an out-of-range value
	// aborts instead of being silently dropped behind a success toast.
	const payload = buildWeeklyPayload()
	const updates = { ...payload }
	if (!isSelf.value && limitHasChanges.value) {
		if (limitEnabled.value) {
			if (!Number.isInteger(limitValue.value) || limitValue.value < LIMIT_MIN || limitValue.value > LIMIT_MAX) {
				showError(t('chorestrike', 'The daily task limit must be between 1 and 100'))
				return
			}
		}
		updates.maxDailyTasks = nextLimit()
	}

	saving.value = true
	try {
		if (isSelf.value) {
			await schedulingStore.saveWeeklyPreferences(payload)
		} else {
			await schedulingStore.updateMemberPreferences(props.member.userId, updates)
		}

		showSuccess(t('chorestrike', 'Availability settings saved successfully'))
		await schedulingStore.fetchMemberAvailability()
		close()
	} catch (error) {
		logger.error('Failed to save availability', error, { component: 'AvailabilityEditModal', action: 'saveAvailability', userId: props.member.userId })
		showError(t('chorestrike', 'Failed to save availability settings'))
	} finally {
		saving.value = false
	}
}

const applyTemplate = (templateType) => {
	const templates = {
		'weekend-warrior': {
			availability: { monday: false, tuesday: false, wednesday: false, thursday: false, friday: false, saturday: true, sunday: true },
			energyBudget: { monday: 3, tuesday: 3, wednesday: 3, thursday: 3, friday: 3, saturday: 20, sunday: 20 },
		},
		balanced: {
			availability: { monday: true, tuesday: true, wednesday: true, thursday: true, friday: true, saturday: true, sunday: true },
			energyBudget: { monday: 10, tuesday: 10, wednesday: 10, thursday: 10, friday: 10, saturday: 15, sunday: 15 },
		},
		minimal: {
			availability: { monday: true, tuesday: true, wednesday: true, thursday: true, friday: true, saturday: false, sunday: false },
			energyBudget: { monday: 5, tuesday: 5, wednesday: 5, thursday: 5, friday: 5, saturday: 1, sunday: 1 },
		},
	}

	const template = templates[templateType]
	if (template) {
		editForm.value.availability = { ...template.availability }
		editForm.value.energyBudget = { ...template.energyBudget }
	}
}
</script>

<style scoped>
.edit-form {
	display: flex;
	flex-direction: column;
	gap: 2rem;
}

.form-section h4 {
	font-size: 1.1rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 1rem;
}

.section-description {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
	margin-bottom: 1rem;
}

.availability-toggle-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 1rem;
}

.toggle-item {
	display: flex;
	align-items: center;
}

.toggle-label {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	cursor: pointer;
	padding: 0.5rem 0;
	border-radius: 6px;
}

.toggle-label:focus-within {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.toggle-slider {
	width: 44px;
	height: 24px;
	background: var(--color-border);
	border-radius: 12px;
	position: relative;
	transition: background-color 0.2s ease;
}

.toggle-slider::before {
	content: '';
	position: absolute;
	top: 2px;
	inset-inline-start: 2px;
	width: 20px;
	height: 20px;
	background: var(--color-main-background);
	border-radius: 50%;
	transition: transform 0.2s ease;
}

.toggle-input:checked + .toggle-slider {
	background: var(--color-primary);
}

.toggle-input:checked + .toggle-slider::before {
	transform: translateX(20px);
}

/* RTL: the knob sits at inset-inline-start; moving to the other end means
   translating in the negative X direction (TECH-LINT-004 verification). */
[dir='rtl'] .toggle-input:checked + .toggle-slider::before {
	transform: translateX(-20px);
}

.toggle-text {
	font-weight: 500;
	color: var(--color-main-text);
}

.energy-budget-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 1rem;
}

.budget-input-item label {
	display: block;
	font-size: 0.9rem;
	font-weight: 500;
	color: var(--color-main-text);
	margin-bottom: 0.5rem;
}

.input-group {
	display: flex;
	align-items: center;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	overflow: hidden;
}

.number-input {
	flex: 1;
	border: none;
	padding: 0.5rem;
	background: var(--color-main-background);
	color: var(--color-main-text);
	text-align: center;
	font-weight: 500;
}

.input-suffix {
	padding: 0.5rem;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
}

.limit-row {
	display: flex;
	align-items: center;
	gap: 1rem;
	flex-wrap: wrap;
}

.limit-input-group {
	width: 160px;
}

.template-buttons {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.template-button {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	cursor: pointer;
	transition: all 0.2s ease;
}

.template-button:hover {
	border-color: var(--color-primary-element);
	background: var(--color-background-dark);
}

.template-desc {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.modal-footer {
	display: flex;
	justify-content: flex-end;
	gap: 1rem;
}

.primary-button, .secondary-button {
	padding: 0.75rem 1.5rem;
	border-radius: 6px;
	font-weight: 500;
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.primary-button {
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
}

.primary-button:hover:not(:disabled) {
	background: var(--color-primary-hover);
}

.primary-button:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.secondary-button {
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
}

.secondary-button:hover {
	background: var(--color-background-dark);
}

@media (max-width: 768px) {
	.availability-toggle-grid {
		grid-template-columns: 1fr;
	}

	.energy-budget-grid {
		grid-template-columns: 1fr;
	}

	.template-button {
		flex-direction: column;
		align-items: flex-start;
		gap: 0.5rem;
	}
}
</style>
