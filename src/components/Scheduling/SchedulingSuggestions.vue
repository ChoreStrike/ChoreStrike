<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="scheduling-suggestions" data-testid="scheduling-suggestions">
		<div class="suggestions-header">
			<h2>{{ t('chorestrike', 'Smart Scheduling Suggestions') }}</h2>
			<p>{{ t('chorestrike', 'AI-powered recommendations for optimal task distribution') }}</p>
		</div>

		<!-- Loading State -->
		<div v-if="loading" class="loading-state">
			<div class="loading-spinner"></div>
			<p>{{ t('chorestrike', 'Analyzing tasks and generating suggestions...') }}</p>
		</div>

		<!-- Suggestions List -->
		<div v-else-if="suggestions.length > 0" class="suggestions-content">
			<!-- Summary Cards -->
			<div class="summary-cards">
				<div class="summary-card">
					<div class="card-icon">
						<Icon name="Lightbulb" :size="24" />
					</div>
					<div class="card-content">
						<h3>{{ suggestions.length }}</h3>
						<p>{{ t('chorestrike', 'Suggestions Available') }}</p>
					</div>
				</div>
				<div class="summary-card">
					<div class="card-icon">
						<Icon name="TrendingUp" :size="24" />
					</div>
					<div class="card-content">
						<h3>{{ Math.round(efficiencyImprovement * 100) }}%</h3>
						<p>{{ t('chorestrike', 'Efficiency Gain') }}</p>
					</div>
				</div>
				<div class="summary-card">
					<div class="card-icon">
						<Icon name="Users" :size="24" />
					</div>
					<div class="card-content">
						<h3>{{ membersInvolved }}</h3>
						<p>{{ t('chorestrike', 'Members Affected') }}</p>
					</div>
				</div>
			</div>

			<!-- Filter Controls -->
			<div class="filter-controls">
				<div class="filter-group">
					<label for="suggestion-type">{{ t('chorestrike', 'Type') }}:</label>
					<select
						id="suggestion-type"
						v-model="selectedType"
						@change="filterSuggestions"
						data-testid="suggestion-type-filter">
						<option value="all">{{ t('chorestrike', 'All Types') }}</option>
						<option value="reassignment">{{ t('chorestrike', 'Reassignment') }}</option>
						<option value="rescheduling">{{ t('chorestrike', 'Rescheduling') }}</option>
						<option value="capacity">{{ t('chorestrike', 'Capacity') }}</option>
						<option value="fairness">{{ t('chorestrike', 'Fairness') }}</option>
					</select>
				</div>
				<div class="filter-group">
					<label for="suggestion-priority">{{ t('chorestrike', 'Priority') }}:</label>
					<select
						id="suggestion-priority"
						v-model="selectedPriority"
						@change="filterSuggestions"
						data-testid="suggestion-priority-filter">
						<option value="all">{{ t('chorestrike', 'All Priorities') }}</option>
						<option value="high">{{ t('chorestrike', 'High') }}</option>
						<option value="medium">{{ t('chorestrike', 'Medium') }}</option>
						<option value="low">{{ t('chorestrike', 'Low') }}</option>
					</select>
				</div>
				<button
					class="refresh-button"
					@click="refreshSuggestions"
					:disabled="loading"
					data-testid="refresh-suggestions-button">
					<Icon name="Refresh" :size="16" />
					{{ t('chorestrike', 'Refresh') }}
				</button>
			</div>

			<!-- Suggestions Grid -->
			<div class="suggestions-grid">
				<div
					v-for="suggestion in filteredSuggestions"
					:key="suggestion.id"
					:class="['suggestion-card', `priority-${suggestion.priority}`, `type-${suggestion.type}`]">
					<div class="suggestion-header">
						<div class="suggestion-icon">
							<Icon :name="getSuggestionIcon(suggestion.type)" :size="20" />
						</div>
						<div class="suggestion-title">
							<h3>{{ suggestion.title }}</h3>
							<span class="suggestion-type">{{ getSuggestionTypeLabel(suggestion.type) }}</span>
						</div>
						<div class="suggestion-priority">
							<span :class="['priority-badge', `priority-${suggestion.priority}`]">
								{{ getPriorityLabel(suggestion.priority) }}
							</span>
						</div>
					</div>

					<div class="suggestion-content">
						<p class="suggestion-description">{{ suggestion.description }}</p>
						
						<!-- Impact Analysis -->
						<div class="impact-analysis">
							<h4>{{ t('chorestrike', 'Expected Impact') }}</h4>
							<div class="impact-metrics">
								<div class="metric">
									<span class="metric-label">{{ t('chorestrike', 'Efficiency') }}:</span>
									<span class="metric-value positive">+{{ suggestion.impact.efficiency }}%</span>
								</div>
								<div class="metric">
									<span class="metric-label">{{ t('chorestrike', 'Fairness') }}:</span>
									<span class="metric-value" :class="getImpactClass(suggestion.impact.fairness)">
										{{ suggestion.impact.fairness > 0 ? '+' : '' }}{{ suggestion.impact.fairness }}
									</span>
								</div>
								<div class="metric">
									<span class="metric-label">{{ t('chorestrike', 'Tasks Affected') }}:</span>
									<span class="metric-value">{{ suggestion.affectedTasks.length }}</span>
								</div>
							</div>
						</div>

						<!-- Affected Tasks -->
						<div class="affected-tasks">
							<h4>{{ t('chorestrike', 'Affected Tasks') }}</h4>
							<div class="task-list">
								<div
									v-for="task in suggestion.affectedTasks.slice(0, 3)"
									:key="task.id"
									class="task-item">
									<span class="task-title">{{ task.title }}</span>
									<span class="task-change">
										{{ getTaskChangeDescription(task) }}
									</span>
								</div>
								<div
									v-if="suggestion.affectedTasks.length > 3"
									class="more-tasks">
									{{ t('chorestrike', 'And {count} more tasks', { count: suggestion.affectedTasks.length - 3 }) }}
								</div>
							</div>
						</div>
					</div>

					<div class="suggestion-actions">
						<button
							class="action-button apply"
							@click="applySuggestion(suggestion)"
							:data-testid="`apply-suggestion-${suggestion.id}`">
							<Icon name="Check" :size="16" />
							{{ t('chorestrike', 'Apply') }}
						</button>
						<button
							class="action-button details"
							@click="showSuggestionDetails(suggestion)"
							:data-testid="`details-suggestion-${suggestion.id}`">
							<Icon name="Info" :size="16" />
							{{ t('chorestrike', 'Details') }}
						</button>
						<button
							class="action-button dismiss"
							@click="dismissSuggestion(suggestion)"
							:data-testid="`dismiss-suggestion-${suggestion.id}`">
							<Icon name="Close" :size="16" />
							{{ t('chorestrike', 'Dismiss') }}
						</button>
					</div>
				</div>
			</div>

			<!-- Batch Actions -->
			<div class="batch-actions">
				<div class="batch-info">
					<span>{{ t('chorestrike', '{count} suggestions selected', { count: selectedSuggestions.length }) }}</span>
				</div>
				<div class="batch-buttons">
					<button
						class="batch-button apply-all"
						@click="applySelectedSuggestions"
						:disabled="selectedSuggestions.length === 0"
						data-testid="apply-selected-button">
						<Icon name="Check" :size="16" />
						{{ t('chorestrike', 'Apply Selected') }}
					</button>
					<button
						class="batch-button dismiss-all"
						@click="dismissAllSuggestions"
						data-testid="dismiss-all-button">
						<Icon name="Close" :size="16" />
						{{ t('chorestrike', 'Dismiss All') }}
					</button>
				</div>
			</div>
		</div>

		<!-- Empty State -->
		<div v-else class="empty-state">
			<div class="empty-icon">
				<Icon name="Lightbulb" :size="48" />
			</div>
			<h3>{{ t('chorestrike', 'No Suggestions Available') }}</h3>
			<p>{{ t('chorestrike', 'Your schedule looks optimal! Check back later for new recommendations.') }}</p>
			<button class="primary-button" @click="refreshSuggestions">
				<Icon name="Refresh" :size="16" />
				{{ t('chorestrike', 'Refresh Suggestions') }}
			</button>
		</div>

		<!-- Suggestion Details Modal -->
		<Modal
			v-if="showDetailsModal"
			:title="t('chorestrike', 'Suggestion Details')"
			@close="closeDetailsModal"
			data-testid="suggestion-details-modal">
			<div v-if="selectedSuggestion" class="suggestion-details">
				<div class="detail-header">
					<div class="detail-icon">
						<Icon :name="getSuggestionIcon(selectedSuggestion.type)" :size="24" />
					</div>
					<div class="detail-title">
						<h3>{{ selectedSuggestion.title }}</h3>
						<span class="detail-type">{{ getSuggestionTypeLabel(selectedSuggestion.type) }}</span>
					</div>
				</div>

				<div class="detail-description">
					<p>{{ selectedSuggestion.description }}</p>
				</div>

				<div class="detail-impact">
					<h4>{{ t('chorestrike', 'Detailed Impact Analysis') }}</h4>
					<div class="impact-chart">
						<div class="chart-item">
							<span class="chart-label">{{ t('chorestrike', 'Efficiency') }}</span>
							<div class="chart-bar">
								<div
									class="chart-fill positive"
									:style="{ width: `${Math.abs(selectedSuggestion.impact.efficiency) * 5}%` }">
								</div>
								<span class="chart-value">+{{ selectedSuggestion.impact.efficiency }}%</span>
							</div>
						</div>
						<div class="chart-item">
							<span class="chart-label">{{ t('chorestrike', 'Fairness') }}</span>
							<div class="chart-bar">
								<div
									class="chart-fill"
									:class="selectedSuggestion.impact.fairness > 0 ? 'positive' : 'negative'"
									:style="{ width: `${Math.abs(selectedSuggestion.impact.fairness) * 10}%` }">
								</div>
								<span class="chart-value">{{ selectedSuggestion.impact.fairness > 0 ? '+' : '' }}{{ selectedSuggestion.impact.fairness }}</span>
							</div>
						</div>
					</div>
				</div>

				<div class="detail-tasks">
					<h4>{{ t('chorestrike', 'All Affected Tasks') }}</h4>
					<div class="task-details-list">
						<div
							v-for="task in selectedSuggestion.affectedTasks"
							:key="task.id"
							class="task-detail-item">
							<div class="task-info">
								<span class="task-title">{{ task.title }}</span>
								<span class="task-priority">{{ getPriorityLabel(task.priority) }}</span>
							</div>
							<div class="task-changes">
								<div class="change-item">
									<span class="change-label">{{ t('chorestrike', 'From') }}:</span>
									<span class="change-value">{{ task.currentAssignment || t('chorestrike', 'Unassigned') }}</span>
								</div>
								<div class="change-item">
									<span class="change-label">{{ t('chorestrike', 'To') }}:</span>
									<span class="change-value">{{ task.suggestedAssignment }}</span>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<template #footer>
				<div class="modal-footer">
					<button class="secondary-button" @click="closeDetailsModal">
						{{ t('chorestrike', 'Close') }}
					</button>
					<button
						class="primary-button"
						@click="applySuggestion(selectedSuggestion)">
						<Icon name="Check" :size="16" />
						{{ t('chorestrike', 'Apply Suggestion') }}
					</button>
				</div>
			</template>
		</Modal>
	</div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import Icon from '../Common/Icon.vue'
