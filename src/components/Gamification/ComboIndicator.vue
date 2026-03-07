<template>
	<div class="combo-indicator" data-testid="combo-indicator">
		<div v-if="comboProgress.hasCombo" class="combo-active">
			<div class="combo-header">
				<div class="combo-title">
					<span class="combo-icon">⚡</span>
					{{ t('chorestrike', 'Combo Active') }}
				</div>
				<div class="combo-timer">
					{{ formatTimeRemaining() }}
				</div>
			</div>

			<div class="combo-progress">
				<div class="progress-bar">
					<div
						class="progress-fill"
						:style="{ width: getProgressPercentage() + '%' }" />
				</div>
				<div class="progress-text">
					{{ comboProgress.taskCount }} {{ t('chorestrike', 'tasks') }}
				</div>
			</div>

			<div class="combo-stats">
				<div class="stat">
					<span class="stat-label">{{ t('chorestrike', 'Bonus Points') }}</span>
					<span class="stat-value">+{{ comboProgress.bonusPoints }}</span>
				</div>
				<div class="stat">
					<span class="stat-label">{{ t('chorestrike', 'Multiplier') }}</span>
					<span class="stat-value">{{ comboProgress.multiplier.toFixed(1) }}x</span>
				</div>
			</div>

			<div v-if="comboProgress.timedOut" class="combo-warning">
				{{ t('chorestrike', 'Combo will expire soon!') }}
			</div>
		</div>

		<div v-else class="combo-inactive">
			<div class="combo-empty">
				<span class="empty-icon">⚡</span>
				<p>{{ t('chorestrike', 'No active combo') }}</p>
				<p class="hint">
					{{ t('chorestrike', 'Complete tasks to start a combo') }}
				</p>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { logger } from '../../utils/logger.js'

interface ComboProgress {
	hasCombo: boolean
	taskCount: number
	bonusPoints: number
	multiplier: number
	timedOut: boolean
}

const props = defineProps<{
	userId: string
}>()

const comboProgress = ref<ComboProgress>({
	hasCombo: false,
	taskCount: 0,
	bonusPoints: 0,
	multiplier: 1.0,
	timedOut: false,
})

let refreshInterval: ReturnType<typeof setInterval> | null = null

const loadComboProgress = async (): Promise<void> => {
	try {
		const response = await axios.get(`/api/v1/game/combo/${props.userId}`)
		if (response.data.success) {
			comboProgress.value = response.data.data
		}
	} catch (error) {
		logger.error('Failed to load combo progress', error, { component: 'ComboIndicator', action: 'loadComboProgress', userId: props.userId })
	}
}

const getProgressPercentage = (): number => {
	const maxTasks = 10
	return Math.min((comboProgress.value.taskCount / maxTasks) * 100, 100)
}

const formatTimeRemaining = (): string => {
	// Placeholder - would need actual timer data from backend
	return '23:45:30'
}

onMounted(() => {
	loadComboProgress()
	// Refresh combo progress every 30 seconds
	refreshInterval = setInterval(loadComboProgress, 30000)
})

onUnmounted(() => {
	if (refreshInterval) {
		clearInterval(refreshInterval)
	}
})
</script>

<style scoped>
.combo-indicator {
	background: var(--color-main-background);
	border-radius: 8px;
	padding: 1rem;
	border: 2px solid var(--color-border);
}

.combo-active {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.combo-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.combo-title {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	font-weight: 600;
	font-size: 1.1rem;
	color: var(--color-primary);
}

.combo-icon {
	font-size: 1.5rem;
	animation: pulse 1s ease-in-out infinite;
}

@keyframes pulse {
	0%, 100% { opacity: 1; }
	50% { opacity: 0.6; }
}

.combo-timer {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
	font-family: monospace;
}

.combo-progress {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.progress-bar {
	height: 8px;
	background: var(--color-background-dark);
	border-radius: 4px;
	overflow: hidden;
}

.progress-fill {
	height: 100%;
	background: linear-gradient(90deg, #4caf50, #8bc34a);
	transition: width 0.3s ease;
}

.progress-text {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.combo-stats {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 1rem;
}

.stat {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	padding: 0.75rem;
	background: var(--color-background-dark);
	border-radius: 4px;
	text-align: center;
}

.stat-label {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.stat-value {
	font-size: 1.2rem;
	font-weight: 600;
	color: var(--color-primary);
}

.combo-warning {
	padding: 0.75rem;
	background: #fff3cd;
	border: 1px solid #ffc107;
	border-radius: 4px;
	color: #856404;
	font-size: 0.9rem;
	text-align: center;
}

.combo-inactive {
	display: flex;
	align-items: center;
	justify-content: center;
	min-height: 120px;
}

.combo-empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.empty-icon {
	font-size: 2rem;
	display: block;
	margin-bottom: 0.5rem;
	opacity: 0.5;
}

.combo-empty p {
	margin: 0.25rem 0;
}

.hint {
	font-size: 0.85rem;
	opacity: 0.7;
}

@media (max-width: 768px) {
	.combo-indicator {
		padding: 0.75rem;
	}

	.combo-stats {
		grid-template-columns: 1fr;
	}

	.combo-title {
		font-size: 1rem;
	}
}
</style>
