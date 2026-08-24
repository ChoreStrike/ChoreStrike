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

		<div class="current-user-section">
			<div class="section-header">
				<h3>{{ t('chorestrike', 'My Energy Budget') }}</h3>
				<div class="user-info">
					<span class="total-energy">{{ totalWeeklyEnergy }} {{ t('chorestrike', 'total energy/week') }}</span>
				</div>
			</div>

			<WeekBudgetGrid
				:budget="localBudget"
				:disabled="saving"
				@update-day="setBudgetDay" />

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

			<BudgetAnalysis :budget="localBudget" />

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
import { weekDays, ENERGY_MIN, ENERGY_MAX } from '../../constants/scheduling.js'
import { createDefaultEnergyBudget } from '../../utils/energyBudgets.js'
import WeekBudgetGrid from './WeekBudgetGrid.vue'
import BudgetAnalysis from './BudgetAnalysis.vue'

const schedulingStore = useSchedulingStore()

const saving = ref(false)
const localBudget = ref(createDefaultEnergyBudget())

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

const totalWeeklyEnergy = computed(() => {
	return Object.values(localBudget.value).reduce((sum, energy) => sum + energy, 0)
})

const hasChanges = computed(() => {
	return JSON.stringify(localBudget.value) !== JSON.stringify(schedulingStore.energyBudget)
})

const syncFromStore = () => {
	localBudget.value = { ...createDefaultEnergyBudget(), ...schedulingStore.energyBudget }
}

// Apply one day's edit onto the CURRENT budget — the grid emits deltas so a
// fast interaction can never resurrect stale values (see WeekBudgetGrid).
const setBudgetDay = ({ key, value }) => {
	localBudget.value = { ...localBudget.value, [key]: value }
}

const applyEnergyTemplate = (template) => {
	localBudget.value = { ...template.budget }
}

const saveEnergyBudget = async () => {
	// Validate every value up front so a partial set is never saved silently.
	const invalid = weekDays.filter((day) => {
		const energy = localBudget.value[day.key]
		return !Number.isInteger(energy) || energy < ENERGY_MIN || energy > ENERGY_MAX
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

	.template-grid {
		grid-template-columns: 1fr;
	}
}
</style>