import Modal from '../Common/Modal.vue'
import axios from '@nextcloud/axios'

type SuggestionType = 'reassignment' | 'rescheduling' | 'capacity' | 'fairness'
type SuggestionPriority = 'high' | 'medium' | 'low'

interface AffectedTask {
	id: number
	title: string
	priority: SuggestionPriority
	currentAssignment?: string
	suggestedAssignment?: string
}

interface SuggestionImpact {
	efficiency: number
	fairness: number
}

interface SchedulingSuggestion {
	id: number
	type: SuggestionType
	priority: SuggestionPriority
	title: string
	description: string
	impact: SuggestionImpact
	affectedTasks: AffectedTask[]
	selected?: boolean
}

interface DaySuggestionSummary {
	availableMembers: number
	totalEnergy: number
	recommendedTasks: number
}

interface SuggestionsResponse {
	data?: Record<string, DaySuggestionSummary> | SchedulingSuggestion[]
	success?: boolean
	error?: string
}

// Props
const props = defineProps<{
	houseId: number
}>()

// Emits
const emit = defineEmits<{
	suggestionApplied: [suggestion: SchedulingSuggestion]
}>()

// Reactive state
const loading = ref(false)
const suggestions = ref<SchedulingSuggestion[]>([])
const selectedType = ref<SuggestionType | 'all'>('all')
const selectedPriority = ref<SuggestionPriority | 'all'>('all')
const selectedSuggestions = ref<SchedulingSuggestion[]>([])
const showDetailsModal = ref(false)
const selectedSuggestion = ref<SchedulingSuggestion | null>(null)

