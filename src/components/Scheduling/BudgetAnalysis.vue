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

/**
 * Day/energy pairs with non-finite entries coerced to 0 — budget values can
 * be transiently empty ('') or NaN while an input is being edited, and the
 * display must stay numeric (BUG-UI-001).
 */
const dailyEnergies = computed(() => {
	return Object.entries(props.budget).map(([day, energy]) => {
		const value = Number(energy)
		return [day, Number.isFinite(value) ? value : 0]
	})
})

const totalWeeklyEnergy = computed(() => {
	return dailyEnergies.value.reduce((sum, [, energy]) => sum + energy, 0)
})

const averageDailyEnergy = computed(() => {
	const total = totalWeeklyEnergy.value
	return total > 0 ? Math.round(total / 7) : 0
})

const peakDay = computed(() => {
	let maxEnergy = 0
	let peakDayKey = 'monday'
	dailyEnergies.value.forEach(([day, energy]) => {
		if (energy > maxEnergy) {
			maxEnergy = energy
			peakDayKey = day
		}
	})
	const day = weekDays.find(d => d.key === peakDayKey)
	return day ? day.short : t('chorestrike', 'None')
})

const lowEnergyDays = computed(() => {
	return dailyEnergies.value.filter(([, energy]) => energy <= 5).length
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
