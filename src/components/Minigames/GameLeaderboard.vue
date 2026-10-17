<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<Modal :aria-label="heading" @close="$emit('close')">
		<div class="game-leaderboard" data-testid="game-leaderboard" :data-game-id="gameId">
			<div class="leaderboard-header">
				<h2>{{ heading }}</h2>
				<div class="tab-buttons">
					<button v-for="tab in TABS"
						:key="tab.id"
						type="button"
						class="tab-btn"
						:class="{ active: activeTab === tab.id }"
						:aria-pressed="activeTab === tab.id ? 'true' : 'false'"
						@click="activeTab = tab.id">
						{{ tab.label() }}
					</button>
				</div>
			</div>

			<div class="personal-stats" data-testid="game-leaderboard-personal">
				<div class="stat-item">
					<span class="stat-label">{{ t('chorestrike', 'Your Best') }}</span>
					<span class="stat-value">{{ slice.personalBest?.score || 0 }}</span>
				</div>
				<div class="stat-item">
					<span class="stat-label">{{ t('chorestrike', 'Your Rank') }}</span>
					<span class="stat-value">#{{ slice.userRank || '-' }}</span>
				</div>
			</div>

			<div class="leaderboard-list">
				<p v-if="loading" class="leaderboard-note">
					{{ t('chorestrike', 'Loading...') }}
				</p>
				<div v-else-if="entries.length === 0" class="leaderboard-note" data-testid="game-leaderboard-empty">
					<p>{{ t('chorestrike', 'No scores yet!') }}</p>
					<p>{{ t('chorestrike', 'Be the first to play!') }}</p>
				</div>
				<ol v-else class="entries">
					<li v-for="(entry, index) in entries"
						:key="entry.id"
						class="leaderboard-entry"
						:class="{ 'current-user': entry.userId === currentUserId }"
						data-testid="game-leaderboard-entry">
						<span class="rank">
							<span v-if="index < 3" aria-hidden="true">{{ MEDALS[index] }}</span>
							<span :class="{ 'cs-visually-hidden': index < 3 }">{{ index + 1 }}</span>
						</span>
						<span class="player-name">{{ entry.displayName }}</span>
						<span class="score">{{ entry.score }}</span>
					</li>
				</ol>
			</div>

			<div v-if="slice.recentScores.length > 0" class="recent-scores">
				<h3>{{ t('chorestrike', 'Your Recent Games') }}</h3>
				<ul class="recent-list">
					<li v-for="recent in slice.recentScores" :key="recent.id" class="recent-entry">
						<span class="recent-score">{{ recent.score }}</span>
						<span class="recent-date">{{ formatDate(recent.createdAt) }}</span>
					</li>
				</ul>
			</div>
		</div>
	</Modal>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useMinigameStore } from '../../store/minigame.js'
import { useUserStore } from '../../store/user.js'
import Modal from '../Common/Modal.vue'
import { findGame } from './gameRegistry.js'

/**
 * Highscores of ONE game (CS-PLAN-086): all-time and weekly boards, own
 * best, own rank and recent rounds. Never merged into the household
 * ranking, and there is no cross-game aggregate.
 */
const props = defineProps({
	gameId: {
		type: String,
		required: true,
	},
})

defineEmits(['close'])

const TABS = [
	{ id: 'allTime', label: () => t('chorestrike', 'All Time') },
	{ id: 'weekly', label: () => t('chorestrike', 'This Week') },
]
const MEDALS = ['🥇', '🥈', '🥉']

const store = useMinigameStore()
const userStore = useUserStore()
const activeTab = ref('allTime')
const loading = ref(true)

const slice = computed(() => store.slice(props.gameId))
const heading = computed(() => {
	const game = findGame(props.gameId)
	return t('chorestrike', 'Leaderboard: {game}', { game: game ? game.name() : props.gameId })
})
const currentUserId = computed(() => userStore.currentUser?.nextcloudUserId)
const entries = computed(() => (activeTab.value === 'allTime' ? slice.value.leaderboard : slice.value.weeklyLeaderboard))

function formatDate(dateString) {
	const date = new Date(dateString)
	const diffMins = Math.floor((Date.now() - date.getTime()) / 60000)
	const diffHours = Math.floor(diffMins / 60)
	const diffDays = Math.floor(diffHours / 24)
	if (diffMins < 1) return t('chorestrike', 'Just now')
	if (diffMins < 60) return t('chorestrike', '{n} min ago', { n: diffMins })
	if (diffHours < 24) return t('chorestrike', '{n}h ago', { n: diffHours })
	if (diffDays < 7) return t('chorestrike', '{n}d ago', { n: diffDays })
	return date.toLocaleDateString()
}

onMounted(async () => {
	try {
		await Promise.all([
			store.fetchLeaderboard(props.gameId),
			store.fetchWeeklyLeaderboard(props.gameId),
			store.fetchPersonalBest(props.gameId),
			store.fetchUserRank(props.gameId),
			store.fetchRecentScores(props.gameId),
		])
	} finally {
		loading.value = false
	}
})
</script>

<style scoped>
.game-leaderboard {
	padding: 1.5rem;
}

.leaderboard-header {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	margin-bottom: 1rem;
}

.leaderboard-header h2 {
	margin: 0;
	padding-inline-end: 2rem;
	font-size: 1.4rem;
	color: var(--color-main-text);
}

.tab-buttons {
	display: flex;
	gap: 0.5rem;
}

.tab-btn {
	min-height: 44px;
	padding: 0 1rem;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.tab-btn.active {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.personal-stats {
	display: flex;
	justify-content: center;
	gap: 2rem;
	padding: 1rem;
	margin-bottom: 1rem;
	background: var(--color-background-dark);
	border-radius: 8px;
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
	color: var(--color-main-text);
}

.leaderboard-list {
	min-height: 160px;
}

.leaderboard-note {
	padding: 1.5rem;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.leaderboard-note p {
	margin: 0;
}

.entries,
.recent-list {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.leaderboard-entry {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	padding: 0.75rem;
	background: var(--color-background-dark);
	border: 2px solid transparent;
	border-radius: 8px;
}

.leaderboard-entry.current-user {
	border-color: var(--color-primary-element);
}

.rank {
	width: 2rem;
	text-align: center;
	font-weight: bold;
	color: var(--color-main-text);
}

.player-name {
	flex: 1;
	font-weight: 500;
	color: var(--color-main-text);
}

.score {
	font-size: 1.15rem;
	font-weight: bold;
	color: var(--color-main-text);
}

.recent-scores {
	margin-top: 1.5rem;
	padding-top: 1rem;
	border-top: 1px solid var(--color-border);
}

.recent-scores h3 {
	margin: 0 0 0.75rem;
	font-size: 1rem;
	color: var(--color-main-text);
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
</style>
