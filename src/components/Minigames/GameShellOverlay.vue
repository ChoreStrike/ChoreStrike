<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="game-overlay" data-testid="game-overlay" :data-phase="phase">
		<p v-if="phase === 'loading'" class="game-overlay-text">
			{{ t('chorestrike', 'Loading game …') }}
		</p>
		<template v-else-if="phase === 'failed'">
			<p class="game-overlay-text">
				{{ t('chorestrike', 'The game could not be loaded.') }}
			</p>
			<button ref="primaryRef"
				type="button"
				class="game-overlay-button primary"
				data-testid="game-retry"
				@click="$emit('retry')">
				{{ t('chorestrike', 'Try again') }}
			</button>
		</template>
		<template v-else-if="phase === 'ready'">
			<h2 class="game-overlay-title">
				<span aria-hidden="true">{{ entry.icon }}</span>
				{{ entry.name() }}
			</h2>
			<p class="game-overlay-text">
				{{ entry.controls() }}
			</p>
			<button ref="primaryRef"
				type="button"
				class="game-overlay-button primary"
				data-testid="game-start"
				@click="$emit('start')">
				{{ t('chorestrike', 'Start Game') }}
			</button>
		</template>
		<template v-else-if="phase === 'paused'">
			<h2 class="game-overlay-title">
				{{ t('chorestrike', 'Paused') }}
			</h2>
			<button ref="primaryRef"
				type="button"
				class="game-overlay-button primary"
				data-testid="game-resume"
				@click="$emit('resume')">
				{{ t('chorestrike', 'Resume') }}
			</button>
		</template>
		<template v-else-if="phase === 'over'">
			<h2 class="game-overlay-title">
				{{ t('chorestrike', 'Game Over!') }}
			</h2>
			<p class="game-overlay-score" data-testid="game-final-score">
				{{ t('chorestrike', 'Score: {score}', { score }) }}
			</p>
			<p v-if="result && result.newBest" class="game-overlay-text" data-testid="game-new-best">
				{{ t('chorestrike', 'New Personal Best!') }}
			</p>
			<p v-if="result && result.saveFailed" class="game-overlay-text" data-testid="game-save-failed">
				{{ t('chorestrike', 'Your score could not be saved.') }}
			</p>
			<div class="game-overlay-actions">
				<button ref="primaryRef"
					type="button"
					class="game-overlay-button primary"
					data-testid="game-restart"
					@click="$emit('start')">
					{{ t('chorestrike', 'Play Again') }}
				</button>
				<button type="button"
					class="game-overlay-button"
					data-testid="game-open-leaderboard"
					@click="$emit('leaderboard')">
					{{ t('chorestrike', 'Leaderboard') }}
				</button>
			</div>
		</template>
	</div>
</template>

<script setup>
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'

/**
 * Start, pause, game-over and load-state overlays of GameShell
 * (CS-PLAN-086 §3.4). Scores-only: the game-over view never shows Coins.
 */
defineProps({
	phase: {
		type: String,
		required: true,
	},
	entry: {
		type: Object,
		default: null,
	},
	score: {
		type: Number,
		default: 0,
	},
	result: {
		type: Object,
		default: null,
	},
})

defineEmits(['start', 'resume', 'retry', 'leaderboard'])

const primaryRef = ref(null)

/** Move focus to the overlay's main action (Start, Resume, Play again). */
function focusPrimary() {
	primaryRef.value?.focus()
}

defineExpose({ focusPrimary })
</script>

<style scoped>
.game-overlay {
	position: absolute;
	inset: 0;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 0.75rem;
	padding: 1rem;
	text-align: center;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.game-overlay-title {
	margin: 0;
	font-size: 1.5rem;
}

.game-overlay-text {
	margin: 0;
	color: var(--color-main-text);
}

.game-overlay-score {
	margin: 0;
	font-size: 1.25rem;
	font-weight: bold;
}

.game-overlay-actions {
	display: flex;
	flex-wrap: wrap;
	justify-content: center;
	gap: 0.5rem;
}

.game-overlay-button {
	min-height: 44px;
	padding: 0 1.25rem;
	border: 1px solid var(--color-border-maxcontrast, var(--color-border));
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 1rem;
	cursor: pointer;
}

.game-overlay-button.primary {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}
</style>
