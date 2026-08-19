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

		<div v-if="loading" class="loading-state">
			<div class="loading-spinner" />
			<p>{{ t('chorestrike', 'Loading energy budget settings...') }}</p>
		</div>

		<div v-else class="current-user-section">
			<div class="section-header">
				<h3>{{ t('chorestrike', 'My Energy Budget') }}</h3>
				<div class="user-info">
					<span class="total-energy">{{ totalWeeklyEnergy }} {{ t('chorestrike', 'total energy/week') }}</span>
				</div>
			</div>

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
								v-model.number="localBudget[day.key]"
								type="range"
								min="1"
								max="20"
								class="energy-slider"
								:disabled="saving"
								:data-testid="`energy-slider-${day.key}`">
							<div class="slider-marks">
								<span v-for="mark in [1, 5, 10, 15, 20]" :key="mark" class="mark">{{ mark }}</span>
							</div>
						</div>
						<div class="energy-value-display">
							<input
								:id="`energy-input-${day.key}`"
								v-model.number="localBudget[day.key]"
								type="number"
								min="1"
								max="20"
								class="energy-number-input"
								:disabled="saving"
								:data-testid="`energy-input-${day.key}`">
							<span class="energy-unit">{{ t('chorestrike', 'energy') }}</span>
						</div>
					</div>
					<div class="energy-indicator">
						<div
							class="energy-bar"
							:style="{ width: `${(localBudget[day.key] / 20) * 100}%` }" />
					</div>
				</div>
			</div>

			<div class="quick-actions">
				<h4>{{ t('chorestrike', 'Quick Templates') }}</h4>
				<div class="template-grid">
					<button
						v-for="template in energyTemplates"
						:key="template.id"
						class="template-button"
						:disabled="saving"
						:data-testid="`template-${template.id}`"
						@click="applyEnergyTemplate(template)">
						<div class="template-content">
							<span class="template-name">{{ template.name }}</span>
							<span class="template-desc">{{ template.description }}</span>
						</div>
					</button>
				</div>
			</div>

			<div class="budget-analysis">
				<h4>{{ t('chorestrike', 'Budget Analysis') }}</h4>
				<div class="analysis-cards">
					<div class="analysis-card">
						<div class="card-content">
							<span class="card-value">{{ averageDailyEnergy }}</span>
							<span class="card-label">{{ t('chorestrike', 'Avg Daily Energy') }}</span>
						</div>
					</div>
					<div class="analysis-card">
						<div class="card-content">
							<span class="card-value">{{ peakDay }}</span>
							<span class="card-label">{{ t('chorestrike', 'Peak Day') }}</span>
						</div>
					</div>
					<div class="analysis-card">
						<div class="card-content">
							<span class="card-value">{{ lowEnergyDays }}</span>
							<span class="card-label">{{ t('chorestrike', 'Low Energy Days') }}</span>
						</div>
					</div>
				</div>
			</div>

			<div class="save-section">
				<button
					class="save-button"
					:disabled="saving || !hasChanges"
					data-testid="save-energy-budget-button"
					@click="saveEnergyBudget">
					{{ saving ? t('chorestrike', 'Saving...') : t('chorestrike', 'Save Energy Budget') }}
				</button>
			</div>
		</div>
	</div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { useSchedulingStore } from '../../store/scheduling.js'
import { logger } from '../../utils/logger.js'

const schedulingStore = useSchedulingStore()

const saving = ref(false)
const localBudget = ref(defaultBudget())

function defaultBudget() {
	return {
		monday: 10,
		tuesday: 10,
		wednesday: 10,
		thursday: 10,
		friday: 10,
		saturday: 15,
		sunday: 15,
	}
}