// Computed properties
const filteredSuggestions = computed(() => {
	let filtered = suggestions.value

	if (selectedType.value !== 'all') {
		filtered = filtered.filter((s: SchedulingSuggestion) => s.type === selectedType.value)
	}

	if (selectedPriority.value !== 'all') {
		filtered = filtered.filter((s: SchedulingSuggestion) => s.priority === selectedPriority.value)
	}

	return filtered
})

const efficiencyImprovement = computed(() => {
	if (filteredSuggestions.value.length === 0) return 0
	const totalEfficiency = filteredSuggestions.value.reduce((sum: number, s: SchedulingSuggestion) => sum + s.impact.efficiency, 0)
	return totalEfficiency / filteredSuggestions.value.length
})

const membersInvolved = computed(() => {
	const members = new Set<string>()
	filteredSuggestions.value.forEach((suggestion: SchedulingSuggestion) => {
		suggestion.affectedTasks.forEach((task: AffectedTask) => {
			if (task.suggestedAssignment) {
				members.add(task.suggestedAssignment)
			}
		})
	})
	return members.size
})

// Methods
const loadSuggestions = async () => {
	if (!props.houseId) return
	
	loading.value = true
	try {
		const response = await axios.get<SuggestionsResponse>(`/api/v1/smart-schedule/suggestions`)
		
		if (response.data.success) {
			const payload = response.data.data

			if (Array.isArray(payload)) {
				suggestions.value = payload.map((suggestion: SchedulingSuggestion, index: number) => ({
					...suggestion,
					id: suggestion.id ?? index + 1,
					selected: false,
				}))
			} else {
				suggestions.value = Object.entries(payload ?? {}).map(([day, info], index: number) => ({
					id: index + 1,
					type: 'capacity',
					priority: info.recommendedTasks > 0 ? 'medium' : 'low',
					title: t('chorestrike', 'Capacity suggestion for {day}', { day }),
					description: t('chorestrike', '{members} members available with total energy {energy}', {
						members: String(info.availableMembers),
						energy: String(info.totalEnergy),
					}),
					impact: {
						efficiency: info.recommendedTasks * 2,
						fairness: 1,
					},
					affectedTasks: [],
					selected: false,
				}))
			}
		} else {
			throw new Error(response.data.error || 'Failed to load suggestions')
		}
	} catch (error) {
		console.error('Failed to load suggestions:', error)
		// Show empty state on error
		suggestions.value = []
	} finally {
		loading.value = false
	}
}

