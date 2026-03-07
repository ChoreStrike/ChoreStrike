<template>
	<div class="game-stats" data-testid="game-stats">
		<div class="stats-header">
			<h2>{{ t('chorestrike', 'Game Statistics') }}</h2>
		</div>

		<div class="stats-grid">
			<div class="stat-card total-points">
				<div class="stat-icon">⭐</div>
				<div class="stat-value">{{ stats.totalPoints }}</div>
				<div class="stat-label">{{ t('chorestrike', 'Total Points') }}</div>
			</div>

			<div class="stat-card tasks-completed">
				<div class="stat-icon">✅</div>
				<div class="stat-value">{{ stats.tasksCompleted }}</div>
				<div class="stat-label">{{ t('chorestrike', 'Tasks Completed') }}</div>
			</div>

			<div class="stat-card current-streak">
				<div class="stat-icon">🔥</div>
				<div class="stat-value">{{ stats.currentStreak }}</div>
				<div class="stat-label">{{ t('chorestrike', 'Current Streak') }}</div>
				<div v-if="stats.longestStreak > 0" class="stat-sublabel">
					{{ t('chorestrike', 'Best: {count}', { count: stats.longestStreak }) }}
				</div>
			</div>

			<div class="stat-card combos">
				<div class="stat-icon">⚡</div>
				<div class="stat-value">{{ stats.totalCombos }}</div>
				<div class="stat-label">{{ t('chorestrike', 'Combos') }}</div>
				<div v-if="stats.comboBonusPoints > 0" class="stat-sublabel">
					+{{ stats.comboBonusPoints }} {{ t('chorestrike', 'bonus points') }}
				</div>
			</div>

			<div class="stat-card medals">
				<div class="stat-icon">🏅</div>
				<div class="stat-value">{{ stats.totalMedals }}</div>
				<div class="stat-label">{{ t('chorestrike', 'Medals Earned') }}</div>
				<div v-if="stats.medalPoints > 0" class="stat-sublabel">
					+{{ stats.medalPoints }} {{ t('chorestrike', 'points') }}
				</div>
			</div>

			<div class="stat-card rank">
				<div class="stat-icon">🎯</div>
				<div class="stat-value">{{ stats.rank }}</div>
				<div class="stat-label">{{ t('chorestrike', 'Rank') }}</div>
				<div v-if="stats.nextRankPoints > 0" class="stat-sublabel">
					{{ t('chorestrike', '{points} to next', { points: stats.nextRankPoints }) }}
				</div>
			</div>
		</div>

		<div class="weekly-section">
			<h3>{{ t('chorestrike', 'This Week') }}</h3>
			<div class="weekly-stats">
				<div class="weekly-stat">
					<span class="weekly-label">{{ t('chorestrike', 'Tasks') }}</span>
					<span class="weekly-value">{{ weeklyStats.tasksThisWeek }}</span>
				</div>
				<div class="weekly-stat">
					<span class="weekly-label">{{ t('chorestrike', 'Points') }}</span>
					<span class="weekly-value">{{ weeklyStats.pointsThisWeek }}</span>
				</div>
				<div class="weekly-stat">
					<span class="weekly-label">{{ t('chorestrike', 'Medals') }}</span>
					<span class="weekly-value">{{ weeklyStats.medalsThisWeek }}</span>
				</div>
			</div>
			<div class="weekly-progress">
				<div class="progress-bar">
					<div
						class="progress-fill"
						:style="{ width: getWeeklyProgress() + '%' }" />
				</div>
				<div class="progress-label">
					{{ t('chorestrike', '{completed} of {goal} weekly goal', { completed: weeklyStats.tasksThisWeek, goal: 10 }) }}
				</div>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { logger } from '../../utils/logger.js'

interface GameStats {
	totalPoints: number
	tasksCompleted: number
	currentStreak: number
	longestStreak: number
	totalCombos: number
	comboBonusPoints: number
	totalMedals: number
	medalPoints: number
	rank: string
	nextRankPoints: number
}

interface WeeklyStats {
	tasksThisWeek: number
	pointsThisWeek: number
	medalsThisWeek: number
}

const props = defineProps<{
	userId: string
}>()

const loading = ref(false)
const stats = ref<GameStats>({
	totalPoints: 0,
	tasksCompleted: 0,
	currentStreak: 0,
	longestStreak: 0,
	totalCombos: 0,
	comboBonusPoints: 0,
	totalMedals: 0,
	medalPoints: 0,
	rank: 'Novice',
	nextRankPoints: 50,
})

const weeklyStats = ref<WeeklyStats>({
	tasksThisWeek: 0,
	pointsThisWeek: 0,
	medalsThisWeek: 0,
})

const loadStats = async () => {
	loading.value = true
	try {
		const response = await axios.get(`/api/v1/game/stats/${props.userId}`)
		if (response.data.success) {
			stats.value = response.data.data.stats
			weeklyStats.value = response.data.data.weekly
		}
	} catch (error) {
		logger.error('Failed to load game stats', error, { component: 'GameStats', action: 'loadStats', userId: props.userId })
	} finally {
		loading.value = false
	}
}

const getWeeklyProgress = (): number => {
	const goal = 10
	return Math.min((weeklyStats.value.tasksThisWeek / goal) * 100, 100)
}

onMounted(() => {
	loadStats()
})
</script>

<style scoped>
.game-stats {
	background: var(--color-main-background);
	border-radius: 12px;
	padding: 1.5rem;
	border: 1px solid var(--color-border);
}

.stats-header {
	margin-bottom: 1.5rem;
}

.stats-header h2 {
	font-size: 1.5rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0;
}

.stats-grid {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 1rem;
	margin-bottom: 2rem;
}

.stat-card {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 1rem;
	background: var(--color-background-dark);
	border-radius: 8px;
	border: 2px solid transparent;
	transition: all 0.2s ease;
}

.stat-card:hover {
	border-color: var(--color-primary);
	transform: translateY(-2px);
}

.stat-card.total-points {
	background: linear-gradient(135deg, #fff9c4, var(--color-background-dark));
}

.stat-card.current-streak {
	background: linear-gradient(135deg, #ffccbc, var(--color-background-dark));
}

.stat-card.combos {
	background: linear-gradient(135deg, #e8f5e9, var(--color-background-dark));
}

.stat-icon {
	font-size: 1.5rem;
	margin-bottom: 0.5rem;
}

.stat-value {
	font-size: 1.75rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.stat-label {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.stat-sublabel {
	font-size: 0.75rem;
	color: var(--color-primary);
	margin-top: 0.25rem;
}

.weekly-section {
	border-top: 1px solid var(--color-border);
	padding-top: 1.5rem;
}

.weekly-section h3 {
	font-size: 1.1rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0 0 1rem 0;
}

.weekly-stats {
	display: flex;
	gap: 2rem;
	margin-bottom: 1rem;
}

.weekly-stat {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.weekly-label {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.weekly-value {
	font-size: 1.25rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.weekly-progress {
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
	background: linear-gradient(90deg, var(--color-primary), var(--color-primary-light));
	transition: width 0.3s ease;
}

.progress-label {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

@media (max-width: 768px) {
	.stats-grid {
		grid-template-columns: repeat(2, 1fr);
	}

	.weekly-stats {
		gap: 1rem;
	}
}

@media (max-width: 480px) {
	.stats-grid {
		grid-template-columns: 1fr;
	}
}
</style>
