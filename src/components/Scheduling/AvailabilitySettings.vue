<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="availability-settings" data-testid="availability-settings">
		<div class="settings-header">
			<h2>{{ t('chorestrike', 'Member Availability') }}</h2>
			<p>{{ t('chorestrike', 'Configure when each member is available for tasks') }}</p>
		</div>

		<div v-if="loading" class="loading-state">
			<div class="loading-spinner" />
			<p>{{ t('chorestrike', 'Loading member availability...') }}</p>
		</div>

		<div v-else-if="members.length > 0" class="members-list">
			<div
				v-for="member in members"
				:key="member.userId"
				class="member-card">
				<div class="member-header">
					<div class="member-info">
						<h3>{{ member.userName || member.userId }}</h3>
						<span class="member-status">
							{{ getAvailableDaysCount(member) }}/7 {{ t('chorestrike', 'days available') }}
						</span>
					</div>
					<div class="member-actions">
						<button
							v-if="member.isCurrentUser"
							class="edit-button"
							data-testid="edit-availability-button"
							@click="editMemberAvailability(member)">
							{{ t('chorestrike', 'Edit') }}
						</button>
					</div>
				</div>

				<div class="availability-grid" data-testid="availability-grid">
					<div
						v-for="day in weekDays"
						:key="day.key"
						:class="['day-item', { available: isMemberAvailable(member, day.key), unavailable: !isMemberAvailable(member, day.key) }]"
						data-testid="availability-slot">
						<div class="day-name">
							{{ day.name }}
						</div>
						<div class="day-status">
							<span>{{ isMemberAvailable(member, day.key) ? t('chorestrike', 'Available') : t('chorestrike', 'Unavailable') }}</span>
						</div>
					</div>
				</div>

				<div class="energy-budget-display">
					<h4>{{ t('chorestrike', 'Daily Energy Budget') }}</h4>
					<div class="budget-grid">
						<div
							v-for="day in weekDays"
							:key="`energy-${day.key}`"
							class="budget-item">
							<span class="day-name">{{ day.short }}</span>
							<span class="energy-value">{{ getMemberEnergy(member, day.key) }}</span>
						</div>
					</div>
				</div>
			</div>
		</div>

		<div v-else class="empty-state">
			<h3>{{ t('chorestrike', 'No Members Found') }}</h3>
			<p>{{ t('chorestrike', 'Add members to your house to configure availability') }}</p>
		</div>

		<Modal
			v-if="showEditModal"
			:title="t('chorestrike', 'Edit Availability')"
			data-testid="edit-availability-modal"
			@close="closeEditModal">
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
									class="toggle-input"
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
									min="1"
									max="20"
									class="number-input"
									:data-testid="`budget-${day.key}-input`">
								<span class="input-suffix">{{ t('chorestrike', 'energy') }}</span>
							</div>
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
						@click="closeEditModal">
						{{ t('chorestrike', 'Cancel') }}
					</button>
					<button
						class="primary-button"
						:disabled="saving"
						data-testid="save-availability-button"
						@click="saveAvailability">
						{{ saving ? t('chorestrike', 'Saving...') : t('chorestrike', 'Save Changes') }}
					</button>
				</div>
			</template>
		</Modal>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { useSchedulingStore } from '../../store/scheduling.js'
import { resolveCurrentUser } from '../../utils/currentUser.js'
import { logger } from '../../utils/logger.js'
import Modal from '../Common/Modal.vue'

const schedulingStore = useSchedulingStore()

const saving = ref(false)
const showEditModal = ref(false)
const editingMember = ref(null)
const currentUser = ref(null)
const editForm = ref({
	availability: {},
	energyBudget: {},
})

const weekDays = [
	{ key: 'monday', name: t('chorestrike', 'Monday'), short: t('chorestrike', 'Mon') },
	{ key: 'tuesday', name: t('chorestrike', 'Tuesday'), short: t('chorestrike', 'Tue') },
	{ key: 'wednesday', name: t('chorestrike', 'Wednesday'), short: t('chorestrike', 'Wed') },
	{ key: 'thursday', name: t('chorestrike', 'Thursday'), short: t('chorestrike', 'Thu') },
	{ key: 'friday', name: t('chorestrike', 'Friday'), short: t('chorestrike', 'Fri') },
	{ key: 'saturday', name: t('chorestrike', 'Saturday'), short: t('chorestrike', 'Sat') },
	{ key: 'sunday', name: t('chorestrike', 'Sunday'), short: t('chorestrike', 'Sun') },
]

// Mirrors SchedulingService::getDefaultEnergyBudget — used when a member's
// stored budget lacks a day (partial section from the owner day-granular path).
const defaultEnergyFallback = {
	monday: 10,
	tuesday: 10,
	wednesday: 10,
	thursday: 10,
	friday: 10,
	saturday: 15,
	sunday: 15,
}

const loading = computed(() => schedulingStore.loading)

const members = computed(() => {
	const currentUserId = currentUser.value?.userId
	return schedulingStore.memberAvailability.map(m => ({
		...m,
		isCurrentUser: m.userId === currentUserId,
	}))
})

const isMemberAvailable = (member, dayKey) => {
	return member.weekdayAvailability?.[dayKey] !== false
}

const getMemberEnergy = (member, dayKey) => {
	return member.energyBudget?.[dayKey] ?? 10
}

