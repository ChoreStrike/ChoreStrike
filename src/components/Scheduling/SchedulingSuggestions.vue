<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="scheduling-suggestions" data-testid="scheduling-suggestions">
		<div class="suggestions-header">
			<h2>{{ t('chorestrike', 'Smart Scheduling Suggestions') }}</h2>
			<p>{{ t('chorestrike', 'Recommendations for optimal task distribution') }}</p>
		</div>

		<div v-if="loading" class="loading-state">
			<div class="loading-spinner" />
			<p>{{ t('chorestrike', 'Analyzing tasks and generating suggestions...') }}</p>
		</div>

		<div v-else-if="suggestions.length > 0" class="suggestions-content">
			<div class="summary-cards">
				<div class="summary-card">
					<div class="card-content">
						<h3>{{ suggestions.length }}</h3>
						<p>{{ t('chorestrike', 'Suggestions Available') }}</p>
					</div>
				</div>
				<div class="summary-card">
					<div class="card-content">
						<h3>{{ membersInvolved }}</h3>
						<p>{{ t('chorestrike', 'Members Affected') }}</p>
					</div>
				</div>
			</div>

			<div class="suggestions-grid">
				<div
					v-for="(suggestion, index) in suggestions"
					:key="index"
					class="suggestion-card">
					<div class="suggestion-header">
						<div class="suggestion-title">
							<h3>{{ suggestion.title || suggestion.type || t('chorestrike', 'Suggestion') }}</h3>
						</div>
					</div>

					<div class="suggestion-content">
						<p class="suggestion-description">
							{{ suggestion.description || suggestion.message || t('chorestrike', 'Suggestion') }}
						</p>

						<div v-if="suggestion.affectedTasks && suggestion.affectedTasks.length > 0" class="affected-tasks">
							<h4>{{ t('chorestrike', 'Affected Tasks') }}</h4>
							<div class="task-list">
								<div
									v-for="task in suggestion.affectedTasks.slice(0, 3)"
									:key="task.id || task.title"
									class="task-item">
									<span class="task-title">{{ task.title }}</span>
								</div>
								<div
									v-if="suggestion.affectedTasks.length > 3"
									class="more-tasks">
									{{ t('chorestrike', 'And {count} more tasks', { count: suggestion.affectedTasks.length - 3 }) }}
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<div class="suggestions-actions">
				<button
					class="refresh-button"
					:disabled="loading"
					data-testid="refresh-suggestions-button"
					@click="refreshSuggestions">
					{{ t('chorestrike', 'Refresh Suggestions') }}
				</button>
			</div>
		</div>

		<div v-else class="empty-state">
			<h3>{{ t('chorestrike', 'No Suggestions Available') }}</h3>
			<p>{{ t('chorestrike', 'Add tasks and configure member availability to get scheduling suggestions') }}</p>
			<button
				class="primary-button"
				data-testid="load-suggestions-button"
				@click="refreshSuggestions">
				{{ t('chorestrike', 'Load Suggestions') }}
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

const suggestions = ref([])

// Component-local loading: the store no longer toggles the shared loading
// flag per action (that unmounted the whole scheduling view mid-request).
const loading = ref(false)

const membersInvolved = computed(() => {
	return schedulingStore.memberAvailability.length
})

const refreshSuggestions = async () => {
	loading.value = true
	try {
		const result = await schedulingStore.getSchedulingSuggestions(tasksStore.activeTasks)
		suggestions.value = Array.isArray(result) ? result : []
	} catch (error) {
		logger.error('Failed to load suggestions', error, { component: 'SchedulingSuggestions', action: 'refreshSuggestions' })
		showError(t('chorestrike', 'Failed to load scheduling suggestions'))
		suggestions.value = []
	} finally {
		loading.value = false
	}
}
</script>

<style scoped>
.scheduling-suggestions {
	background: var(--color-main-background);
	border-radius: 12px;
	padding: 1.5rem;
}

.suggestions-header {
	margin-bottom: 2rem;
}

.suggestions-header h2 {
	font-size: 1.5rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.5rem;
}

.suggestions-header p {
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

.suggestions-grid {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	margin-bottom: 2rem;
}

.suggestion-card {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 1.5rem;
	background: var(--color-background-dark);
}

.suggestion-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 1rem;
}

.suggestion-title h3 {
	font-size: 1.1rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0;
}

.suggestion-description {
	color: var(--color-main-text);
	margin-bottom: 1rem;
}

.affected-tasks h4 {
	font-size: 0.9rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin-bottom: 0.5rem;
}

.task-list {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.task-item {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.5rem;
	background: var(--color-main-background);
	border-radius: 4px;
}

.task-title {
	font-weight: 500;
	color: var(--color-main-text);
}

.more-tasks {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	padding: 0.25rem 0.5rem;
}

.suggestions-actions {
	display: flex;
	justify-content: center;
}

.refresh-button {
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

.refresh-button:hover:not(:disabled) {
	background: var(--color-primary-hover);
}

.refresh-button:disabled {
	opacity: 0.6;
	cursor: not-allowed;
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
	.summary-cards {
		grid-template-columns: 1fr;
	}
}
</style>
