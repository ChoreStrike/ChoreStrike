<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="schedule-preview" data-testid="schedule-preview">
		<div class="preview-header">
			<h2>{{ t('chorestrike', 'Schedule Preview') }}</h2>
			<div class="preview-controls">
				<div class="days-selector">
					<label for="preview-days">{{ t('chorestrike', 'Preview Days') }}:</label>
					<select
						id="preview-days"
						v-model="previewDays"
						data-testid="preview-days-select"
						@change="loadPreview">
						<option value="3">
							3 {{ t('chorestrike', 'days') }}
						</option>
						<option value="7">
							7 {{ t('chorestrike', 'days') }}
						</option>
						<option value="14">
							14 {{ t('chorestrike', 'days') }}
						</option>
						<option value="30">
							30 {{ t('chorestrike', 'days') }}
						</option>
					</select>
				</div>
				<button
					class="generate-button"
					:disabled="loading || !canGenerate"
					data-testid="generate-schedule-button"
					@click="generateSchedule">
					<Icon name="Calendar" :size="16" />
					{{ t('chorestrike', 'Generate Schedule') }}
				</button>
			</div>
		</div>

		<!-- Loading State -->
		<div v-if="loading" class="loading-state">
			<div class="loading-spinner" />
			<p>{{ t('chorestrike', 'Analyzing tasks and generating optimal schedule...') }}</p>
		</div>

		<!-- Preview Results -->
		<div v-else-if="preview" class="preview-content">
			<!-- Summary Cards -->
			<div class="summary-cards">
				<div class="summary-card">
					<div class="card-icon">
						<Icon name="Task" :size="24" />
					</div>
					<div class="card-content">
						<h3>{{ preview.taskCount }}</h3>
						<p>{{ t('chorestrike', 'Unscheduled Tasks') }}</p>
					</div>
				</div>
				<div class="summary-card">
					<div class="card-icon">
						<Icon name="Users" :size="24" />
					</div>
					<div class="card-content">
						<h3>{{ preview.memberCount }}</h3>
						<p>{{ t('chorestrike', 'Available Members') }}</p>
					</div>
				</div>
				<div class="summary-card">
					<div class="card-icon">
						<Icon name="Battery" :size="24" />
					</div>
					<div class="card-content">
						<h3>{{ totalDailyCapacity }}</h3>
						<p>{{ t('chorestrike', 'Avg Daily Capacity') }}</p>
					</div>
				</div>
			</div>

			<!-- Daily Capacity Chart -->
			<div class="capacity-chart">
				<h3>{{ t('chorestrike', 'Daily Capacity Overview') }}</h3>
				<div class="chart-container">
					<div
						v-for="(capacity, date) in preview.dailyCapacity"
						:key="date"
						class="capacity-bar">
						<div class="date-label">
							{{ formatDate(date) }}
						</div>
						<div class="capacity-bar-container">
							<div
								class="capacity-fill"
								:style="{ width: `${Math.min((capacity / maxCapacity) * 100, 100)}%` }" />
							<span class="capacity-value">{{ capacity }}</span>
						</div>
					</div>
				</div>
			</div>

			<!-- Conflicts and Warnings -->
			<div v-if="preview.potentialConflicts.length > 0" class="conflicts-section">
				<h3>{{ t('chorestrike', 'Potential Conflicts') }}</h3>
				<div class="conflicts-list">
					<div
						v-for="conflict in preview.potentialConflicts"
						:key="conflict.type"
						:class="['conflict-item', `severity-${conflict.severity}`]">
						<div class="conflict-icon">
							<Icon :name="conflict.severity === 'high' ? 'Alert' : 'Warning'" :size="20" />
						</div>
						<div class="conflict-content">
							<p>{{ conflict.message }}</p>
						</div>
					</div>
				</div>
			</div>

			<!-- Generated Schedule Results -->
			<div v-if="generatedSchedule" class="schedule-results">
				<h3>{{ t('chorestrike', 'Generated Schedule') }}</h3>
				<div class="schedule-summary">
					<div class="summary-item">
						<span class="label">{{ t('chorestrike', 'Tasks Assigned') }}:</span>
						<span class="value">{{ generatedSchedule.assignedTasks }} / {{ generatedSchedule.totalTasks }}</span>
					</div>
					<div class="summary-item">
						<span class="label">{{ t('chorestrike', 'Efficiency') }}:</span>
						<span class="value">{{ Math.round(generatedSchedule.efficiency * 100) }}%</span>
					</div>
					<div class="summary-item">
						<span class="label">{{ t('chorestrike', 'Unassigned Tasks') }}:</span>
						<span class="value">{{ generatedSchedule.unassignedTasks.length }}</span>
					</div>
				</div>
			</div>
		</div>

		<!-- Empty State -->
		<div v-else class="empty-state">
			<div class="empty-icon">
				<Icon name="Calendar" :size="48" />
			</div>
			<h3>{{ t('chorestrike', 'No Preview Available') }}</h3>
			<p>{{ t('chorestrike', 'Generate a preview to see scheduling capacity and potential conflicts') }}</p>
			<button class="primary-button" @click="loadPreview">
				{{ t('chorestrike', 'Load Preview') }}
			</button>
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import Icon from '../Common/Icon.vue'
import axios from '@nextcloud/axios'
import { logger } from '../../utils/logger.js'

