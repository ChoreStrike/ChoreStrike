<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="schedule-planner" data-testid="schedule-planner">
		<div class="planner-header">
			<h2>{{ t('chorestrike', 'Schedule Preview') }}</h2>
			<div class="preview-controls">
				<button
					class="generate-button"
					:disabled="loading"
					data-testid="generate-schedule-button"
					@click="loadSuggestions">
					{{ t('chorestrike', 'Load Preview') }}
				</button>
			</div>
		</div>

		<div v-if="loading" class="loading-state">
			<div class="loading-spinner" />
			<p>{{ t('chorestrike', 'Analyzing tasks and generating optimal schedule...') }}</p>
		</div>

		<div v-else-if="hasLoaded" class="preview-content">
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

			<div v-if="suggestions.length > 0" class="suggestions-list" data-testid="scheduling-suggestions">
				<h3>{{ t('chorestrike', 'Scheduling Suggestions') }}</h3>
				<p class="list-hint">
					{{ t('chorestrike', 'Applying a suggestion commits your share of that day\'s energy to your own week.') }}
				</p>
				<div
					v-for="suggestion in suggestions"
					:key="suggestion.day"
					class="suggestion-item">
					<div class="suggestion-content">
						<h4>{{ suggestion.title }}</h4>
						<p>{{ suggestion.description }}</p>
						<p class="share-preview" :data-testid="`share-preview-${suggestion.day}`">
							{{ t('chorestrike', 'Sets your {day} budget to {energy} energy', {
								day: suggestion.title,
								energy: personalShare(suggestion),
							}) }}
						</p>
					</div>
					<button
						class="apply-button"
						:disabled="applyingDay !== null || personalShare(suggestion) < ENERGY_MIN"
						:data-testid="`apply-suggestion-${suggestion.day}`"
						@click="applySuggestion(suggestion)">
						{{ applyingDay === suggestion.day ? t('chorestrike', 'Applying...') : t('chorestrike', 'Apply') }}
					</button>
				</div>
			</div>

			<div v-else class="empty-suggestions">
				<p>{{ t('chorestrike', 'No scheduling suggestions available. Add more tasks or adjust member availability.') }}</p>
			</div>
		</div>

		<div v-else class="empty-state">
			<h3>{{ t('chorestrike', 'No Preview Available') }}</h3>
			<p>{{ t('chorestrike', 'Load a preview to see scheduling capacity and suggestions') }}</p>
			<button class="primary-button" data-testid="load-preview-button" @click="loadSuggestions">
				{{ t('chorestrike', 'Load Preview') }}
			</button>
		</div>
	</div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { useSchedulingStore } from '../../store/scheduling.js'
import { useTasksStore } from '../../store/tasks.js'
import { logger } from '../../utils/logger.js'
import { weekDays, ENERGY_MIN, ENERGY_MAX } from '../../constants/scheduling.js'
import { resolveEnergyForDay } from '../../utils/energyBudgets.js'

const schedulingStore = useSchedulingStore()
const tasksStore = useTasksStore()

const suggestions = ref([])
// False until the first load — distinguishes "never loaded" from "loaded but empty".
const hasLoaded = ref(false)

// Component-local loading: the store no longer toggles the shared loading
// flag per action (that unmounted the whole scheduling view mid-request).
const loading = ref(false)
// Day currently being persisted, for per-row in-flight state.
const applyingDay = ref(null)

const availableMembersCount = computed(() => schedulingStore.memberAvailability.length)
const totalWeeklyEnergy = computed(() => schedulingStore.totalWeeklyEnergy)

const loadSuggestions = async () => {
	loading.value = true
	try {
		const result = await schedulingStore.getSchedulingSuggestions(tasksStore.activeTasks.length)
		suggestions.value = Array.isArray(result) ? result : []
		hasLoaded.value = true
	} catch (error) {
		logger.error('Failed to load schedule preview', error, { component: 'SchedulePlanner', action: 'loadSuggestions' })
		showError(t('chorestrike', 'Failed to load schedule preview'))
	} finally {
		loading.value = false
	}
}

// Fair share of the suggested day energy for the current user, clamped to the
// valid range. Shown inline BEFORE persisting (preview of the result).
const personalShare = (suggestion) => {
	const members = Math.max(1, suggestion.availableMembers)
	return Math.min(ENERGY_MAX, Math.max(0, Math.round(suggestion.totalEnergy / members)))
}

const applySuggestion = async (suggestion) => {
	if (applyingDay.value !== null) return

	const share = personalShare(suggestion)
	if (share < ENERGY_MIN) return

	applyingDay.value = suggestion.day
	try {
		// Persist exactly the suggested distribution for that day: available,
		// with the personal energy share; all other days keep their state.
		const weekdayAvailability = {}
		const energyBudget = {}
		for (const day of weekDays) {
			const isTarget = day.key === suggestion.day
			weekdayAvailability[day.key] = isTarget || !!schedulingStore.weekdayAvailability[day.key]
			energyBudget[day.key] = isTarget
				? share
				: resolveEnergyForDay(schedulingStore.energyBudget, day.key)
		}

		await schedulingStore.saveWeeklyPreferences({ weekdayAvailability, energyBudget })
		showSuccess(t('chorestrike', 'Suggestion applied to your week'))
	} catch (error) {
		logger.error('Failed to apply suggestion', error, { component: 'SchedulePlanner', action: 'applySuggestion', day: suggestion.day })
		showError(t('chorestrike', 'Failed to apply the suggestion'))
	} finally {
		applyingDay.value = null
	}
}
</script>

<style scoped>
.schedule-planner {
	background: var(--color-main-background);
	border-radius: 12px;
	padding: 1.5rem;
}

.planner-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 2rem;
	flex-wrap: wrap;
	gap: 1rem;
}

.planner-header h2 {
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
	margin-bottom: 0.25rem;
}

.list-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	margin: 0 0 1rem;
}

.suggestion-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 1rem;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	margin-bottom: 0.75rem;
	background: var(--color-background-dark);
}

.suggestion-content h4 {
	font-size: 1rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.25rem;
}

.suggestion-content p {
	margin: 0 0 0.25rem;
	color: var(--color-main-text);
}

.share-preview {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.apply-button {
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
	border-radius: 6px;
	padding: 0.5rem 1.25rem;
	font-weight: 500;
	cursor: pointer;
	transition: background-color 0.2s ease;
	flex-shrink: 0;
}

.apply-button:hover:not(:disabled) {
	background: var(--color-primary-hover);
}

.apply-button:disabled {
	opacity: 0.6;
	cursor: not-allowed;
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
	.planner-header {
		flex-direction: column;
		align-items: flex-start;
	}

	.summary-cards {
		grid-template-columns: 1fr;
	}

	.suggestion-item {
		flex-direction: column;
		align-items: flex-start;
	}
}
</style>
