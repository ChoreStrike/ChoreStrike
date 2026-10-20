<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="game-overlay"
		data-testid="game-overlay"
		:data-phase="phase"
		:style="entry && entry.accent ? { '--game-accent': entry.accent } : null">
		<p v-if="phase === 'loading'" class="game-overlay-text">
			{{ t('chorestrike', 'Loading game …') }}
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
			<span class="game-overlay-icon" aria-hidden="true">{{ entry.icon }}</span>
			<h2 class="game-overlay-title">
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
			<h2 class="game-overlay-title" data-testid="game-over-title">
				{{ entry && entry.overTitle ? entry.overTitle() : t('chorestrike', 'Game Over!') }}
			</h2>
			<p class="game-overlay-score" data-testid="game-final-score">
				{{ t('chorestrike', 'Score: {score}', { score }) }}
			</p>
			<p v-if="result && result.newBest" class="game-overlay-text game-overlay-best" data-testid="game-new-best">
				<span aria-hidden="true">🏆</span> {{ t('chorestrike', 'New Personal Best!') }}
			</p>
			<p v-if="result && result.saveFailed" class="game-overlay-text" data-testid="game-save-failed">
				{{ saveFailedText(result.failure) }}
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
		<template v-if="phase === 'ready' || phase === 'paused' || phase === 'over'">
			<div class="game-overlay-effects" data-testid="game-effect-toggles">
				<button type="button"
					class="game-overlay-toggle"
					:class="{ 'is-saving': saving }"
					:aria-pressed="sound ? 'true' : 'false'"
					data-testid="game-sound-toggle"
					@click="$emit('toggle', 'sound')">
					<span aria-hidden="true">{{ sound ? '🔊' : '🔇' }}</span>
					{{ t('chorestrike', 'Sound') }}
				</button>
				<button type="button"
					class="game-overlay-toggle"
					:class="{ 'is-saving': saving }"
					:aria-pressed="effects ? 'true' : 'false'"
					data-testid="game-effects-toggle"
					@click="$emit('toggle', 'effects')">
					<span aria-hidden="true">✨</span>
					{{ t('chorestrike', 'Effects') }}
				</button>
			</div>
			<p v-if="effects && prefersReducedMotion()" class="game-overlay-hint">
				{{ t('chorestrike', 'Your system reduced-motion setting always wins over this switch.') }}
			</p>
		</template>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { ref } from 'vue'
import { prefersReducedMotion } from '../../utils/confetti.js'

/**
 * Start, pause, game-over and load-state overlays of GameShell
 * (CS-PLAN-086 §3.4). Scores-only: the game-over view never shows Coins.
 * A game without a fail state names its end through the registry entry's
 * optional `overTitle()`; an entry's `accent` tints the overlay. The Sound
 * and Effects switches (WP-G) show the personal opt-ins and ask the shell
 * to flip them (the shell ignores a click while a save runs; the buttons
 * stay enabled so keyboard focus never drops); a system reduced-motion
 * preference is named as the floor.
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
	sound: {
		type: Boolean,
		default: false,
	},
	effects: {
		type: Boolean,
		default: false,
	},
	saving: {
		type: Boolean,
		default: false,
	},
})

defineEmits(['start', 'resume', 'retry', 'leaderboard', 'toggle'])

const primaryRef = ref(null)

/**
 * Why a score was not saved (R086H-03): a game locked again after an undo
 * and the daily save cap get their own neutral line.
 *
 * @param {string|null} failure 'locked', 'limit' or 'error'
 * @return {string}
 */
function saveFailedText(failure) {
	if (failure === 'locked') {
		return t('chorestrike', 'This game is locked again, so your score was not saved.')
	}
	if (failure === 'limit') {
		return t('chorestrike', "Today's score limit is reached, so your score was not saved.")
	}
	return t('chorestrike', 'Your score could not be saved.')
}

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
	overflow-y: auto;
	text-align: center;
	background: linear-gradient(
		180deg,
		color-mix(in srgb, var(--game-accent, var(--color-main-background)) 24%, var(--color-main-background)) 0%,
		var(--color-main-background) 100%
	);
	color: var(--color-main-text);
}

.game-overlay-icon {
	font-size: 3rem;
	line-height: 1;
}

.game-overlay-title {
	margin: 0;
	font-size: 1.5rem;
}

.game-overlay-text {
	margin: 0;
	color: var(--color-main-text);
}

.game-overlay-best {
	font-weight: bold;
}

.game-overlay-score {
	margin: 0;
	font-size: 1.5rem;
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

.game-overlay-effects {
	display: flex;
	gap: 0.5rem;
	margin-top: 0.25rem;
}

.game-overlay-toggle {
	min-height: 44px;
	padding: 0 0.75rem;
	border: 1px solid var(--color-border-maxcontrast, var(--color-border));
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.9rem;
	cursor: pointer;
}

.game-overlay-toggle[aria-pressed="true"] {
	border-color: var(--color-primary-element);
	box-shadow: inset 0 0 0 1px var(--color-primary-element);
}

.game-overlay-toggle.is-saving {
	cursor: progress;
	opacity: 0.6;
}

.game-overlay-hint {
	margin: 0;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}
</style>
