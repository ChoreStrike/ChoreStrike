<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<header class="game-shell-header" :class="{ 'is-calm': !motion }">
		<div class="game-shell-stat">
			<span class="game-shell-stat-label">{{ t('chorestrike', 'Score') }}</span>
			<span :key="score" class="game-shell-stat-value is-score" data-testid="game-score">{{ score }}</span>
		</div>
		<div v-if="timeLeft !== null" class="game-shell-stat" :class="{ 'is-urgent': timeLeft <= URGENT_SECONDS }">
			<span class="game-shell-stat-label">{{ t('chorestrike', 'Time') }}</span>
			<span class="game-shell-stat-value" data-testid="game-time">{{ timeLeft }}</span>
		</div>
		<div v-if="lives !== null" class="game-shell-stat">
			<span class="game-shell-stat-label">{{ t('chorestrike', 'Lives') }}</span>
			<span class="game-shell-stat-value">
				<span class="cs-visually-hidden" data-testid="game-lives">{{ lives }}</span>
				<span class="game-shell-hearts" aria-hidden="true">{{ hearts }}</span>
			</span>
		</div>
		<button v-if="playing"
			type="button"
			class="game-shell-button"
			data-testid="game-pause"
			@click="$emit('pause')">
			{{ t('chorestrike', 'Pause') }}
		</button>
		<div class="game-shell-stat">
			<span class="game-shell-stat-label">{{ t('chorestrike', 'Best') }}</span>
			<span class="game-shell-stat-value" data-testid="game-best">{{ best }}</span>
		</div>
	</header>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'

/**
 * Score header of GameShell (CS-PLAN-086 §3.4): score, the time left of a
 * timed round, the lives of a game that has them (as hearts, the number
 * stays for assistive technology), the pause button while playing and the
 * personal best. With the motion opt-in a changed score bumps and the last
 * seconds pulse; the last seconds are colored either way.
 */
const props = defineProps({
	score: {
		type: Number,
		required: true,
	},
	timeLeft: {
		type: Number,
		default: null,
	},
	lives: {
		type: Number,
		default: null,
	},
	best: {
		type: Number,
		required: true,
	},
	playing: {
		type: Boolean,
		default: false,
	},
	motion: {
		type: Boolean,
		default: false,
	},
})

defineEmits(['pause'])

const URGENT_SECONDS = 5
const MAX_HEARTS = 5

const hearts = computed(() => (props.lives > 0 ? '♥'.repeat(Math.min(props.lives, MAX_HEARTS)) : '♡'))
</script>

<style scoped>
.game-shell-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 1rem;
	width: 100%;
	max-width: 480px;
	padding: 0.5rem 1rem;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large, 10px);
}

.game-shell-stat {
	display: flex;
	flex-direction: column;
	align-items: center;
}

.game-shell-stat-label {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.game-shell-stat-value {
	position: relative;
	font-size: 1.5rem;
	font-weight: bold;
	color: var(--color-main-text);
}

.game-shell-hearts {
	letter-spacing: 0.1em;
	color: var(--color-error-text);
}

.game-shell-stat.is-urgent .game-shell-stat-value {
	color: var(--color-error-text);
}

.game-shell-header:not(.is-calm) .is-score {
	animation: game-score-bump 250ms ease-out;
}

.game-shell-header:not(.is-calm) .is-urgent .game-shell-stat-value {
	animation: game-time-pulse 1s ease-in-out infinite;
}

.game-shell-button {
	min-height: 44px;
	padding: 0 1rem;
	border: 1px solid var(--color-border-maxcontrast, var(--color-border));
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

@keyframes game-score-bump {
	from {
		transform: scale(1.35);
	}

	to {
		transform: scale(1);
	}
}

@keyframes game-time-pulse {
	50% {
		transform: scale(1.15);
	}
}
</style>
