<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="scheduling-view" data-testid="scheduling-view">
		<header class="scheduling-header">
			<h1>{{ t('chorestrike', 'Smart Scheduling') }}</h1>
			<p class="subtitle">
				{{ t('chorestrike', 'Intelligent task distribution based on member availability and preferences') }}
			</p>
		</header>

		<div class="scheduling-content">
			<!-- Schedule Preview Section -->
			<section class="scheduling-section">
				<SchedulePreview
					:house-id="currentHouseId"
					@schedule-generated="handleScheduleGenerated"
					@conflict-detected="handleConflictDetected" />
			</section>

			<!-- Member Availability Settings -->
			<section class="scheduling-section">
				<AvailabilitySettings
					:house-id="currentHouseId"
					@availability-updated="handleAvailabilityUpdated" />
			</section>

			<!-- Energy Budget Settings -->
			<section class="scheduling-section">
				<EnergyBudgetSettings
					:house-id="currentHouseId"
					@budget-updated="handleBudgetUpdated" />
			</section>

			<!-- Schedule Calendar View -->
			<section class="scheduling-section">
				<ScheduleCalendar
					:house-id="currentHouseId"
					:schedule="currentSchedule"
					@task-moved="handleTaskMoved" />
			</section>

			<!-- Scheduling Suggestions -->
			<section class="scheduling-section">
				<SchedulingSuggestions
					:house-id="currentHouseId"
					@suggestion-applied="handleSuggestionApplied" />
			</section>
		</div>

		<!-- Notifications -->
		<Modal
			v-if="showSuccessModal"
			:title="t('chorestrike', 'Success')"
			@close="showSuccessModal = false">
			<p>{{ successMessage }}</p>
			<template #footer>
				<button class="primary-button" @click="showSuccessModal = false">
					{{ t('chorestrike', 'OK') }}
				</button>
			</template>
		</Modal>

		<Modal
			v-if="showErrorModal"
			:title="t('chorestrike', 'Error')"
			@close="showErrorModal = false">
			<p>{{ errorMessage }}</p>
			<template #footer>
				<button class="primary-button" @click="showErrorModal = false">
					{{ t('chorestrike', 'OK') }}
				</button>
			</template>
		</Modal>
	</div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useHouseStore } from '../store/house.js'
import { logger } from '../utils/logger.js'
import SchedulePreview from '../components/Scheduling/SchedulePreview.vue'
import AvailabilitySettings from '../components/Scheduling/AvailabilitySettings.vue'
import EnergyBudgetSettings from '../components/Scheduling/EnergyBudgetSettings.vue'
import ScheduleCalendar from '../components/Scheduling/ScheduleCalendar.vue'
import SchedulingSuggestions from '../components/Scheduling/SchedulingSuggestions.vue'
import Modal from '../components/Common/Modal.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

interface ConflictItem {
	message: string
}

// Stores
const houseStore = useHouseStore()

// Reactive state
const currentSchedule = ref(null)
const showSuccessModal = ref(false)
const showErrorModal = ref(false)
const successMessage = ref('')
const errorMessage = ref('')

// Computed properties
const currentHouseId = computed<number>(() => houseStore.currentHouse?.id ?? 0)

// Event handlers
const handleScheduleGenerated = (schedule: unknown) => {
	currentSchedule.value = schedule
	showSuccess(t('chorestrike', 'Schedule generated successfully!'))
}

const handleConflictDetected = (conflicts: ConflictItem[]) => {
	if (conflicts.length > 0) {
		const conflictMessages = conflicts.map((conflict: ConflictItem) => conflict.message).join('. ')
		showError(t('chorestrike', 'Scheduling conflicts detected: {conflicts}', { conflicts: conflictMessages }))
	}
}

const handleAvailabilityUpdated = (_availability: unknown) => {
	showSuccess(t('chorestrike', 'Availability updated successfully!'))
}

const handleBudgetUpdated = (_budget: unknown) => {
	showSuccess(t('chorestrike', 'Energy budget updated successfully!'))
}

const handleTaskMoved = (_taskUpdate: unknown) => {
	showSuccess(t('chorestrike', 'Task schedule updated!'))
}

const handleSuggestionApplied = (_suggestion: unknown) => {
	showSuccess(t('chorestrike', 'Scheduling suggestion applied!'))
}

// Lifecycle
onMounted(async () => {
	try {
		await houseStore.loadCurrentHouse()
	} catch (error) {
		logger.error('Failed to load house', error, { view: 'SchedulingView', action: 'loadCurrentHouse' })
		showError(t('chorestrike', 'Failed to load house data'))
	}
})
</script>

<style scoped>
.scheduling-view {
	max-width: 1200px;
	margin: 0 auto;
	padding: 2rem;
}

.scheduling-header {
	text-align: center;
	margin-bottom: 3rem;
}

.scheduling-header h1 {
	font-size: 2.5rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.5rem;
}

.subtitle {
	font-size: 1.1rem;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.scheduling-content {
	display: flex;
	flex-direction: column;
	gap: 2rem;
}

.scheduling-section {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 12px;
	padding: 1.5rem;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.scheduling-section h2 {
	font-size: 1.5rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 1rem;
}

.primary-button {
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
	border-radius: 6px;
	padding: 0.75rem 1.5rem;
	font-weight: 500;
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.primary-button:hover {
	background: var(--color-primary-hover);
}

@media (max-width: 768px) {
	.scheduling-view {
		padding: 1rem;
	}

	.scheduling-header h1 {
		font-size: 2rem;
	}

	.scheduling-section {
		padding: 1rem;
	}
}
</style>