// Props
const props = defineProps<{
	houseId: number
}>()

// Emits
const emit = defineEmits<{
	'schedule-generated': [schedule: any]
	'conflict-detected': [conflicts: any[]]
}>()

// Reactive state
const loading = ref(false)
const previewDays = ref(7)
const preview = ref(null)
const generatedSchedule = ref(null)

// Computed properties
const totalDailyCapacity = computed(() => {
	if (!preview.value?.dailyCapacity) return 0
	const capacities = Object.values(preview.value.dailyCapacity)
	return Math.round(capacities.reduce((sum, cap) => sum + cap, 0) / capacities.length)
})

const maxCapacity = computed(() => {
	if (!preview.value?.dailyCapacity) return 0
	return Math.max(...Object.values(preview.value.dailyCapacity))
})

const canGenerate = computed(() => {
	return preview.value && preview.value.taskCount > 0 && preview.value.memberCount > 0
})

// Methods
const loadPreview = async () => {
	if (!props.houseId) return

	loading.value = true
	try {
		const response = await axios.get('/api/v1/smart-schedule/preview', {
			params: { days: previewDays.value },
		})

		if (response.data.success) {
			preview.value = response.data.data
			generatedSchedule.value = null // Clear previous schedule

			// Emit conflicts if any
			if (preview.value.potentialConflicts.length > 0) {
				emit('conflict-detected', preview.value.potentialConflicts)
			}
		} else {
			throw new Error(response.data.error || 'Failed to load preview')
		}
	} catch (error) {
		logger.error('Failed to load schedule preview', error, { component: 'SchedulePreview', action: 'loadPreview', houseId: props.houseId })
		// Handle error (could show toast notification)
	} finally {
		loading.value = false
	}
}

const generateSchedule = async () => {
	if (!props.houseId || !preview.value) return

	loading.value = true
	try {
		const startDate = new Date().toISOString().split('T')[0]
		const endDate = new Date(Date.now() + previewDays.value * 24 * 60 * 60 * 1000).toISOString().split('T')[0]

		const response = await axios.post('/api/v1/smart-schedule/generate', {
			startDate,
			endDate,
		})

		if (response.data.success) {
			generatedSchedule.value = response.data.data
			emit('schedule-generated', generatedSchedule.value)
		} else {
			throw new Error(response.data.error || 'Failed to generate schedule')
		}
	} catch (error) {
		logger.error('Failed to generate schedule', error, { component: 'SchedulePreview', action: 'generateSchedule', houseId: props.houseId })
		// Handle error
	} finally {
		loading.value = false
	}
}

const formatDate = (dateString: string) => {
	const date = new Date(dateString)
	return date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })
}

// Lifecycle
onMounted(() => {
	if (props.houseId) {
		loadPreview()
	}
})

watch(() => props.houseId, (newHouseId) => {
	if (newHouseId) {
		loadPreview()
	}
})
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

.preview-controls {
	display: flex;
	align-items: center;
	gap: 1rem;
	flex-wrap: wrap;
}

.days-selector {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.days-selector label {
	font-weight: 500;
	color: var(--color-main-text);
}

.days-selector select {
	padding: 0.5rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
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

.capacity-chart {
	margin-bottom: 2rem;
}

.capacity-chart h3 {
	font-size: 1.2rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 1rem;
}

.chart-container {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.capacity-bar {
	display: flex;
	align-items: center;
	gap: 1rem;
}

.date-label {
	min-width: 80px;
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.capacity-bar-container {
	flex: 1;
	display: flex;
	align-items: center;
	height: 24px;
	background: var(--color-border);
	border-radius: 12px;
	overflow: hidden;
	position: relative;
}

.capacity-fill {
	height: 100%;
	background: var(--color-primary);
	transition: width 0.3s ease;
}

.capacity-value {
	position: absolute;
	right: 8px;
	font-size: 0.8rem;
	font-weight: 500;
	color: var(--color-main-text);
	z-index: 1;
}

.conflicts-section {
	margin-bottom: 2rem;
}

.conflicts-section h3 {
	font-size: 1.2rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 1rem;
}

.conflicts-list {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.conflict-item {
	display: flex;
	align-items: flex-start;
	gap: 0.75rem;
	padding: 1rem;
	border-radius: 8px;
	border-left: 4px solid;
}

.conflict-item.severity-high {
	background: rgba(236, 64, 64, 0.1);
	border-left-color: #ec4040;
}

.conflict-item.severity-medium {
	background: rgba(245, 124, 0, 0.1);
	border-left-color: #f57c00;
}

.conflict-icon {
	color: var(--color-main-text);
}

.conflict-content p {
	margin: 0;
	color: var(--color-main-text);
}

.schedule-results {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 1.5rem;
}

.schedule-results h3 {
	font-size: 1.2rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 1rem;
}

.schedule-summary {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.summary-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.summary-item .label {
	color: var(--color-text-maxcontrast);
}

.summary-item .value {
	font-weight: 600;
	color: var(--color-main-text);
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

	.preview-controls {
		width: 100%;
		justify-content: space-between;
	}

	.summary-cards {
		grid-template-columns: 1fr;
	}

	.capacity-bar {
		flex-direction: column;
		align-items: flex-start;
		gap: 0.5rem;
	}

	.capacity-bar-container {
		width: 100%;
	}
}
</style>
