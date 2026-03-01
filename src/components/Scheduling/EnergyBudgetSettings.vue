<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="energy-budget-settings" data-testid="energy-budget-settings">
		<div class="settings-header">
			<h2>{{ t('chorestrike', 'Energy Budget Settings') }}</h2>
			<p>{{ t('chorestrike', 'Configure daily energy budgets for optimal task distribution') }}</p>
		</div>

		<!-- Loading State -->
		<div v-if="loading" class="loading-state">
			<div class="loading-spinner"></div>
			<p>{{ t('chorestrike', 'Loading energy budget settings...') }}</p>
		</div>

		<!-- Current User Settings -->
		<div v-else-if="currentUser" class="current-user-section">
			<div class="section-header">
				<h3>{{ t('chorestrike', 'My Energy Budget') }}</h3>
				<div class="user-info">
					<span class="user-name">{{ currentUser.userName }}</span>
					<span class="total-energy">{{ totalWeeklyEnergy }} {{ t('chorestrike', 'total energy/week') }}</span>
				</div>
			</div>

			<!-- Energy Budget Grid -->
			<div class="budget-grid">
				<div
					v-for="day in weekDays"
					:key="day.key"
					class="budget-item">
					<div class="day-header">
						<span class="day-name">{{ day.name }}</span>
						<span class="day-short">{{ day.short }}</span>
					</div>
					<div class="budget-input-group">
						<div class="energy-slider-container">
							<input
								:id="`energy-${day.key}`"
								v-model.number="currentUserBudget[day.key]"
								type="range"
								min="1"
								max="20"
								class="energy-slider"
								:data-testid="`energy-slider-${day.key}`"
								@input="updateEnergyPreview(day.key)" />
							<div class="slider-marks">
								<span class="mark" v-for="mark in [0, 5, 10, 15, 20]" :key="mark">{{ mark }}</span>
							</div>
						</div>
						<div class="energy-value-display">
							<input
								:id="`energy-input-${day.key}`"
								v-model.number="currentUserBudget[day.key]"
								type="number"
								min="1"
								max="20"
								class="energy-number-input"
								:data-testid="`energy-input-${day.key}`" />
							<span class="energy-unit">{{ t('chorestrike', 'energy') }}</span>
						</div>
					</div>
					<div class="energy-indicator">
						<div
							class="energy-bar"
							:style="{ width: `${(currentUserBudget[day.key] / 20) * 100}%` }">
						</div>
					</div>
				</div>
			</div>

			<!-- Quick Actions -->
			<div class="quick-actions">
				<h4>{{ t('chorestrike', 'Quick Templates') }}</h4>
				<div class="template-grid">
					<button
						v-for="template in energyTemplates"
						:key="template.id"
						class="template-button"
						@click="applyEnergyTemplate(template)"
						:data-testid="`template-${template.id}`">
						<div class="template-icon">
							<Icon :name="template.icon" :size="20" />
						</div>
						<div class="template-content">
							<span class="template-name">{{ template.name }}</span>
							<span class="template-desc">{{ template.description }}</span>
						</div>
					</button>
				</div>
			</div>

			<!-- Budget Analysis -->
			<div class="budget-analysis">
				<h4>{{ t('chorestrike', 'Budget Analysis') }}</h4>
				<div class="analysis-cards">
					<div class="analysis-card">
						<div class="card-icon">
							<Icon name="Battery" :size="20" />
						</div>
						<div class="card-content">
							<span class="card-value">{{ averageDailyEnergy }}</span>
							<span class="card-label">{{ t('chorestrike', 'Avg Daily Energy') }}</span>
						</div>
					</div>
					<div class="analysis-card">
						<div class="card-icon">
							<Icon name="TrendingUp" :size="20" />
						</div>
						<div class="card-content">
							<span class="card-value">{{ peakDay }}</span>
							<span class="card-label">{{ t('chorestrike', 'Peak Day') }}</span>
						</div>
					</div>
					<div class="analysis-card">
						<div class="card-icon">
							<Icon name="Alert" :size="20" />
						</div>
						<div class="card-content">
							<span class="card-value">{{ lowEnergyDays }}</span>
							<span class="card-label">{{ t('chorestrike', 'Low Energy Days') }}</span>
						</div>
					</div>
				</div>
			</div>

			<!-- Save Button -->
			<div class="save-section">
				<button
					class="save-button"
					@click="saveEnergyBudget"
					:disabled="saving || !hasChanges"
					data-testid="save-energy-budget-button">
					<Icon v-if="saving" name="Loading" :size="16" class="spinning" />
					<Icon v-else name="Save" :size="16" />
					{{ saving ? t('chorestrike', 'Saving...') : t('chorestrike', 'Save Energy Budget') }}
				</button>
			</div>
		</div>

		<!-- Empty State -->
		<div v-else class="empty-state">
			<div class="empty-icon">
				<Icon name="Battery" :size="48" />
			</div>
			<h3>{{ t('chorestrike', 'No User Data Available') }}</h3>
			<p>{{ t('chorestrike', 'Please ensure you are properly logged in and have house access') }}</p>
		</div>

		<!-- Success/Error Notifications -->
		<div v-if="notification.show" :class="['notification', notification.type]">
			<div class="notification-icon">
				<Icon :name="notification.type === 'success' ? 'Check' : 'Alert'" :size="20" />
			</div>
			<div class="notification-content">
				<p>{{ notification.message }}</p>
			</div>
			<button class="notification-close" @click="hideNotification">
				<Icon name="Close" :size="16" />
			</button>
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import Icon from '../Common/Icon.vue'
import axios from '@nextcloud/axios'

