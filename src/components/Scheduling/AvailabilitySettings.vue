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

		<!-- Loading State -->
		<div v-if="loading" class="loading-state">
			<div class="loading-spinner" />
			<p>{{ t('chorestrike', 'Loading member availability...') }}</p>
		</div>

		<!-- Member List -->
		<div v-else-if="members.length > 0" class="members-list">
			<div
				v-for="member in members"
				:key="member.userId"
				class="member-card">
				<div class="member-header">
					<div class="member-info">
						<h3>{{ member.userName }}</h3>
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
							<Icon name="Edit" :size="16" />
							{{ t('chorestrike', 'Edit') }}
						</button>
					</div>
				</div>

				<!-- Availability Grid -->
				<div class="availability-grid">
					<div
						v-for="(available, day) in member.weekdayAvailability"
						:key="day"
						:class="['day-item', { available: available, unavailable: !available }]">
						<div class="day-name">
							{{ formatDayName(day) }}
						</div>
						<div class="day-status">
							<Icon :name="available ? 'Check' : 'Close'" :size="16" />
							<span>{{ available ? t('chorestrike', 'Available') : t('chorestrike', 'Unavailable') }}</span>
						</div>
					</div>
				</div>

				<!-- Energy Budget Display -->
				<div class="energy-budget-display">
					<h4>{{ t('chorestrike', 'Daily Energy Budget') }}</h4>
					<div class="budget-grid">
						<div
							v-for="(energy, day) in member.energyBudget"
							:key="`energy-${day}`"
							class="budget-item">
							<span class="day-name">{{ formatDayName(day) }}</span>
							<span class="energy-value">{{ energy }}</span>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Empty State -->
		<div v-else class="empty-state">
			<div class="empty-icon">
				<Icon name="Users" :size="48" />
			</div>
			<h3>{{ t('chorestrike', 'No Members Found') }}</h3>
			<p>{{ t('chorestrike', 'Add members to your house to configure availability') }}</p>
		</div>

		<!-- Edit Availability Modal -->
		<Modal
			v-if="showEditModal"
			:title="t('chorestrike', 'Edit Availability for {userName}', { userName: editingMember?.userName })"
			data-testid="edit-availability-modal"
			@close="closeEditModal">
			<div class="edit-form">
				<!-- Availability Settings -->
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

				<!-- Energy Budget Settings -->
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

				<!-- Quick Templates -->
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
						<Icon v-if="saving"
							name="Loading"
							:size="16"
							class="spinning" />
						{{ saving ? t('chorestrike', 'Saving...') : t('chorestrike', 'Save Changes') }}
					</button>
				</div>
			</template>
		</Modal>
	</div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import Icon from '../Common/Icon.vue'
import Modal from '../Common/Modal.vue'
import axios from '@nextcloud/axios'

// Props
const props = defineProps<{
	houseId: number
}>()

// Emits
const emit = defineEmits<{
	'availability-updated': [availability: any]
}>()

// Reactive state
const loading = ref<boolean>(false)
const saving = ref<boolean>(false)
const members = ref<any[]>([])
const showEditModal = ref<boolean>(false)
const editingMember = ref<any>(null)
const editForm = ref<{ availability: Record<string, boolean>; energyBudget: Record<string, number> }>({
	availability: {},
	energyBudget: {},
})

// Constants
const weekDays = [
	{ key: 'monday', name: t('chorestrike', 'Monday') },
	{ key: 'tuesday', name: t('chorestrike', 'Tuesday') },
	{ key: 'wednesday', name: t('chorestrike', 'Wednesday') },
	{ key: 'thursday', name: t('chorestrike', 'Thursday') },
	{ key: 'friday', name: t('chorestrike', 'Friday') },
	{ key: 'saturday', name: t('chorestrike', 'Saturday') },
	{ key: 'sunday', name: t('chorestrike', 'Sunday') },
]

// Methods
const loadMemberAvailability = async () => {
	if (!props.houseId) return

	loading.value = true
	try {
		const response = await axios.get('/api/v1/smart-schedule/availability')

		if (response.data.success) {
			members.value = response.data.data.map(member => ({
				...member,
				isCurrentUser: member.userId === currentUserId.value,
			}))
		} else {
			throw new Error(response.data.error || 'Failed to load availability')
		}
	} catch (error) {
		console.error('Failed to load member availability:', error)
	} finally {
		loading.value = false
	}
}

const editMemberAvailability = (member: any) => {
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

	saving.value = true
	try {
		// Update availability
		await axios.put('/api/v1/smart-schedule/availability', editForm.value.availability)

		// Update energy budget
		await axios.put('/api/v1/smart-schedule/energy-budget', editForm.value.energyBudget)

		// Refresh member list
		await loadMemberAvailability()

		emit('availability-updated', editForm.value)
		closeEditModal()
	} catch (error) {
		console.error('Failed to save availability:', error)
	} finally {
		saving.value = false
	}
}

const applyTemplate = (templateType: string) => {
	const templates = {
		'weekend-warrior': {
			availability: {
				monday: false,
				tuesday: false,
				wednesday: false,
				thursday: false,
				friday: false,
				saturday: true,
				sunday: true,
			},
			energyBudget: {
				monday: 3,
				tuesday: 3,
				wednesday: 3,
				thursday: 3,
				friday: 3,
				saturday: 20,
				sunday: 20,
			},
		},
		balanced: {
			availability: {
				monday: true,
				tuesday: true,
				wednesday: true,
				thursday: true,
				friday: true,
				saturday: true,
				sunday: true,
			},
			energyBudget: {
				monday: 10,
				tuesday: 10,
				wednesday: 10,
				thursday: 10,
				friday: 10,
				saturday: 15,
				sunday: 15,
			},
		},
		minimal: {
			availability: {
				monday: true,
				tuesday: true,
				wednesday: true,
				thursday: true,
				friday: true,
				saturday: false,
				sunday: false,
			},
			energyBudget: {
				monday: 5,
				tuesday: 5,
				wednesday: 5,
				thursday: 5,
				friday: 5,
				saturday: 0,
				sunday: 0,
			},
		},
	}

	const template = templates[templateType]
	if (template) {
		editForm.value.availability = { ...template.availability }
		editForm.value.energyBudget = { ...template.energyBudget }
	}
}

const getAvailableDaysCount = (member: any): number => {
	return Object.values(member.weekdayAvailability).filter(Boolean).length
}

const formatDayName = (dayKey: string): string => {
	const day = weekDays.find(d => d.key === dayKey)
	return day ? day.name : dayKey
}

// Computed
const currentUserId = computed<number>(() => {
	// This would come from user store or authentication context
	return 1 // Placeholder
})

// Lifecycle
onMounted(() => {
	if (props.houseId) {
		loadMemberAvailability()
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

.empty-icon {
	color: var(--color-text-maxcontrast);
	margin-bottom: 1rem;
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

/* Modal Styles */
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

.spinning {
	animation: spin 1s linear infinite;
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
