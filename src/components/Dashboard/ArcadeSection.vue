<template>
	<div class="arcade-section" data-testid="arcade-section">
		<div class="arcade-header">
			<div class="arcade-title">
				<span class="arcade-icon">🎮</span>
				<h2>{{ t('chorestrike', 'Arcade') }}</h2>
			</div>
			<div class="coins-display">
				<span class="coins-icon">⚡</span>
				<span class="coins-value">{{ currentCoins }}</span>
				<span class="coins-label">{{ t('chorestrike', 'Coins') }}</span>
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
					<div class="boosts-available">
						<span class="boosts-label">{{ t('chorestrike', 'Boosts') }}:</span>
						<span v-for="boost in boostIcons"
							:key="boost.id"
							class="boost-icon"
							:title="boost.name">
							{{ boost.icon }}
						</span>
					</div>
				</div>
				<div class="play-button">
					<button class="btn btn-primary">
						{{ t('chorestrike', 'Play') }}
					</button>
				</div>
			</div>

			<!-- 2048 Game Card -->
			<div class="game-card" data-testid="arcade-card-2048" @click="open2048">
				<div class="game-preview game-preview-2048">
					<div class="game-preview-tiles">
						<span class="game-preview-tile t-2">2</span>
						<span class="game-preview-tile t-4">4</span>
						<span class="game-preview-tile t-8">8</span>
						<span class="game-preview-tile t-16">16</span>
					</div>
				</div>
				<div class="game-info">
					<h3>{{ t('chorestrike', '2048') }}</h3>
					<p class="game-description">
						{{ t('chorestrike', 'Slide and merge matching tiles to reach 2048!') }}
					</p>
					<div class="game-stats">
						<div class="stat">
							<span class="stat-label">{{ t('chorestrike', 'Your Best') }}</span>
							<span class="stat-value">{{ best2048 }}</span>
						</div>
					</div>
				</div>
				<div class="play-button">
					<button class="btn btn-primary">
						{{ t('chorestrike', 'Play') }}
					</button>
				</div>
			</div>

			<!-- Memory Game Card -->
			<div class="game-card" data-testid="arcade-card-memory" @click="openMemory">
				<div class="game-preview game-preview-memory">
					<div class="game-preview-cards">
						<span class="game-preview-card">🍎</span>
						<span class="game-preview-card">🍌</span>
						<span class="game-preview-card">❓</span>
						<span class="game-preview-card">❓</span>
					</div>
				</div>
				<div class="game-info">
					<h3>{{ t('chorestrike', 'Memory') }}</h3>
					<p class="game-description">
						{{ t('chorestrike', 'Flip cards and match pairs. Train your brain!') }}
					</p>
					<div class="game-stats">
						<div class="stat">
							<span class="stat-label">{{ t('chorestrike', 'Your Best') }}</span>
							<span class="stat-value">{{ bestMemory }}</span>
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
		<Modal v-if="activeGame === 'snake'" @close="closeGameModal">
			<SnakeGame />
		</Modal>

		<!-- 2048 Game Modal -->
		<Modal v-if="activeGame === '2048'" @close="closeGameModal">
			<Game2048 />
		</Modal>

		<!-- Memory Game Modal -->
		<Modal v-if="activeGame === 'memory'" @close="closeGameModal">
			<MemoryGame />
		</Modal>

		<!-- Leaderboard Modal -->
		<SnakeLeaderboard v-if="showLeaderboardModal" @close="closeLeaderboardModal" />
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useMinigameStore, BOOST_DESCRIPTIONS } from '../../store/minigame.js'
import { storeToRefs } from 'pinia'
import Modal from '../Common/Modal.vue'
import SnakeGame from '../Minigames/SnakeGame.vue'
import Game2048 from '../Minigames/Game2048.vue'
import MemoryGame from '../Minigames/MemoryGame.vue'
import SnakeLeaderboard from '../Minigames/SnakeLeaderboard.vue'

const minigameStore = useMinigameStore()
const { currentCoins, personalBest, userRank } = storeToRefs(minigameStore)

const activeGame = ref(null)
const showLeaderboardModal = ref(false)

const personalBestScore = computed(() => {
	return personalBest.value?.score || 0
})

const best2048 = ref(0)
const bestMemory = ref(0)

const boostIcons = computed(() => {
	return Object.entries(BOOST_DESCRIPTIONS).map(([id, boost]) => ({
		id,
		icon: boost.icon,
		name: boost.name,
	}))
})

function openSnakeGame() {
	activeGame.value = 'snake'
}

function open2048() {
	activeGame.value = '2048'
}

function openMemory() {
	activeGame.value = 'memory'
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
	await minigameStore.fetchCoins()
	await minigameStore.fetchPersonalBest('snake')
	await minigameStore.fetchUserRank('snake')
	await minigameStore.fetchPersonalBest('2048')
	best2048.value = minigameStore.personalBest?.score || 0
	await minigameStore.fetchPersonalBest('memory')
	bestMemory.value = minigameStore.personalBest?.score || 0
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

.coins-display {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.5rem 1rem;
	background: linear-gradient(135deg, rgba(253, 203, 110, 0.18), rgba(255, 118, 117, 0.10));
	border-radius: 8px;
	border: 1px solid rgba(253, 203, 110, 0.35);
}

.coins-icon {
	font-size: 1.25rem;
}

.coins-value {
	font-size: 1.25rem;
	font-weight: bold;
	color: var(--cs-celebrate-gold);
}

.coins-label {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
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
	border-color: var(--color-primary);
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

.game-preview-2048,
.game-preview-memory {
	background: linear-gradient(135deg, var(--cs-celebrate-primary), var(--cs-celebrate-accent));
}

.game-preview-tiles,
.game-preview-cards {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 6px;
	padding: 12px;
}

.game-preview-tile {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 38px;
	height: 38px;
	border-radius: 6px;
	font-weight: 700;
	font-size: 1.1rem;
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.game-preview-tile.t-2 { background: #EEE4DA; }
.game-preview-tile.t-4 { background: #EDE0C8; }
.game-preview-tile.t-8 { background: #F2B179; color: #FFFFFF; }
.game-preview-tile.t-16 { background: #F59563; color: #FFFFFF; }

.game-preview-card {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 38px;
	height: 38px;
	border-radius: 6px;
	font-size: 1.2rem;
	background: var(--color-main-background);
	color: var(--color-main-text);
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
	color: var(--color-primary);
}

.boosts-available {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	flex-wrap: wrap;
}

.boosts-label {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.boost-icon {
	font-size: 1rem;
	padding: 0.2rem;
	background: var(--color-main-background);
	border-radius: 4px;
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