const refreshSuggestions = () => {
	loadSuggestions()
}

const filterSuggestions = () => {
	// Filtering is handled by computed property
}

const getSuggestionIcon = (type: SuggestionType): string => {
	const icons = {
		reassignment: 'Users',
		rescheduling: 'Calendar',
		capacity: 'Battery',
		fairness: 'Scale'
	} as Record<SuggestionType, string>
	return icons[type] || 'Lightbulb'
}

const getSuggestionTypeLabel = (type: SuggestionType): string => {
	const labels = {
		reassignment: t('chorestrike', 'Reassignment'),
		rescheduling: t('chorestrike', 'Rescheduling'),
		capacity: t('chorestrike', 'Capacity'),
		fairness: t('chorestrike', 'Fairness')
	} as Record<SuggestionType, string>
	return labels[type] || t('chorestrike', 'General')
}

const getPriorityLabel = (priority: SuggestionPriority): string => {
	const labels = {
		high: t('chorestrike', 'High'),
		medium: t('chorestrike', 'Medium'),
		low: t('chorestrike', 'Low')
	} as Record<SuggestionPriority, string>
	return labels[priority] || t('chorestrike', 'Unknown')
}

const getImpactClass = (value: number): string => {
	return value > 0 ? 'positive' : value < 0 ? 'negative' : 'neutral'
}