type Weekday = 'monday' | 'tuesday' | 'wednesday' | 'thursday' | 'friday' | 'saturday' | 'sunday'
type EnergyBudget = Record<Weekday, number>

interface MemberAvailability {
	userId: number
	userName: string
	energyBudget: EnergyBudget
	weekdayAvailability: Record<Weekday, boolean>
}

interface EnergyTemplate {
	id: string
	name: string
	description: string
	icon: string
	budget: EnergyBudget
}

interface NotificationState {
	show: boolean
	type: 'success' | 'error'
	message: string
}

interface WeekDayLabel {
	key: Weekday
	name: string
	short: string
}

const defaultBudget = (): EnergyBudget => ({
	monday: 10,
	tuesday: 10,
	wednesday: 10,
	thursday: 10,
	friday: 10,
	saturday: 15,
	sunday: 15,
})

// Props
const props = defineProps<{
	houseId: number
}>()

// Emits
const emit = defineEmits<{
	budgetUpdated: [budget: EnergyBudget]
}>()

// Reactive state
const loading = ref(false)
const saving = ref(false)
const currentUser = ref<MemberAvailability | null>(null)
const currentUserBudget = ref<EnergyBudget>(defaultBudget())
const originalBudget = ref<EnergyBudget>(defaultBudget())
const notification = ref<NotificationState>({
	show: false,
	type: 'success',
	message: ''
})

// Constants
const weekDays: WeekDayLabel[] = [
	{ key: 'monday', name: t('chorestrike', 'Monday'), short: t('chorestrike', 'Mon') },
	{ key: 'tuesday', name: t('chorestrike', 'Tuesday'), short: t('chorestrike', 'Tue') },
	{ key: 'wednesday', name: t('chorestrike', 'Wednesday'), short: t('chorestrike', 'Wed') },
	{ key: 'thursday', name: t('chorestrike', 'Thursday'), short: t('chorestrike', 'Thu') },
	{ key: 'friday', name: t('chorestrike', 'Friday'), short: t('chorestrike', 'Fri') },
	{ key: 'saturday', name: t('chorestrike', 'Saturday'), short: t('chorestrike', 'Sat') },
	{ key: 'sunday', name: t('chorestrike', 'Sunday'), short: t('chorestrike', 'Sun') },
]

const energyTemplates: EnergyTemplate[] = [
	{
		id: 'light',
		name: t('chorestrike', 'Light Schedule'),
		description: t('chorestrike', '5-8 energy per day'),
		icon: 'Battery',
		budget: { monday: 6, tuesday: 6, wednesday: 6, thursday: 6, friday: 6, saturday: 8, sunday: 8 }
	},
	{
		id: 'moderate',
		name: t('chorestrike', 'Moderate Schedule'),
		description: t('chorestrike', '8-12 energy per day'),
		icon: 'BatteryHalf',
		budget: { monday: 10, tuesday: 10, wednesday: 10, thursday: 10, friday: 10, saturday: 12, sunday: 12 }
	},
	{
		id: 'heavy',
		name: t('chorestrike', 'Heavy Schedule'),
		description: t('chorestrike', '15-20 energy per day'),
		icon: 'BatteryFull',
		budget: { monday: 15, tuesday: 15, wednesday: 15, thursday: 15, friday: 15, saturday: 20, sunday: 20 }
	},
	{
		id: 'weekend-focused',
		name: t('chorestrike', 'Weekend Warrior'),
		description: t('chorestrike', 'Low weekdays, high weekends'),
		icon: 'Calendar',
		budget: { monday: 3, tuesday: 3, wednesday: 3, thursday: 3, friday: 3, saturday: 20, sunday: 20 }
	}
]

