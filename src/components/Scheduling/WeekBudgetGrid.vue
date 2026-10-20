<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="week-budget-grid">
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
						type="range"
						:min="ENERGY_MIN"
						:max="ENERGY_MAX"
						:value="budget[day.key]"
						class="energy-slider"
						:disabled="disabled"
						:data-testid="`energy-slider-${day.key}`"
						@input="setDay(day.key, $event.target.valueAsNumber)">
					<div class="slider-marks">
						<span v-for="mark in marks" :key="mark" class="mark">{{ mark }}</span>
					</div>
				</div>
				<div class="energy-value-display">
					<input
						:id="`energy-input-${day.key}`"
						type="number"
						:min="ENERGY_MIN"
						:max="ENERGY_MAX"
						:value="budget[day.key]"
						class="energy-number-input"
						:disabled="disabled"
						:data-testid="`energy-input-${day.key}`"
						@input="setDay(day.key, $event.target.value === '' ? '' : $event.target.valueAsNumber)">
					<span class="energy-unit">{{ t('chorestrike', 'energy') }}</span>
				</div>
			</div>
			<div class="energy-indicator">
				<div
					class="energy-bar"
					:style="{ width: `${((budget[day.key] || 0) / ENERGY_MAX) * 100}%` }" />
			</div>
		</div>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { ENERGY_MAX, ENERGY_MIN, weekDays } from '../../constants/scheduling.js'

defineProps({
	/** Budget map keyed by weekday */
	budget: {
		type: Object,
		required: true,
	},
	disabled: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['updateDay'])

const marks = [ENERGY_MIN, 5, 10, 15, ENERGY_MAX]

// Emit only the edited day's delta; the parent applies it onto its current
// budget state. Spreading a (possibly stale) prop snapshot here would let a
// fast interaction overwrite freshly synced days with old values.
function setDay(key, value) {
	emit('updateDay', { key, value })
}
</script>

<style scoped>
.week-budget-grid {
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

@media (max-width: 768px) {
	.budget-input-group {
		flex-direction: column;
		gap: 0.75rem;
	}

	.energy-value-display {
		width: 100%;
		justify-content: space-between;
	}
}
</style>