const weekDays = [
	{ key: 'monday', name: t('chorestrike', 'Monday'), short: t('chorestrike', 'Mon') },
	{ key: 'tuesday', name: t('chorestrike', 'Tuesday'), short: t('chorestrike', 'Tue') },
	{ key: 'wednesday', name: t('chorestrike', 'Wednesday'), short: t('chorestrike', 'Wed') },
	{ key: 'thursday', name: t('chorestrike', 'Thursday'), short: t('chorestrike', 'Thu') },
	{ key: 'friday', name: t('chorestrike', 'Friday'), short: t('chorestrike', 'Fri') },
	{ key: 'saturday', name: t('chorestrike', 'Saturday'), short: t('chorestrike', 'Sat') },
	{ key: 'sunday', name: t('chorestrike', 'Sunday'), short: t('chorestrike', 'Sun') },
]

const energyTemplates = [
	{
		id: 'light',
		name: t('chorestrike', 'Light Schedule'),
		description: t('chorestrike', '5-8 energy per day'),
		budget: { monday: 6, tuesday: 6, wednesday: 6, thursday: 6, friday: 6, saturday: 8, sunday: 8 },
	},
	{
		id: 'moderate',
		name: t('chorestrike', 'Moderate Schedule'),
		description: t('chorestrike', '8-12 energy per day'),
		budget: { monday: 10, tuesday: 10, wednesday: 10, thursday: 10, friday: 10, saturday: 12, sunday: 12 },
	},
	{
		id: 'heavy',
		name: t('chorestrike', 'Heavy Schedule'),
		description: t('chorestrike', '15-20 energy per day'),
		budget: { monday: 15, tuesday: 15, wednesday: 15, thursday: 15, friday: 15, saturday: 20, sunday: 20 },
	},
	{
		id: 'weekend-focused',
		name: t('chorestrike', 'Weekend Warrior'),
		description: t('chorestrike', 'Low weekdays, high weekends'),
		budget: { monday: 3, tuesday: 3, wednesday: 3, thursday: 3, friday: 3, saturday: 20, sunday: 20 },
	},
]

const loading = computed(() => schedulingStore.loading)

const totalWeeklyEnergy = computed(() => {
	return Object.values(localBudget.value).reduce((sum, energy) => sum + energy, 0)
})

const averageDailyEnergy = computed(() => {
	const total = totalWeeklyEnergy.value
	return total > 0 ? Math.round(total / 7) : 0
})

const peakDay = computed(() => {
	let maxEnergy = 0
	let peakDayKey = 'monday'
	Object.entries(localBudget.value).forEach(([day, energy]) => {
		if (energy > maxEnergy) {
			maxEnergy = energy
			peakDayKey = day
		}
	})
	const day = weekDays.find(d => d.key === peakDayKey)
	return day ? day.short : t('chorestrike', 'None')
})

const lowEnergyDays = computed(() => {
	return Object.values(localBudget.value).filter(energy => energy <= 5).length
})

const hasChanges = computed(() => {
	return JSON.stringify(localBudget.value) !== JSON.stringify(schedulingStore.energyBudget)
})

const syncFromStore = () => {
	localBudget.value = { ...defaultBudget(), ...schedulingStore.energyBudget }
}

const applyEnergyTemplate = (template) => {
	localBudget.value = { ...template.budget }
}

const saveEnergyBudget = async () => {
	// Validate every value up front so a partial set is never saved silently.
	const invalid = weekDays.filter((day) => {
		const energy = localBudget.value[day.key]
		return !Number.isInteger(energy) || energy < 1 || energy > 20
	})
	if (invalid.length > 0) {
		showError(t('chorestrike', 'Energy values must be between 1 and 20'))
		return
	}

	saving.value = true
	try {
		await schedulingStore.saveWeeklyPreferences({ energyBudget: { ...localBudget.value } })
		showSuccess(t('chorestrike', 'Energy budget saved successfully!'))
	} catch (error) {
		logger.error('Failed to save energy budget', error, { component: 'EnergyBudgetSettings', action: 'saveEnergyBudget' })
		showError(t('chorestrike', 'Failed to save energy budget'))
	} finally {
		saving.value = false
	}
}

onMounted(() => {
	syncFromStore()
})

watch(() => schedulingStore.energyBudget, () => {
	syncFromStore()
}, { deep: true })
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
}
</style>
