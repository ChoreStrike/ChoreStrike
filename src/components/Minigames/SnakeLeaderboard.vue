<template>
	<div class="leaderboard-overlay" @click.self="$emit('close')">
		<div class="snake-leaderboard" data-testid="snake-leaderboard">
			<div class="leaderboard-header">
				<h2>🏆 {{ t('chorestrike', 'Snake Leaderboard') }}</h2>
				<div class="tab-buttons">
					<button
						class="tab-btn"
						:class="{ active: activeTab === 'allTime' }"
						@click="activeTab = 'allTime'">
						{{ t('chorestrike', 'All Time') }}
					</button>
					<button
						class="tab-btn"
						:class="{ active: activeTab === 'weekly' }"
						@click="activeTab = 'weekly'">
						{{ t('chorestrike', 'This Week') }}
					</button>
				</div>
			</div>

			<!-- Personal Stats -->
			<div class="personal-stats">
				<div class="stat-item">
					<span class="stat-label">{{ t('chorestrike', 'Your Best') }}</span>
					<span class="stat-value">{{ personalBest?.score || 0 }}</span>
				</div>
				<div class="stat-item">
					<span class="stat-label">{{ t('chorestrike', 'Your Rank') }}</span>
					<span class="stat-value">#{{ userRank || '-' }}</span>
				</div>
			</div>

			<!-- Leaderboard List -->
			<div class="leaderboard-list">
				<div v-if="loading" class="loading">
					<span class="loading-spinner" />
					{{ t('chorestrike', 'Loading...') }}
				</div>

				<div v-else-if="currentLeaderboard.length === 0" class="empty-state">
					<p>{{ t('chorestrike', 'No scores yet!') }}</p>
					<p>{{ t('chorestrike', 'Be the first to play!') }}</p>
				</div>

				<div v-else class="entries">
					<div
						v-for="(entry, index) in currentLeaderboard"
						:key="entry.id"
						class="leaderboard-entry"
						:class="{
							'top-three': index < 3,
							'current-user': entry.userId === currentUserId,
						}">
						<div class="rank">
							<span v-if="index === 0" class="medal gold">🥇</span>
							<span v-else-if="index === 1" class="medal silver">🥈</span>
							<span v-else-if="index === 2" class="medal bronze">🥉</span>
							<span v-else class="rank-number">{{ index + 1 }}</span>
						</div>
						<div class="player-info">
							<span class="player-name">{{ entry.displayName }}</span>
						</div>
						<div class="score">
							{{ entry.score }}
						</div>
					</div>
				</div>
			</div>

			<!-- Recent Scores -->
			<div v-if="recentScores.length > 0" class="recent-scores">
				<h3>{{ t('chorestrike', 'Your Recent Games') }}</h3>
				<div class="recent-list">
					<div
						v-for="score in recentScores"
						:key="score.id"
						class="recent-entry">
						<span class="recent-score">{{ score.score }}</span>
						<span class="recent-date">{{ formatDate(score.createdAt) }}</span>
					</div>
				</div>
			</div>

			<div class="leaderboard-footer">
				<button class="btn btn-secondary" @click="$emit('close')">
					{{ t('chorestrike', 'Close') }}
				</button>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useMinigameStore } from '../../store/minigame.js'
import { storeToRefs } from 'pinia'
import { useUserStore } from '../../store/user.js'

const minigameStore = useMinigameStore()
const userStore = useUserStore()

const {
	leaderboard,
	weeklyLeaderboard,
	personalBest,
	userRank,
	recentScores,
	loading,
} = storeToRefs(minigameStore)

const currentUserId = computed(() => userStore.currentUser?.nextcloudUserId)

const activeTab = ref('allTime')

const currentLeaderboard = computed(() => {
	return activeTab.value === 'allTime'
		? leaderboard.value
		: weeklyLeaderboard.value
})

const formatDate = (dateString) => {
	const date = new Date(dateString)
	const now = new Date()
	const diffMs = now - date
	const diffMins = Math.floor(diffMs / 60000)
	const diffHours = Math.floor(diffMs / 3600000)
	const diffDays = Math.floor(diffMs / 86400000)

	if (diffMins < 1) return t('chorestrike', 'Just now')
	if (diffMins < 60) return t('chorestrike', '{n} min ago', { n: diffMins })
	if (diffHours < 24) return t('chorestrike', '{n}h ago', { n: diffHours })
	if (diffDays < 7) return t('chorestrike', '{n}d ago', { n: diffDays })

	return date.toLocaleDateString()
}

