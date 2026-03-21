<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="household-progress" data-testid="household-progress">
		<div class="progress-header">
			<h3 class="progress-title">
				<Icon name="Users" :size="20" class="icon" />
				{{ t('chorestrike', 'Household Progress') }}
			</h3>
		</div>

		<div class="progress-grid">
			<!-- Total Coins -->
			<div class="progress-card team-coins">
				<div class="card-icon">
					⭐
				</div>
				<div class="card-value">
					{{ formatNumber(householdTotalCoins) }}
				</div>
				<div class="card-label">
					{{ t('chorestrike', 'Coins') }}
				</div>
			</div>

			<!-- Team Streak -->
			<div class="progress-card team-streak" :class="{ active: teamStreak > 0 }">
				<div class="card-icon">
					🔥
				</div>
				<div class="card-value">
					{{ teamStreak }}
				</div>
				<div class="card-label">
					{{ t('chorestrike', 'Team Streak') }}
				</div>
				<div v-if="teamStreak >= 7" class="card-badge">
					{{ t('chorestrike', 'On fire!') }}
				</div>
			</div>

			<!-- Weekly Goal -->
			<div class="progress-card weekly-goal" :class="{ achieved: isWeeklyGoalAchieved }">
				<div class="card-header">
					<span class="card-title">{{ t('chorestrike', 'Weekly Goal') }}</span>
					<span class="card-count">{{ weeklyGoalCompleted }}/{{ weeklyGoalTarget }}</span>
				</div>
				<div class="progress-bar-container">
					<div
						class="progress-bar-fill"
						:style="{ width: weeklyGoalProgress + '%' }" />
				</div>
				<div class="progress-percentage">
					{{ weeklyGoalProgress }}%
				</div>
				<div v-if="isWeeklyGoalAchieved" class="achievement-badge">
					✅ {{ t('chorestrike', 'Goal achieved!') }}
				</div>
			</div>
		</div>

		<!-- Celebration Banner -->
		<transition name="fade">
			<div v-if="pendingCelebration" class="celebration-banner" data-testid="celebration-banner">
				<div class="celebration-icon">
					🎉
				</div>
				<div class="celebration-content">
					<h4>{{ getCelebrationTitle(pendingCelebration.type) }}</h4>
					<p>{{ getCelebrationMessage(pendingCelebration.type) }}</p>
				</div>
				<button class="celebration-dismiss" @click="dismissCelebration(pendingCelebration.id)">
					<Icon name="Close" :size="16" />
				</button>
			</div>
		</transition>
	</div>
</template>

<script>
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useGameStore } from '../../store/game.js'
import Icon from '../Common/Icon.vue'

export default {
	name: 'HouseholdProgress',

	components: {
		Icon,
	},

	setup() {
		const gameStore = useGameStore()

		const householdTotalCoins = computed(() => gameStore.householdTotalCoins)
		const teamStreak = computed(() => gameStore.teamStreak)
		const weeklyGoalProgress = computed(() => gameStore.weeklyGoalProgress)
		const weeklyGoalCompleted = computed(() => gameStore.householdProgress.weeklyGoal.completed)
		const weeklyGoalTarget = computed(() => gameStore.householdProgress.weeklyGoal.target)
		const isWeeklyGoalAchieved = computed(() => gameStore.isWeeklyGoalAchieved)
		const pendingCelebration = computed(() => gameStore.pendingCelebrations[0] || null)

		const formatNumber = (num) => {
			return num?.toLocaleString() || '0'
		}

		const getCelebrationTitle = (type) => {
			const titles = {
				weekly_goal_achieved: t('chorestrike', 'Weekly Goal Achieved!'),
				team_streak_milestone: t('chorestrike', 'Team Streak Milestone!'),
				household_milestone: t('chorestrike', 'Household Milestone!'),
			}
			return titles[type] || t('chorestrike', 'Celebration!')
		}

		const getCelebrationMessage = (type) => {
			const messages = {
				weekly_goal_achieved: t('chorestrike', 'Your household completed the weekly goal together!'),
				team_streak_milestone: t('chorestrike', 'Your team is on a roll! Keep up the great work!'),
				household_milestone: t('chorestrike', 'Your household reached a new milestone!'),
			}
			return messages[type] || t('chorestrike', 'Great job!')
		}

		const dismissCelebration = (celebrationId) => {
			gameStore.dismissCelebration(celebrationId)
		}

		return {
			t,
			householdTotalCoins,
			teamStreak,
			weeklyGoalProgress,
			weeklyGoalCompleted,
			weeklyGoalTarget,
			isWeeklyGoalAchieved,
			pendingCelebration,
			formatNumber,
			getCelebrationTitle,
			getCelebrationMessage,
			dismissCelebration,
		}
	},
}
</script>