const getTaskChangeDescription = (task: AffectedTask): string => {
	if (task.currentAssignment === task.suggestedAssignment) {
		return t('chorestrike', 'No change')
	}
	if (!task.currentAssignment) {
		const suggested = task.suggestedAssignment ?? t('chorestrike', 'Unknown')
		return t('chorestrike', 'Assign to {name}', { name: suggested })
	}
	const from = task.currentAssignment
	const to = task.suggestedAssignment ?? t('chorestrike', 'Unknown')
	return t('chorestrike', 'Move from {from} to {to}', { 
		from,
		to
	})
}

const applySuggestion = async (suggestion: SchedulingSuggestion | null): Promise<void> => {
	if (!suggestion) {
		return
	}

	try {
		// No dedicated backend endpoint exists yet; keep UI behavior consistent for now.
		emit('suggestionApplied', suggestion)
		suggestions.value = suggestions.value.filter((s: SchedulingSuggestion) => s.id !== suggestion.id)
	} catch (error) {
		console.error('Failed to apply suggestion:', error)
	}
}

const dismissSuggestion = (suggestion: SchedulingSuggestion) => {
	suggestions.value = suggestions.value.filter((s: SchedulingSuggestion) => s.id !== suggestion.id)
}

const showSuggestionDetails = (suggestion: SchedulingSuggestion) => {
	selectedSuggestion.value = suggestion
	showDetailsModal.value = true
}

const closeDetailsModal = () => {
	showDetailsModal.value = false
	selectedSuggestion.value = null
}

const applySelectedSuggestions = async () => {
	for (const suggestion of selectedSuggestions.value) {
		await applySuggestion(suggestion)
	}
	selectedSuggestions.value = []
}

const dismissAllSuggestions = () => {
	suggestions.value = []
	selectedSuggestions.value = []
}

// Lifecycle
onMounted(() => {
	if (props.houseId) {
		loadSuggestions()
	}
})
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

.card-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 48px;
	height: 48px;
	background: var(--color-primary);
	color: var(--color-primary-text);
	border-radius: 8px;
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

.filter-controls {
	display: flex;
	align-items: center;
	gap: 1rem;
	margin-bottom: 2rem;
	flex-wrap: wrap;
}