const getAvailableDaysCount = (member) => {
	if (!member.weekdayAvailability) return 7
	return Object.values(member.weekdayAvailability).filter(Boolean).length
}

const editMemberAvailability = (member) => {
	editingMember.value = member
	editForm.value = {
		availability: { ...member.weekdayAvailability },
		energyBudget: { ...member.energyBudget },
	}
	showEditModal.value = true
}

const closeEditModal = () => {
	showEditModal.value = false
	editingMember.value = null
	editForm.value = { availability: {}, energyBudget: {} }
}

const saveAvailability = async () => {
	if (!editingMember.value) return

	// Validate every provided energy value up front so an out-of-range value
	// aborts with feedback instead of being silently dropped after a misleading
	// success toast. Empty (unset) values are left unchanged.
	const invalid = weekDays.filter((day) => {
		const energy = editForm.value.energyBudget[day.key]
		return energy !== undefined && energy !== ''
			&& (!Number.isInteger(energy) || energy < 1 || energy > 20)
	})
	if (invalid.length > 0) {
		showError(t('chorestrike', 'Energy values must be between 1 and 20'))
		return
	}

	saving.value = true
	try {
		// Build full-week payloads: availability from the form, energy from
		// the edited values with unset days defaulting to the member's
		// stored budget (empty input = leave unchanged).
		const storedBudget = editingMember.value.energyBudget ?? {}
		const weekdayAvailability = {}
		const energyBudget = {}
		for (const day of weekDays) {
			weekdayAvailability[day.key] = !!editForm.value.availability[day.key]
			const energy = editForm.value.energyBudget[day.key]
			energyBudget[day.key] = Number.isInteger(energy)
				? energy
				: (storedBudget[day.key] ?? defaultEnergyFallback[day.key])
		}

		await schedulingStore.saveWeeklyPreferences({ weekdayAvailability, energyBudget })

		showSuccess(t('chorestrike', 'Availability settings saved successfully'))
		await schedulingStore.fetchMemberAvailability()
		closeEditModal()
	} catch (error) {
		logger.error('Failed to save availability', error, { component: 'AvailabilitySettings', action: 'saveAvailability' })
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

onMounted(() => {
	try {
		currentUser.value = resolveCurrentUser()
	} catch (error) {
		logger.warn('Could not resolve current user', { component: 'AvailabilitySettings', error })
	}
})
</script>

<style scoped>
.availability-settings {
	background: var(--color-main-background);
	border-radius: 12px;
	padding: 1.5rem;
}

.settings-header {
	margin-bottom: 2rem;
}

.settings-header h2 {
	font-size: 1.5rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.5rem;
}

.settings-header p {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.loading-state {
	text-align: center;
	padding: 3rem 0;
}

.loading-spinner {
	width: 40px;
	height: 40px;
	border: 3px solid var(--color-border);
	border-top: 3px solid var(--color-primary);
	border-radius: 50%;
	animation: spin 1s linear infinite;
	margin: 0 auto 1rem;
}

@keyframes spin {
	0% { transform: rotate(0deg); }
	100% { transform: rotate(360deg); }
}

.members-list {
	display: flex;
	flex-direction: column;
	gap: 1.5rem;
}

.member-card {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 1.5rem;
	background: var(--color-background-dark);
}

.member-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 1.5rem;
}

.member-info h3 {
	font-size: 1.2rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.25rem;
}

.member-status {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.edit-button {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
	border-radius: 6px;
	padding: 0.5rem 1rem;
	font-size: 0.9rem;
	font-weight: 500;
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.edit-button:hover {
	background: var(--color-primary-hover);
}

.availability-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
	gap: 0.75rem;
	margin-bottom: 1.5rem;
}

.day-item {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 0.75rem;
	border-radius: 6px;
	border: 1px solid var(--color-border);
}

.day-item.available {
	background: rgba(76, 175, 80, 0.1);
	border-color: #4caf50;
}

.day-item.unavailable {
	background: rgba(244, 67, 54, 0.1);
	border-color: #f44336;
}

.day-name {
	font-size: 0.9rem;
	font-weight: 500;
	color: var(--color-main-text);
	margin-bottom: 0.25rem;
}

.day-status {
	display: flex;
	align-items: center;
	gap: 0.25rem;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.energy-budget-display h4 {
	font-size: 1rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.75rem;
}

.budget-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
	gap: 0.5rem;
}

.budget-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.5rem;
	background: var(--color-main-background);
	border-radius: 4px;
}

.budget-item .day-name {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.energy-value {
	font-weight: 600;
	color: var(--color-main-text);
}

.empty-state {
	text-align: center;
	padding: 3rem 0;
}

.empty-state h3 {
	font-size: 1.2rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.5rem;
}

.empty-state p {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

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
}

.toggle-input {
	display: none;
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
	left: 2px;
	width: 20px;
	height: 20px;
	background: white;
	border-radius: 50%;
	transition: transform 0.2s ease;
}

.toggle-input:checked + .toggle-slider {
	background: var(--color-primary);
}

.toggle-input:checked + .toggle-slider::before {
	transform: translateX(20px);
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
	border-color: var(--color-primary);
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
	.availability-grid {
		grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
	}

	.budget-grid {
		grid-template-columns: repeat(auto-fit, minmax(60px, 1fr));
	}

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