onMounted(async () => {
	await Promise.all([
		minigameStore.fetchLeaderboard('snake'),
		minigameStore.fetchWeeklyLeaderboard('snake'),
		minigameStore.fetchRecentScores('snake'),
	])
})
</script>

<style scoped>
.leaderboard-overlay {
	position: fixed;
	top: 0;
	inset-inline: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 1000;
}

.snake-leaderboard {
	background: var(--color-main-background);
	border-radius: 12px;
	padding: 1.5rem;
	max-width: 500px;
	width: 90%;
	max-height: 80vh;
	overflow-y: auto;
	box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
}

.leaderboard-header {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	margin-bottom: 1rem;
}

.leaderboard-header h2 {
	font-size: 1.5rem;
	margin: 0;
	color: var(--color-main-text);
	text-align: center;
}

.tab-buttons {
	display: flex;
	gap: 0.5rem;
	justify-content: center;
}

.tab-btn {
	padding: 0.5rem 1rem;
	border: none;
	border-radius: 6px;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	transition: all 0.2s ease;
}

.tab-btn:hover {
	background: var(--color-background-darker);
}

.tab-btn.active {
	background: var(--color-primary);
	color: white;
}

.personal-stats {
	display: flex;
	justify-content: center;
	gap: 2rem;
	padding: 1rem;
	background: var(--color-background-dark);
	border-radius: 8px;
	margin-bottom: 1rem;
}

.stat-item {
	display: flex;
	flex-direction: column;
	align-items: center;
}

.stat-label {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.stat-value {
	font-size: 1.25rem;
	font-weight: bold;
	color: var(--color-primary-element);
}

.leaderboard-list {
	min-height: 200px;
}

.loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 2rem;
	color: var(--color-text-maxcontrast);
}

.loading-spinner {
	width: 24px;
	height: 24px;
	border: 2px solid var(--color-background-darker);
	border-top-color: var(--color-primary-element);
	border-radius: 50%;
	animation: spin 1s linear infinite;
	margin-bottom: 0.5rem;
}

@keyframes spin {
	to { transform: rotate(360deg); }
}

.empty-state {
	text-align: center;
	padding: 2rem;
	color: var(--color-text-maxcontrast);
}

.entries {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.leaderboard-entry {
	display: flex;
	align-items: center;
	padding: 0.75rem;
	background: var(--color-background-dark);
	border-radius: 8px;
	transition: all 0.2s ease;
}

.leaderboard-entry.top-three {
	background: linear-gradient(135deg, var(--color-background-dark), var(--color-primary-light));
}

.leaderboard-entry.current-user {
	border: 2px solid var(--color-primary-element);
}

.rank {
	width: 40px;
	display: flex;
	justify-content: center;
}

.medal {
	font-size: 1.25rem;
}

.rank-number {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.player-info {
	flex: 1;
	display: flex;
	flex-direction: column;
}

.player-name {
	font-weight: 500;
	color: var(--color-main-text);
}

.score {
	font-size: 1.25rem;
	font-weight: bold;
	color: var(--color-primary-element);
	padding: 0.25rem 0.75rem;
	background: var(--color-main-background);
	border-radius: 4px;
}

.recent-scores {
	margin-top: 1.5rem;
	padding-top: 1rem;
	border-top: 1px solid var(--color-border);
}

.recent-scores h3 {
	font-size: 1rem;
	margin: 0 0 0.75rem 0;
	color: var(--color-main-text);
}

.recent-list {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.recent-entry {
	display: flex;
	justify-content: space-between;
	padding: 0.5rem;
	background: var(--color-background-dark);
	border-radius: 4px;
}

.recent-score {
	font-weight: bold;
	color: var(--color-main-text);
}

.recent-date {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.leaderboard-footer {
	display: flex;
	justify-content: center;
	margin-top: 1.5rem;
	padding-top: 1rem;
	border-top: 1px solid var(--color-border);
}

.btn {
	padding: 0.75rem 1.5rem;
	border: none;
	border-radius: 6px;
	font-size: 1rem;
	cursor: pointer;
	transition: all 0.2s ease;
}

.btn-secondary {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.btn-secondary:hover {
	background: var(--color-background-darker);
}
</style>
