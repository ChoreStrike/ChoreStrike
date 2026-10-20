<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="self-availability-settings" data-testid="self-availability-settings">
		<div class="settings-header">
			<h2>{{ t('chorestrike', 'My Availability') }}</h2>
			<p>{{ t('chorestrike', 'Choose on which days you are available for tasks') }}</p>
		</div>

		<div class="self-section">
			<div class="section-header">
				<h3>{{ t('chorestrike', 'Weekly Availability') }}</h3>
				<span class="days-count" data-testid="self-availability-count">
					{{ availableDaysCount }}/7 {{ t('chorestrike', 'days available') }}
				</span>
			</div>

			<div class="availability-toggle-grid">
				<div
					v-for="day in weekDays"
					:key="day.key"
					class="toggle-item">
					<label class="toggle-label">
						<input
							v-model="localAvailability[day.key]"
							type="checkbox"
							class="toggle-input cs-visually-hidden"
							:disabled="saving"
							:data-testid="`self-availability-${day.key}`">
						<span class="toggle-slider" />
						<span class="toggle-text">{{ day.name }}</span>
					</label>
				</div>
			</div>

			<div class="actions">
				<button
					class="secondary-button"
					:disabled="saving"
					data-testid="cancel-self-availability-button"
					@click="resetFromStore">
					{{ t('chorestrike', 'Cancel') }}
				</button>
				<button
					class="primary-button"
					:disabled="saving || !hasChanges"
					data-testid="save-self-availability-button"
					@click="saveAvailability">
					{{ saving ? t('chorestrike', 'Saving...') : t('chorestrike', 'Save Changes') }}
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
import { weekDays } from '../../constants/scheduling.js'

const schedulingStore = useSchedulingStore()

const saving = ref(false)
const localAvailability = ref(defaultAvailability())

function defaultAvailability() {
	return {
		monday: true,
		tuesday: true,
		wednesday: true,
		thursday: true,
		friday: true,
		saturday: true,
		sunday: true,
	}
}

const availableDaysCount = computed(() => {
	return weekDays.filter((day) => !!localAvailability.value[day.key]).length
})

const hasChanges = computed(() => {
	return weekDays.some((day) => !!localAvailability.value[day.key] !== !!schedulingStore.weekdayAvailability[day.key])
})

const syncFromStore = () => {
	localAvailability.value = { ...defaultAvailability(), ...schedulingStore.weekdayAvailability }
}

const resetFromStore = () => {
	syncFromStore()
}

const saveAvailability = async () => {
	const changedDays = weekDays
		.filter((day) => !!localAvailability.value[day.key] !== !!schedulingStore.weekdayAvailability[day.key])
	if (changedDays.length === 0) {
		return
	}

	saving.value = true
	try {
		// One bulk save carrying the full week (CS-033 adoption hook,
		// recorded in CS-PLAN-032's save handler).
		const weekdayAvailability = {}
		for (const day of weekDays) {
			weekdayAvailability[day.key] = !!localAvailability.value[day.key]
		}
		await schedulingStore.saveWeeklyPreferences({ weekdayAvailability })
		showSuccess(t('chorestrike', 'Availability settings saved successfully'))
	} catch (error) {
		logger.error('Failed to save availability', error, { component: 'SelfAvailabilitySettings', action: 'saveAvailability' })
		showError(t('chorestrike', 'Failed to save availability settings'))
	} finally {
		saving.value = false
	}
}

onMounted(() => {
	syncFromStore()
})

watch(() => schedulingStore.weekdayAvailability, () => {
	syncFromStore()
}, { deep: true })
</script>

<style scoped>
.self-availability-settings {
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

.self-section {
	display: flex;
	flex-direction: column;
	gap: 1.5rem;
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

.days-count {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
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
	flex-shrink: 0;
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

.actions {
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

.secondary-button:hover:not(:disabled) {
	background: var(--color-background-dark);
}

.secondary-button:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

@media (max-width: 768px) {
	.section-header {
		flex-direction: column;
		align-items: flex-start;
		gap: 0.5rem;
	}

	.availability-toggle-grid {
		grid-template-columns: 1fr;
	}
}
</style>