// Computed properties
const totalWeeklyEnergy = computed(() => {
	return Object.values(currentUserBudget.value).reduce((sum: number, energy: number) => sum + energy, 0)
})

const averageDailyEnergy = computed(() => {
	const total = totalWeeklyEnergy.value
	return total > 0 ? Math.round(total / 7) : 0
})

const peakDay = computed(() => {
	if (!currentUser.value) return t('chorestrike', 'None')
	let maxEnergy = 0
	let peakDayKey: Weekday = 'monday'
	
	Object.entries(currentUserBudget.value).forEach(([day, energy]) => {
		if (energy > maxEnergy) {
			maxEnergy = energy
			peakDayKey = day as Weekday
		}
	})
	
	const day = weekDays.find(d => d.key === peakDayKey)
	return day ? day.short : t('chorestrike', 'None')
})

const lowEnergyDays = computed(() => {
	if (!currentUser.value) return 0
	return Object.values(currentUserBudget.value).filter((energy: number) => energy <= 5).length
})

const hasChanges = computed(() => {
	return JSON.stringify(currentUserBudget.value) !== JSON.stringify(originalBudget.value)
})

// Methods
const loadCurrentUserBudget = async () => {
	if (!props.houseId) return
	
	loading.value = true
	try {
		const response = await axios.get(`/api/v1/smart-schedule/availability`)
		
		if (response.data.success) {
			const members = response.data.data as MemberAvailability[]
			const currentUserId = null
			
			const user = (currentUserId === null)
				? members[0]
				: members.find((member: MemberAvailability) => member.userId === currentUserId)
			if (user) {
				currentUser.value = user
				currentUserBudget.value = { ...defaultBudget(), ...user.energyBudget }
				originalBudget.value = { ...defaultBudget(), ...user.energyBudget }
			}
		} else {
			throw new Error(response.data.error || 'Failed to load energy budget')
		}
	} catch (error) {
		console.error('Failed to load energy budget:', error)
		showNotification('error', t('chorestrike', 'Failed to load energy budget settings'))
	} finally {
		loading.value = false
	}
}

const applyEnergyTemplate = (template: EnergyTemplate) => {
	currentUserBudget.value = { ...template.budget }
	showNotification('success', t('chorestrike', 'Template "{name}" applied', { name: template.name }))
}

const updateEnergyPreview = (day: Weekday) => {
	// This could trigger real-time preview updates
	const energy = currentUserBudget.value[day]
	if (energy < 1) currentUserBudget.value[day] = 1
	if (energy > 20) currentUserBudget.value[day] = 20
}

const saveEnergyBudget = async () => {
	if (!currentUser.value) return
	
	saving.value = true
	try {
		await axios.put(`/api/v1/smart-schedule/energy-budget`, currentUserBudget.value)
		
		originalBudget.value = { ...currentUserBudget.value }
		emit('budgetUpdated', currentUserBudget.value)
		showNotification('success', t('chorestrike', 'Energy budget saved successfully!'))
	} catch (error) {
		console.error('Failed to save energy budget:', error)
		showNotification('error', t('chorestrike', 'Failed to save energy budget'))
	} finally {
		saving.value = false
	}
}

const showNotification = (type: 'success' | 'error', message: string) => {
	notification.value = {
		show: true,
		type,
		message
	}
	
	setTimeout(() => {
		hideNotification()
	}, 5000)
}

const hideNotification = () => {
	notification.value.show = false
}

// Lifecycle
onMounted(() => {
	if (props.houseId) {
		loadCurrentUserBudget()
	}
})

watch(() => props.houseId, (newHouseId) => {
	if (newHouseId) {
		loadCurrentUserBudget()
	}
})
</script>

<style scoped>
.energy-budget-settings {
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

.current-user-section {
	display: flex;
	flex-direction: column;
	gap: 2rem;
}

.section-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding-bottom: 1rem;
	border-bottom: 1px solid var(--color-border);
}

.section-header h3 {
	font-size: 1.2rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0;
}

.user-info {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 0.25rem;
}

.user-name {
	font-weight: 500;
	color: var(--color-main-text);
}