<style scoped>
.household-progress {
	background: var(--color-background-dark);
	border-radius: 12px;
	padding: 1rem;
}

.progress-header {
	margin-bottom: 1rem;
}

.progress-title {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	font-size: 1.1rem;
	font-weight: 600;
	margin: 0;
	color: var(--color-main-text);
}

.progress-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 1rem;
}

.progress-card {
	background: var(--color-main-background);
	border-radius: 8px;
	padding: 1rem;
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
	transition: transform 0.2s, box-shadow 0.2s;
}

.progress-card:hover {
	transform: translateY(-2px);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.card-icon {
	font-size: 1.5rem;
	margin-bottom: 0.5rem;
}

.card-value {
	font-size: 1.5rem;
	font-weight: 700;
	color: var(--color-primary);
}

.card-label {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	margin-top: 0.25rem;
}

.card-badge {
	font-size: 0.7rem;
	background: #ff6b6b;
	color: white;
	padding: 0.15rem 0.5rem;
	border-radius: 10px;
	margin-top: 0.5rem;
	font-weight: 600;
}

/* Team Streak Card */
.progress-card.team-streak.active {
	background: linear-gradient(135deg, #fff9c4, var(--color-main-background));
}

.progress-card.team-streak.active .card-value {
	color: #ff9800;
}

/* Weekly Goal Card */
.progress-card.weekly-goal {
	align-items: stretch;
}

.card-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 0.75rem;
}

.card-title {
	font-size: 0.9rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.card-count {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.progress-bar-container {
	height: 8px;
	background: var(--color-background-dark);
	border-radius: 4px;
	overflow: hidden;
}

.progress-bar-fill {
	height: 100%;
	background: var(--color-primary);
	border-radius: 4px;
	transition: width 0.3s ease;
}

.progress-percentage {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	text-align: right;
	margin-top: 0.25rem;
}

.achievement-badge {
	font-size: 0.8rem;
	color: #4caf50;
	font-weight: 600;
	text-align: center;
	margin-top: 0.5rem;
}

.progress-card.weekly-goal.achieved {
	background: linear-gradient(135deg, #e8f5e9, var(--color-main-background));
}

.progress-card.weekly-goal.achieved .progress-bar-fill {
	background: #4caf50;
}

/* Celebration Banner */
.celebration-banner {
	display: flex;
	align-items: center;
	gap: 1rem;
	background: linear-gradient(135deg, #fff9c4, #ffe082);
	border-radius: 8px;
	padding: 1rem;
	margin-top: 1rem;
	animation: pulse 2s ease-in-out infinite;
}

.celebration-icon {
	font-size: 2rem;
}

.celebration-content {
	flex: 1;
}

.celebration-content h4 {
	margin: 0 0 0.25rem 0;
	font-size: 1rem;
	color: #333;
}

.celebration-content p {
	margin: 0;
	font-size: 0.85rem;
	color: #555;
}

.celebration-dismiss {
	background: transparent;
	border: none;
	cursor: pointer;
	padding: 0.25rem;
	color: #666;
	opacity: 0.7;
	transition: opacity 0.2s;
}

.celebration-dismiss:hover {
	opacity: 1;
}

@keyframes pulse {
	0%, 100% {
		transform: scale(1);
	}
	50% {
		transform: scale(1.02);
	}
}

.fade-enter-active,
.fade-leave-active {
	transition: opacity 0.3s ease;
}

.fade-enter-from,
.fade-leave-to {
	opacity: 0;
}

@media (max-width: 600px) {
	.progress-grid {
		grid-template-columns: 1fr;
	}
}
</style>
