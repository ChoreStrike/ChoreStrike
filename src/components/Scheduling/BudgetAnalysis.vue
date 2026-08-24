<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="budget-analysis" data-testid="budget-analysis">
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
</template>

<script setup>
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { weekDays } from '../../constants/scheduling.js'

const props = defineProps({
	/** Budget map keyed by weekday (may include unsaved edits). */
	budget: {
		type: Object,
		required: true,
	},
})

const totalWeeklyEnergy = computed(() => {
	return Object.values(props.budget).reduce((sum, energy) => sum + energy, 0)
})

const averageDailyEnergy = computed(() => {
	const total = totalWeeklyEnergy.value
	return total > 0 ? Math.round(total / 7) : 0
})

const peakDay = computed(() => {
	let maxEnergy = 0
	let peakDayKey = 'monday'
	Object.entries(props.budget).forEach(([day, energy]) => {
		if (energy > maxEnergy) {
			maxEnergy = energy
			peakDayKey = day
		}
	})
	const day = weekDays.find(d => d.key === peakDayKey)
	return day ? day.short : t('chorestrike', 'None')
})

const lowEnergyDays = computed(() => {
	return Object.values(props.budget).filter(energy => energy <= 5).length
})
</script>

<style scoped>
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

@media (max-width: 768px) {
	.analysis-cards {
		grid-template-columns: 1fr;
	}
}
</style>
