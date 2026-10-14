<template>
	<div class="arcade-section" data-testid="arcade-section">
		<div class="arcade-header">
			<div class="arcade-title">
				<span class="arcade-icon">🎮</span>
				<h2>{{ t('chorestrike', 'Arcade') }}</h2>
			</div>
		</div>

		<div class="arcade-content">
			<!-- Snake Game Card -->
			<div class="game-card" data-testid="arcade-card-snake" @click="openSnakeGame">
				<div class="game-preview">
					<div class="snake-preview">
						<span class="snake-icon">🐍</span>
					</div>
				</div>
				<div class="game-info">
					<h3>{{ t('chorestrike', 'Snake') }}</h3>
					<p class="game-description">
						{{ t('chorestrike', 'Collect food, grow your snake, beat the highscore!') }}
					</p>
					<div class="game-stats">
						<div class="stat">
							<span class="stat-label">{{ t('chorestrike', 'Your Best') }}</span>
							<span class="stat-value">{{ personalBestScore }}</span>
						</div>
						<div class="stat">
							<span class="stat-label">{{ t('chorestrike', 'Rank') }}</span>
							<span class="stat-value">#{{ userRank || '-' }}</span>
						</div>
					</div>
				</div>
				<div class="play-button">
					<button class="btn btn-primary">
						{{ t('chorestrike', 'Play') }}
					</button>
				</div>
			</div>
		</div>

		<!-- Full Leaderboard Link -->
		<div class="arcade-footer">
			<button class="btn btn-secondary" @click="showLeaderboard">
				{{ t('chorestrike', 'View Leaderboard') }}
			</button>
		</div>

		<!-- Snake Game Modal -->
		<Modal v-if="activeGame === 'snake'" :aria-label="t('chorestrike', 'Snake')" @close="closeGameModal">
			<SnakeGame />
		</Modal>

		<!-- Leaderboard Modal -->
		<SnakeLeaderboard v-if="showLeaderboardModal" @close="closeLeaderboardModal" />
	</div>
</template>

<script setup>
// CS-PLAN-070 WP3 (T10/D7d no-Coin arcade cutover): the arcade is
// scores-only family fun — never a Coin source. The 2048/Memory games and
// the boost shop retired with their Coin spending effects; Snake keeps its
// plain leaderboard. Scores never write wallet entries or contribution.
import { ref, computed, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useMinigameStore } from '../../store/minigame.js'
import { storeToRefs } from 'pinia'
import Modal from '../Common/Modal.vue'
import SnakeGame from '../Minigames/SnakeGame.vue'
import SnakeLeaderboard from '../Minigames/SnakeLeaderboard.vue'

const minigameStore = useMinigameStore()
const { personalBest, userRank } = storeToRefs(minigameStore)

const activeGame = ref(null)
const showLeaderboardModal = ref(false)

const personalBestScore = computed(() => {
	return personalBest.value?.score || 0
})

function openSnakeGame() {
	activeGame.value = 'snake'
}

function closeGameModal() {
	activeGame.value = null
}

function showLeaderboard() {
	showLeaderboardModal.value = true
}

function closeLeaderboardModal() {
	showLeaderboardModal.value = false
}

onMounted(async () => {
	await minigameStore.fetchPersonalBest('snake')
	await minigameStore.fetchUserRank('snake')
})
</script>

<style scoped>
.arcade-section {
	background: var(--color-main-background);
	border-radius: 12px;
	padding: 1.5rem;
	border: 1px solid var(--color-border);
}

.arcade-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 1.5rem;
	padding-bottom: 1rem;
	border-bottom: 1px solid var(--color-border);
}

.arcade-title {
	display: flex;
	align-items: center;
	gap: 0.75rem;
}

.arcade-icon {
	font-size: 1.5rem;
}

.arcade-title h2 {
	font-size: 1.25rem;
	font-weight: 600;
	margin: 0;
	color: var(--color-main-text);
}

.arcade-content {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: 1rem;
	margin-bottom: 1.5rem;
}

.game-card {
	display: flex;
	flex-direction: column;
	background: var(--color-background-dark);
	border-radius: 10px;
	overflow: hidden;
	cursor: pointer;
	transition: all 0.2s ease;
	border: 2px solid transparent;
}

.game-card:hover {
	border-color: var(--color-primary-element);
	transform: translateY(-2px);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.game-preview {
	height: 120px;
	display: flex;
	align-items: center;
	justify-content: center;
	background: linear-gradient(135deg, #1a1a2e, #16213e);
}

.snake-preview {
	display: flex;
	align-items: center;
	justify-content: center;
}

.snake-icon {
	font-size: 4rem;
	animation: snake-wiggle 2s ease-in-out infinite;
}

@keyframes snake-wiggle {
	0%, 100% { transform: rotate(-5deg); }
	50% { transform: rotate(5deg); }
}

.game-info {
	padding: 1rem;
	flex: 1;
}

.game-info h3 {
	font-size: 1.1rem;
	font-weight: 600;
	margin: 0 0 0.5rem 0;
	color: var(--color-main-text);
}

.game-description {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	margin: 0 0 1rem 0;
	line-height: 1.4;
}

.game-stats {
	display: flex;
	gap: 1.5rem;
	margin-bottom: 0.75rem;
}

.stat {
	display: flex;
	flex-direction: column;
}

.stat-label {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.stat-value {
	font-size: 1.1rem;
	font-weight: bold;
	color: var(--color-primary-element);
}

.play-button {
	padding: 1rem;
	border-top: 1px solid var(--color-border);
}

.btn {
	width: 100%;
	padding: 0.75rem 1rem;
	border: none;
	border-radius: 6px;
	font-size: 1rem;
	font-weight: 500;
	cursor: pointer;
	transition: all 0.2s ease;
}

.btn-primary {
	background: var(--color-primary);
	color: white;
}

.btn-primary:hover {
	background: var(--color-primary-dark);
}

.btn-secondary {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.btn-secondary:hover {
	background: var(--color-background-darker);
}

.arcade-footer {
	display: flex;
	justify-content: center;
	padding-top: 1rem;
	border-top: 1px solid var(--color-border);
}

@media (max-width: 768px) {
	.arcade-content {
		grid-template-columns: 1fr;
	}

	.arcade-header {
		flex-direction: column;
		gap: 1rem;
		align-items: flex-start;
	}
}
</style>
