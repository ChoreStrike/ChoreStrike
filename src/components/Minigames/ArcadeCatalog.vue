<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="arcade-catalog" data-testid="arcade-catalog">
		<p v-if="loadFailed" class="arcade-catalog-note" data-testid="arcade-catalog-error">
			{{ t('chorestrike', 'Scores could not be loaded.') }}
			<button type="button" class="arcade-catalog-link" @click="loadStats">
				{{ t('chorestrike', 'Try again') }}
			</button>
		</p>

		<ul class="arcade-catalog-grid">
			<li v-for="game in GAMES"
				:key="game.id"
				class="arcade-card"
				:data-testid="`arcade-card-${game.id}`">
				<div class="arcade-card-icon" aria-hidden="true">
					{{ game.icon }}
				</div>
				<div class="arcade-card-body">
					<h2 class="arcade-card-title">
						{{ game.name() }}
					</h2>
					<p class="arcade-card-description">
						{{ game.description() }}
					</p>
					<dl class="arcade-card-stats">
						<div class="arcade-card-stat">
							<dt>{{ t('chorestrike', 'Your Best') }}</dt>
							<dd :data-testid="`arcade-best-${game.id}`">
								{{ store.slice(game.id).personalBest?.score ?? 0 }}
							</dd>
						</div>
						<div class="arcade-card-stat">
							<dt>{{ t('chorestrike', 'Rank') }}</dt>
							<dd>#{{ store.slice(game.id).userRank || '-' }}</dd>
						</div>
					</dl>
				</div>
				<div class="arcade-card-actions">
					<button type="button"
						class="arcade-card-button primary"
						:data-testid="`arcade-play-${game.id}`"
						@click="activeGameId = game.id">
						{{ t('chorestrike', 'Play') }}
					</button>
					<button type="button"
						class="arcade-card-button"
						:data-testid="`arcade-leaderboard-${game.id}`"
						@click="leaderboardGameId = game.id">
						{{ t('chorestrike', 'Leaderboard') }}
					</button>
				</div>
			</li>
		</ul>

		<Modal v-if="activeGameId" :aria-label="activeGameName" @close="activeGameId = null">
			<div class="arcade-game-frame">
				<GameShell :game-id="activeGameId" />
			</div>
		</Modal>

		<GameLeaderboard v-if="leaderboardGameId"
			:game-id="leaderboardGameId"
			@close="leaderboardGameId = null" />
	</div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useMinigameStore } from '../../store/minigame.js'
import Modal from '../Common/Modal.vue'
import GameShell from './GameShell.vue'
import GameLeaderboard from './GameLeaderboard.vue'
import { GAMES, GAME_IDS, findGame } from './gameRegistry.js'

/**
 * The arcade's game catalog (CS-PLAN-086 §3.4; replaces the single Snake
 * card of `Dashboard/ArcadeSection.vue`). One card per registered game with
 * its own best and rank; a game opens in the shared Modal and loads its
 * code only then. Scores-only — never a Coin surface (T10/D7d).
 */
const store = useMinigameStore()
const activeGameId = ref(null)
const leaderboardGameId = ref(null)
// Only the stats load drives the note — a failed save inside a game is
// reported by the game itself.
const loadFailed = ref(false)
const activeGameName = computed(() => findGame(activeGameId.value)?.name() ?? '')

async function loadStats() {
	await store.initialize(GAME_IDS)
	loadFailed.value = store.error !== null
}

onMounted(loadStats)
</script>

<style scoped>
.arcade-catalog-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
	gap: 1rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.arcade-card {
	display: flex;
	flex-direction: column;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 12px;
	overflow: hidden;
}

.arcade-card-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 96px;
	font-size: 3rem;
	background: var(--color-background-dark);
}

.arcade-card-body {
	flex: 1;
	padding: 1rem;
}

.arcade-card-title {
	margin: 0 0 0.5rem;
	font-size: 1.1rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.arcade-card-description {
	margin: 0 0 1rem;
	font-size: 0.9rem;
	line-height: 1.4;
	color: var(--color-text-maxcontrast);
}

.arcade-card-stats {
	display: flex;
	gap: 1.5rem;
	margin: 0;
}

.arcade-card-stat dt {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.arcade-card-stat dd {
	margin: 0;
	font-size: 1.1rem;
	font-weight: bold;
	color: var(--color-main-text);
}

.arcade-card-actions {
	display: flex;
	gap: 0.5rem;
	padding: 1rem;
	border-top: 1px solid var(--color-border);
}

.arcade-card-button {
	flex: 1;
	min-height: 44px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 1rem;
	cursor: pointer;
}

.arcade-card-button.primary {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.arcade-catalog-note {
	margin: 0 0 1rem;
	color: var(--color-text-maxcontrast);
}

.arcade-catalog-link {
	min-height: 44px;
	padding: 0 0.5rem;
	border: none;
	background: none;
	color: var(--color-primary-element);
	text-decoration: underline;
	cursor: pointer;
}

.arcade-game-frame {
	padding: 2.75rem 0.5rem 0.75rem;
}
</style>