.total-energy {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.budget-grid {
	display: grid;
	gap: 1.5rem;
}

.budget-item {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 1.5rem;
	border: 1px solid var(--color-border);
}

.day-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 1rem;
}

.day-name {
	font-weight: 600;
	color: var(--color-main-text);
}

.day-short {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.budget-input-group {
	display: flex;
	align-items: center;
	gap: 1rem;
	margin-bottom: 1rem;
}

.energy-slider-container {
	flex: 1;
	position: relative;
}

.energy-slider {
	width: 100%;
	height: 6px;
	border-radius: 3px;
	background: var(--color-border);
	outline: none;
	-webkit-appearance: none;
}

.energy-slider::-webkit-slider-thumb {
	-webkit-appearance: none;
	appearance: none;
	width: 20px;
	height: 20px;
	border-radius: 50%;
	background: var(--color-primary);
	cursor: pointer;
	box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.energy-slider::-moz-range-thumb {
	width: 20px;
	height: 20px;
	border-radius: 50%;
	background: var(--color-primary);
	cursor: pointer;
	box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
	border: none;
}

.slider-marks {
	display: flex;
	justify-content: space-between;
	margin-top: 0.5rem;
	padding: 0 2px;
}

.mark {
	font-size: 0.7rem;
	color: var(--color-text-maxcontrast);
}

.energy-value-display {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	min-width: 120px;
}

.energy-number-input {
	width: 60px;
	padding: 0.5rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	text-align: center;
	font-weight: 500;
}

.energy-unit {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.energy-indicator {
	height: 4px;
	background: var(--color-border);
	border-radius: 2px;
	overflow: hidden;
}

.energy-bar {
	height: 100%;
	background: var(--color-primary);
	transition: width 0.3s ease;
}

.quick-actions {
	margin-bottom: 2rem;
}

.quick-actions h4 {
	font-size: 1.1rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 1rem;
}

.template-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
	gap: 1rem;
}

.template-button {
	display: flex;
	align-items: center;
	gap: 1rem;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-main-background);
	cursor: pointer;
	transition: all 0.2s ease;
}

.template-button:hover {
	border-color: var(--color-primary);
	background: var(--color-background-dark);
}

.template-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 40px;
	height: 40px;
	background: var(--color-primary);
	color: var(--color-primary-text);
	border-radius: 8px;
}

.template-content {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.template-name {
	font-weight: 500;
	color: var(--color-main-text);
}

.template-desc {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.budget-analysis {
	margin-bottom: 2rem;
}

.budget-analysis h4 {
	font-size: 1.1rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 1rem;
}

.analysis-cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 1rem;
}

.analysis-card {
	display: flex;
	align-items: center;
	gap: 1rem;
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 1rem;
}

.card-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 40px;
	height: 40px;
	background: var(--color-primary);
	color: var(--color-primary-text);
	border-radius: 8px;
}

.card-content {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.card-value {
	font-size: 1.2rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.card-label {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.save-section {
	display: flex;
	justify-content: center;
}

.save-button {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
	border-radius: 8px;
	padding: 1rem 2rem;
	font-weight: 500;
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.save-button:hover:not(:disabled) {
	background: var(--color-primary-hover);
}

.save-button:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.spinning {
	animation: spin 1s linear infinite;
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

.notification {
	position: fixed;
	top: 20px;
	right: 20px;
	display: flex;
	align-items: center;
	gap: 1rem;
	padding: 1rem 1.5rem;
	border-radius: 8px;
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
	z-index: 1000;
	max-width: 400px;
}

.notification.success {
	background: rgba(76, 175, 80, 0.9);
	color: white;
}

.notification.error {
	background: rgba(244, 67, 54, 0.9);
	color: white;
}

.notification-icon {
	flex-shrink: 0;
}

.notification-content {
	flex: 1;
}

.notification-content p {
	margin: 0;
}

.notification-close {
	background: none;
	border: none;
	color: inherit;
	cursor: pointer;
	padding: 0;
}

@media (max-width: 768px) {
	.section-header {
		flex-direction: column;
		align-items: flex-start;
		gap: 0.5rem;
	}
	
	.budget-input-group {
		flex-direction: column;
		gap: 0.75rem;
	}
	
	.energy-value-display {
		width: 100%;
		justify-content: space-between;
	}
	
	.template-grid {
		grid-template-columns: 1fr;
	}
	
	.analysis-cards {
		grid-template-columns: 1fr;
	}
	
	.notification {
		right: 10px;
		left: 10px;
		max-width: none;
	}
}
</style>
