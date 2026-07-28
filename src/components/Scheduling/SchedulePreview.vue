<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="schedule-preview" data-testid="schedule-preview">
		<div class="preview-header">
			<h2>{{ t('chorestrike', 'Schedule Preview') }}</h2>
			<div class="preview-controls">
				<button
					class="generate-button"
					:disabled="loading"
					data-testid="generate-schedule-button"
					@click="loadPreview">
					{{ t('chorestrike', 'Load Preview') }}
				</button>
			</div>
		</div>

		<div v-if="loading" class="loading-state">
			<div class="loading-spinner" />
			<p>{{ t('chorestrike', 'Analyzing tasks and generating optimal schedule...') }}</p>
		</div>

		<div v-else-if="suggestions" class="preview-content">
			<div class="summary-cards">
				<div class="summary-card">
					<div class="card-content">
						<h3>{{ suggestions.length }}</h3>
						<p>{{ t('chorestrike', 'Suggestions') }}</p>
					</div>
				</div>
				<div class="summary-card">
					<div class="card-content">
						<h3>{{ availableMembersCount }}</h3>
						<p>{{ t('chorestrike', 'Available Members') }}</p>
					</div>
				</div>
				<div class="summary-card">
					<div class="card-content">
						<h3>{{ totalWeeklyEnergy }}</h3>
						<p>{{ t('chorestrike', 'Weekly Energy') }}</p>
					</div>
				</div>
			</div>

			<div v-if="suggestions.length > 0" class="suggestions-list">
				<h3>{{ t('chorestrike', 'Scheduling Suggestions') }}</h3>
				<div
					v-for="(suggestion, index) in suggestions"
					:key="index"
					class="suggestion-item">
					<div class="suggestion-content">
						<p>{{ suggestion.description || suggestion.message || t('chorestrike', 'Suggestion') }}</p>
					</div>
				</div>
			</div>

			<div v-else class="empty-suggestions">
				<p>{{ t('chorestrike', 'No scheduling suggestions available. Add more tasks or adjust member availability.') }}</p>
			</div>
		</div>

		<div v-else class="empty-state">
			<h3>{{ t('chorestrike', 'No Preview Available') }}</h3>
			<p>{{ t('chorestrike', 'Load a preview to see scheduling capacity and suggestions') }}</p>
			<button class="primary-button" data-testid="load-preview-button" @click="loadPreview">
				{{ t('chorestrike', 'Load Preview') }}
			</button>
		</div>
	</div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import { useSchedulingStore } from '../../store/scheduling.js'
import { useTasksStore } from '../../store/tasks.js'
import { logger } from '../../utils/logger.js'

const schedulingStore = useSchedulingStore()
const tasksStore = useTasksStore()

const suggestions = ref(null)

// Component-local loading: the store no longer toggles the shared loading
// flag per action (that unmounted the whole scheduling view mid-request).
const loading = ref(false)

const availableMembersCount = computed(() => {
	return schedulingStore.memberAvailability.length
})

const totalWeeklyEnergy = computed(() => {
	return schedulingStore.totalWeeklyEnergy
})

const loadPreview = async () => {
	loading.value = true
	try {
		const result = await schedulingStore.getSchedulingSuggestions(tasksStore.activeTasks)
		suggestions.value = Array.isArray(result) ? result : []
	} catch (error) {
		logger.error('Failed to load schedule preview', error, { component: 'SchedulePreview', action: 'loadPreview' })
		showError(t('chorestrike', 'Failed to load schedule preview'))
		suggestions.value = []
	} finally {
		loading.value = false
	}
}
</script>

<style scoped>
.schedule-preview {
	background: var(--color-main-background);
	border-radius: 12px;
	padding: 1.5rem;
}

.preview-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 2rem;
	flex-wrap: wrap;
	gap: 1rem;
}

.preview-header h2 {
	font-size: 1.5rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0;
}

.generate-button {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
	border-radius: 6px;
	padding: 0.75rem 1.5rem;
	font-weight: 500;
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.generate-button:hover:not(:disabled) {
	background: var(--color-primary-hover);
}

.generate-button:disabled {
	opacity: 0.6;
	cursor: not-allowed;
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

.summary-cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 1rem;
	margin-bottom: 2rem;
}

.summary-card {
	display: flex;
	align-items: center;
	gap: 1rem;
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 1rem;
}

.card-content h3 {
	font-size: 1.5rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0;
}

.card-content p {
	color: var(--color-text-maxcontrast);
	margin: 0;
	font-size: 0.9rem;
}

.suggestions-list h3 {
	font-size: 1.2rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 1rem;
}

.suggestion-item {
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	margin-bottom: 0.75rem;
	background: var(--color-background-dark);
}

.suggestion-content p {
	margin: 0;
	color: var(--color-main-text);
}

.empty-suggestions {
	text-align: center;
	padding: 2rem 0;
	color: var(--color-text-maxcontrast);
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
	margin-bottom: 1.5rem;
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
	.preview-header {
		flex-direction: column;
		align-items: flex-start;
	}

	.summary-cards {
		grid-template-columns: 1fr;
	}
}
</style>
