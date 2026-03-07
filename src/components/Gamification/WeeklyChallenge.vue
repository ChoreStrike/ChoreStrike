<template>
	<div class="weekly-challenge" data-testid="weekly-challenge">
		<div class="challenge-header">
			<h3>{{ t('chorestrike', 'Weekly Challenge') }}</h3>
			<div class="challenge-difficulty" :class="`difficulty-${challenge.difficulty}`">
				{{ getDifficultyLabel(challenge.difficulty) }}
			</div>
		</div>

		<div class="challenge-content">
			<div class="challenge-icon">{{ challenge.icon }}</div>
			<div class="challenge-info">
				<div class="challenge-title">{{ challenge.title }}</div>
				<div class="challenge-description">{{ challenge.description }}</div>
				<div class="challenge-reward">
					<span class="reward-label">{{ t('chorestrike', 'Reward:') }}</span>
					<span class="reward-value">+{{ challenge.reward }} {{ t('chorestrike', 'points') }}</span>
				</div>
			</div>
		</div>

		<div class="challenge-progress">
			<div class="progress-header">
				<span class="progress-text">
					{{ t('chorestrike', '{current} of {target}', { current: challenge.progress, target: challenge.target }) }}
				</span>
				<span class="progress-percentage">{{ getProgressPercentage() }}%</span>
			</div>
			<div class="progress-bar">
				<div
					class="progress-fill"
					:style="{ width: getProgressPercentage() + '%' }"
					:class="{ complete: challenge.progress >= challenge.target }" />
			</div>
		</div>

		<div class="challenge-actions">
			<button
				v-if="challenge.progress >= challenge.target && !challenge.claimed"
				class="claim-button"
				@click="claimReward"
				:disabled="claiming">
				{{ claiming ? t('chorestrike', 'Claiming...') : t('chorestrike', 'Claim Reward') }}
			</button>
			<div v-else-if="challenge.claimed" class="completed-badge">
				<Icon name="Check" :size="16" />
				{{ t('chorestrike', 'Completed') }}
			</div>
			<div v-else class="time-remaining">
				⏰ {{ formatTimeRemaining() }}
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import Icon from '../Common/Icon.vue'
import axios from '@nextcloud/axios'
import { logger } from '../../utils/logger.js'

interface Challenge {
	id: string
	title: string
	description: string
	icon: string
	target: number
	progress: number
	reward: number
	difficulty: 'easy' | 'medium' | 'hard'
	claimed: boolean
	expiresAt: string
}

const props = defineProps<{
	userId: string
}>()

const loading = ref(false)
const claiming = ref(false)

const challenge = ref<Challenge>({
	id: '',
	title: '',
	description: '',
	icon: '🎯',
	target: 10,
	progress: 0,
	reward: 100,
	difficulty: 'medium',
	claimed: false,
	expiresAt: '',
})

const loadChallenge = async () => {
	loading.value = true
	try {
		const response = await axios.get(`/api/v1/game/challenge/${props.userId}`)
		if (response.data.success) {
			challenge.value = response.data.data
		}
	} catch (error) {
		logger.error('Failed to load challenge', error, { component: 'WeeklyChallenge', action: 'loadChallenge', userId: props.userId })
	} finally {
		loading.value = false
	}
}

const claimReward = async () => {
	claiming.value = true
	try {
		const response = await axios.post(`/api/v1/game/challenge/${challenge.value.id}/claim`)
		if (response.data.success) {
			challenge.value.claimed = true
		}
	} catch (error) {
		logger.error('Failed to claim reward', error, { component: 'WeeklyChallenge', action: 'claimReward', challengeId: challenge.value.id, userId: props.userId })
	} finally {
		claiming.value = false
	}
}

const getProgressPercentage = (): number => {
	return Math.min((challenge.value.progress / challenge.value.target) * 100, 100)
}

const getDifficultyLabel = (difficulty: string): string => {
	const labels: Record<string, string> = {
		easy: t('chorestrike', 'Easy'),
		medium: t('chorestrike', 'Medium'),
		hard: t('chorestrike', 'Hard'),
	}
	return labels[difficulty] || difficulty
}

const formatTimeRemaining = (): string => {
	// Placeholder - would calculate actual time remaining
	return '2d 14h'
}

onMounted(() => {
	loadChallenge()
})
</script>

<style scoped>
.weekly-challenge {
	background: var(--color-main-background);
	border-radius: 12px;
	padding: 1.5rem;
	border: 2px solid var(--color-border);
	transition: all 0.2s ease;
}

.weekly-challenge:hover {
	border-color: var(--color-primary);
}

.challenge-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 1rem;
}

.challenge-header h3 {
	font-size: 1.1rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0;
}

.challenge-difficulty {
	padding: 0.25rem 0.75rem;
	border-radius: 4px;
	font-size: 0.75rem;
	font-weight: 600;
	text-transform: uppercase;
}

.challenge-difficulty.difficulty-easy {
	background: #e8f5e9;
	color: #2e7d32;
}

.challenge-difficulty.difficulty-medium {
	background: #fff3cd;
	color: #f57c00;
}

.challenge-difficulty.difficulty-hard {
	background: #ffccbc;
	color: #d84315;
}

.challenge-content {
	display: flex;
	gap: 1rem;
	margin-bottom: 1.5rem;
}

.challenge-icon {
	font-size: 3rem;
	line-height: 1;
}

.challenge-info {
	flex: 1;
}

.challenge-title {
	font-size: 1.1rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.5rem;
}

.challenge-description {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
	margin-bottom: 0.75rem;
}

.challenge-reward {
	display: flex;
	gap: 0.5rem;
	align-items: center;
}

.reward-label {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.reward-value {
	font-size: 0.9rem;
	font-weight: 600;
	color: var(--color-primary);
}

.challenge-progress {
	margin-bottom: 1.5rem;
}

.progress-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 0.5rem;
}

.progress-text {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.progress-percentage {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.progress-bar {
	height: 10px;
	background: var(--color-background-dark);
	border-radius: 5px;
	overflow: hidden;
}

.progress-fill {
	height: 100%;
	background: linear-gradient(90deg, var(--color-primary), var(--color-primary-light));
	transition: width 0.3s ease;
}

.progress-fill.complete {
	background: linear-gradient(90deg, #4caf50, #8bc34a);
}

.challenge-actions {
	display: flex;
	justify-content: center;
}

.claim-button {
	padding: 0.75rem 1.5rem;
	background: var(--color-primary);
	color: white;
	border: none;
	border-radius: 4px;
	font-weight: 600;
	cursor: pointer;
	transition: all 0.2s ease;
}

.claim-button:hover:not(:disabled) {
	background: var(--color-primary-dark);
	transform: translateY(-1px);
}

.claim-button:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.completed-badge {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.5rem 1rem;
	background: #e8f5e9;
	color: #2e7d32;
	border-radius: 4px;
	font-weight: 600;
}

.time-remaining {
	padding: 0.5rem 1rem;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

@media (max-width: 768px) {
	.challenge-content {
		flex-direction: column;
		align-items: center;
		text-align: center;
	}

	.challenge-icon {
		font-size: 2.5rem;
	}
}
</style>
