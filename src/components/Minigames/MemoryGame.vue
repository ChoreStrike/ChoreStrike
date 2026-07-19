<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT

  MemoryGame.vue — ChoreStrike's take on the classic concentration /
  memory matching game. Reuses the existing minigame store
  (`useMinigameStore`) for scores/coins/leaderboards.

  Pure clean-room implementation: a small set of emoji pairs is
  shuffled onto an N×N grid; the player flips two at a time, matching
  pairs disappear, and the score grows with each match. No third-party
  code is vendored.
-->
<template>
	<div class="memory-game" data-testid="memory-game">
		<div class="game-header">
			<div class="score-display">
				<span class="score-label">{{ t('chorestrike', 'Moves') }}</span>
				<span class="score-value" data-testid="memory-moves">{{ moves }}</span>
			</div>
			<div class="coins-display">
				<span class="coins-icon">⚡</span>
				<span class="coins-value">{{ currentCoins }}</span>
			</div>
			<div v-if="best" class="personal-best">
				<span class="best-label">{{ t('chorestrike', 'Best') }}</span>
				<span class="best-value">{{ best.score }}</span>
			</div>
		</div>

		<div class="board" data-testid="memory-board">
			<button
				v-for="(card, index) in cards"
				:key="card.id"
				class="memory-card"
				:class="{
					flipped: card.flipped || card.matched,
					matched: card.matched,
				}"
				:disabled="card.matched || card.flipped || locked"
				:aria-label="card.matched ? t('chorestrike', 'Matched') : t('chorestrike', 'Hidden card')"
				@click="flip(index)">
				<span class="card-back">❓</span>
				<span class="card-face">{{ card.symbol }}</span>
			</button>
		</div>

		<div v-if="matchedPairs === totalPairs" class="win-banner" data-testid="memory-win">
			<h2>{{ t('chorestrike', 'You matched them all!') }}</h2>
			<p>{{ t('chorestrike', 'Score: {score}', { score }) }}</p>
			<button
				class="btn btn-primary"
				data-testid="memory-restart"
				@click="restart">
				{{ t('chorestrike', 'Play again') }}
			</button>
		</div>

		<div class="controls">
			<button
				class="btn btn-secondary"
				data-testid="memory-restart-button"
				@click="restart">
				{{ t('chorestrike', 'New game') }}
			</button>
		</div>
	</div>
</template>

<script>
import { ref, computed, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useMinigameStore } from '../../store/minigame.js'
import { storeToRefs } from 'pinia'

const SYMBOLS = ['🍎', '🍌', '🍇', '🍉', '🍓', '🍒', '🥝', '🍍']
const PAIRS = 8

function shuffle(items) {
	const arr = items.slice()
	for (let i = arr.length - 1; i > 0; i--) {
		const j = Math.floor(Math.random() * (i + 1))
		;[arr[i], arr[j]] = [arr[j], arr[i]]
	}
	return arr
}

function buildDeck() {
	const pairs = []
	for (let i = 0; i < PAIRS; i++) {
		const symbol = SYMBOLS[i]
		pairs.push({ id: `${i}-a`, symbol, flipped: false, matched: false })
		pairs.push({ id: `${i}-b`, symbol, flipped: false, matched: false })
	}
	return shuffle(pairs)
}