.filter-group {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.filter-group label {
	font-weight: 500;
	color: var(--color-main-text);
}

.filter-group select {
	padding: 0.5rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.refresh-button {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.5rem 1rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	transition: all 0.2s ease;
}

.refresh-button:hover:not(:disabled) {
	background: var(--color-background-dark);
}

.refresh-button:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.suggestions-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
	gap: 1.5rem;
	margin-bottom: 2rem;
}

.suggestion-card {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 1.5rem;
	background: var(--color-main-background);
	transition: all 0.2s ease;
}

.suggestion-card:hover {
	border-color: var(--color-primary);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.suggestion-card.priority-high {
	border-left: 4px solid #f44336;
}

.suggestion-card.priority-medium {
	border-left: 4px solid #ff9800;
}

.suggestion-card.priority-low {
	border-left: 4px solid #4caf50;
}

.suggestion-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 1rem;
}

.suggestion-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 40px;
	height: 40px;
	background: var(--color-primary);
	color: var(--color-primary-text);
	border-radius: 8px;
	flex-shrink: 0;
}

.suggestion-title {
	flex: 1;
	margin: 0 1rem;
}

.suggestion-title h3 {
	font-size: 1.1rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.25rem;
}

.suggestion-type {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.suggestion-priority {
	flex-shrink: 0;
}

.priority-badge {
	padding: 0.25rem 0.5rem;
	border-radius: 4px;
	font-size: 0.7rem;
	font-weight: 500;
	text-transform: uppercase;
}

.priority-badge.priority-high {
	background: rgba(244, 67, 54, 0.2);
	color: #f44336;
}

.priority-badge.priority-medium {
	background: rgba(255, 152, 0, 0.2);
	color: #ff9800;
}

.priority-badge.priority-low {
	background: rgba(76, 175, 80, 0.2);
	color: #4caf50;
}

.suggestion-content {
	margin-bottom: 1.5rem;
}

.suggestion-description {
	color: var(--color-main-text);
	margin-bottom: 1rem;
	line-height: 1.5;
}

.impact-analysis {
	margin-bottom: 1rem;
}

.impact-analysis h4 {
	font-size: 0.9rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.5rem;
}

.impact-metrics {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.metric {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.metric-label {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.metric-value {
	font-weight: 500;
}

.metric-value.positive {
	color: #4caf50;
}

.metric-value.negative {
	color: #f44336;
}

.metric-value.neutral {
	color: var(--color-main-text);
}

.affected-tasks h4 {
	font-size: 0.9rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.5rem;
}

.task-list {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.task-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.5rem;
	background: var(--color-background-dark);
	border-radius: 4px;
}

.task-title {
	font-size: 0.8rem;
	color: var(--color-main-text);
}

.task-change {
	font-size: 0.7rem;
	color: var(--color-text-maxcontrast);
}

.more-tasks {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.suggestion-actions {
	display: flex;
	gap: 0.5rem;
}

.action-button {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.5rem 1rem;
	border: none;
	border-radius: 6px;
	font-size: 0.8rem;
	font-weight: 500;
	cursor: pointer;
	transition: all 0.2s ease;
}

.action-button.apply {
	background: var(--color-primary);
	color: var(--color-primary-text);
}

.action-button.apply:hover {
	background: var(--color-primary-hover);
}

.action-button.details {
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
}

.action-button.details:hover {
	background: var(--color-background-dark);
}

.action-button.dismiss {
	background: var(--color-error);
	color: var(--color-error-text);
}

.action-button.dismiss:hover {
	background: var(--color-error-hover);
}

.batch-actions {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 1rem;
	background: var(--color-background-dark);
	border-radius: 8px;
}

.batch-info {
	font-size: 0.9rem;
	color: var(--color-main-text);
}

.batch-buttons {
	display: flex;
	gap: 1rem;
}

.batch-button {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.75rem 1.5rem;
	border: none;
	border-radius: 6px;
	font-weight: 500;
	cursor: pointer;
	transition: all 0.2s ease;
}

.batch-button.apply-all {
	background: var(--color-primary);
	color: var(--color-primary-text);
}

.batch-button.apply-all:hover:not(:disabled) {
	background: var(--color-primary-hover);
}

.batch-button.apply-all:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.batch-button.dismiss-all {
	background: var(--color-error);
	color: var(--color-error-text);
}

.batch-button.dismiss-all:hover {
	background: var(--color-error-hover);
}

.empty-state {
	text-align: center;
	padding: 3rem 0;
}

.empty-icon {
	color: var(--color-text-maxcontrast);
	margin-bottom: 1rem;
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

.primary-button:hover {
	background: var(--color-primary-hover);
}

/* Modal Styles */
.suggestion-details {
	display: flex;
	flex-direction: column;
	gap: 2rem;
}

.detail-header {
	display: flex;
	align-items: center;
	gap: 1rem;
}

.detail-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 48px;
	height: 48px;
	background: var(--color-primary);
	color: var(--color-primary-text);
	border-radius: 8px;
}

.detail-title h3 {
	font-size: 1.3rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.25rem;
}

.detail-type {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.detail-description p {
	color: var(--color-main-text);
	line-height: 1.6;
}

.detail-impact h4,
.detail-tasks h4 {
	font-size: 1.1rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 1rem;
}

.impact-chart {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.chart-item {
	display: flex;
	align-items: center;
	gap: 1rem;
}

.chart-label {
	min-width: 80px;
	font-size: 0.9rem;
	color: var(--color-main-text);
}

.chart-bar {
	flex: 1;
	display: flex;
	align-items: center;
	height: 20px;
	background: var(--color-border);
	border-radius: 10px;
	overflow: hidden;
	position: relative;
}

.chart-fill {
	height: 100%;
	transition: width 0.3s ease;
}

.chart-fill.positive {
	background: #4caf50;
}

.chart-fill.negative {
	background: #f44336;
}

.chart-value {
	position: absolute;
	right: 8px;
	font-size: 0.8rem;
	font-weight: 500;
	color: var(--color-main-text);
}

.task-details-list {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.task-detail-item {
	padding: 1rem;
	background: var(--color-background-dark);
	border-radius: 6px;
}

.task-info {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 0.75rem;
}

.task-title {
	font-weight: 500;
	color: var(--color-main-text);
}

.task-priority {
	padding: 0.25rem 0.5rem;
	border-radius: 4px;
	font-size: 0.7rem;
	font-weight: 500;
	background: rgba(33, 150, 243, 0.2);
	color: #2196f3;
}

.task-changes {
	display: flex;
	gap: 2rem;
}

.change-item {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.change-label {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.change-value {
	font-weight: 500;
	color: var(--color-main-text);
}

.modal-footer {
	display: flex;
	justify-content: flex-end;
	gap: 1rem;
}

.secondary-button, .primary-button {
	padding: 0.75rem 1.5rem;
	border-radius: 6px;
	font-weight: 500;
	cursor: pointer;
	transition: all 0.2s ease;
}

.secondary-button {
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
}

.secondary-button:hover {
	background: var(--color-background-dark);
}

.primary-button {
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
}

.primary-button:hover {
	background: var(--color-primary-hover);
}

@media (max-width: 768px) {
	.summary-cards {
		grid-template-columns: 1fr;
	}
	
	.filter-controls {
		flex-direction: column;
		align-items: flex-start;
	}
	
	.suggestions-grid {
		grid-template-columns: 1fr;
	}
	
	.suggestion-header {
		flex-direction: column;
		gap: 1rem;
	}
	
	.suggestion-title {
		margin: 0;
	}
	
	.batch-actions {
		flex-direction: column;
		gap: 1rem;
		align-items: flex-start;
	}
	
	.batch-buttons {
		width: 100%;
		justify-content: space-between;
	}
	
	.task-changes {
		flex-direction: column;
		gap: 0.5rem;
	}
}
</style>