export default {
	name: 'MemoryGame',

	setup() {
		const minigameStore = useMinigameStore()
		const { currentCoins, personalBest: best } = storeToRefs(minigameStore)

		const cards = ref(buildDeck())
		const moves = ref(0)
		const score = ref(0)
		const locked = ref(false)
		const firstPick = ref(null)
		const secondPick = ref(null)

		const totalPairs = computed(() => PAIRS)
		const matchedPairs = computed(() => cards.value.filter((c) => c.matched).length / 2)

		function startNewGame() {
			cards.value = buildDeck()
			moves.value = 0
			score.value = 0
			firstPick.value = null
			secondPick.value = null
			locked.value = false
			minigameStore.startGame('memory')
		}

		function restart() {
			startNewGame()
		}

		function flip(index) {
			if (locked.value) {
				return
			}
			const card = cards.value[index]
			if (!card || card.flipped || card.matched) {
				return
			}
			card.flipped = true
			if (firstPick.value === null) {
				firstPick.value = index
				return
			}
			if (secondPick.value !== null) {
				return
			}
			secondPick.value = index
			moves.value += 1
			minigameStore.updateScore(1)
			evaluatePick()
		}

		async function evaluatePick() {
			locked.value = true
			const a = cards.value[firstPick.value]
			const b = cards.value[secondPick.value]
			if (a.symbol === b.symbol) {
				a.matched = true
				b.matched = true
				score.value += 10
				firstPick.value = null
				secondPick.value = null
				locked.value = false
				if (matchedPairs.value === totalPairs.value) {
					await minigameStore.endGame()
				}
				return
			}
			// Mismatch: flip back after a short delay so the player can
			// memorise the cards.
			setTimeout(() => {
				a.flipped = false
				b.flipped = false
				firstPick.value = null
				secondPick.value = null
				locked.value = false
			}, 700)
		}

		onMounted(async () => {
			await minigameStore.fetchPersonalBest('memory')
		})

		return {
			t,
			cards,
			moves,
			score,
			locked,
			best,
			currentCoins,
			totalPairs,
			matchedPairs,
			flip,
			restart,
		}
	},
}
</script>

<style scoped>
.memory-game {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
	background: var(--cs-card-bg);
	border-radius: var(--cs-radius-lg);
	border: 1px solid var(--cs-card-border);
	box-shadow: var(--cs-shadow-md);
}

.game-header {
	display: flex;
	justify-content: space-between;
	gap: 12px;
}

.score-display,
.coins-display,
.personal-best {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 8px 12px;
	background: var(--cs-chip-bg);
	border-radius: var(--cs-radius-md);
	min-width: 80px;
}

.score-label,
.coins-icon,
.best-label {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.score-value,
.coins-value,
.best-value {
	font-size: 1.2rem;
	font-weight: 700;
	color: var(--color-primary-element);
}

.board {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 8px;
	aspect-ratio: 1;
}

.memory-card {
	position: relative;
	display: flex;
	align-items: center;
	justify-content: center;
	aspect-ratio: 1;
	font-size: 1.5rem;
	background: var(--color-background-dark);
	border: 2px solid var(--cs-card-border);
	border-radius: var(--cs-radius-md);
	cursor: pointer;
	transition: transform var(--cs-transition-fast), background-color var(--cs-transition-base);
	padding: 0;
	color: var(--color-main-text);
}

.memory-card:active:not(:disabled) {
	transform: scale(0.97);
}

.memory-card.flipped {
	background: var(--color-main-background);
	border-color: var(--color-primary-element);
}

.memory-card.matched {
	background: var(--cs-celebrate-success);
	color: #FFFFFF;
	border-color: var(--cs-celebrate-success);
	cursor: default;
}

.card-back,
.card-face {
	pointer-events: none;
}

.card-face {
	display: none;
}

.memory-card.flipped .card-back {
	display: none;
}

.memory-card.flipped .card-face {
	display: inline;
}

.win-banner {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 16px;
	background: linear-gradient(135deg, var(--cs-celebrate-gold), var(--cs-celebrate-accent));
	color: #1F2937;
	border-radius: var(--cs-radius-md);
}

.win-banner h2,
.win-banner p {
	margin: 0;
}

.controls {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}

.btn {
	padding: 8px 16px;
	border: none;
	border-radius: var(--cs-radius-md);
	font-weight: 600;
	cursor: pointer;
	transition: background-color var(--cs-transition-fast);
}

.btn-primary {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text, #FFFFFF);
}

.btn-secondary {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

@media (max-width: 480px) {
	.memory-card {
		font-size: 1.2rem;
	}
}
</style>
