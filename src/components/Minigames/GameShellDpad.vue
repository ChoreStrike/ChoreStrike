<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="game-dpad" data-testid="game-dpad">
		<button v-for="control in CONTROLS"
			:key="control.direction"
			type="button"
			class="game-dpad-button"
			:class="control.direction"
			:aria-label="control.label()"
			@click="$emit('direction', control.direction)">
			<span aria-hidden="true">{{ control.glyph }}</span>
		</button>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'

/**
 * On-screen direction pad of the `dpad` input mode (CS-PLAN-086 §3.4):
 * labelled 52 px buttons, shown on touch devices and narrow screens.
 */
defineEmits(['direction'])

const CONTROLS = [
	{ direction: 'up', glyph: '▲', label: () => t('chorestrike', 'Up') },
	{ direction: 'left', glyph: '◀', label: () => t('chorestrike', 'Left') },
	{ direction: 'down', glyph: '▼', label: () => t('chorestrike', 'Down') },
	{ direction: 'right', glyph: '▶', label: () => t('chorestrike', 'Right') },
]
</script>

<style scoped>
.game-dpad {
	display: none;
	grid-template-areas: ". up ." "left down right";
	gap: 0.5rem;
}

.game-dpad-button {
	width: 52px;
	height: 52px;
	border: 1px solid var(--color-border-maxcontrast, var(--color-border));
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-background-dark);
	color: var(--color-main-text);
	font-size: 1.25rem;
	cursor: pointer;
}

.game-dpad-button.up {
	grid-area: up;
}

.game-dpad-button.left {
	grid-area: left;
}

.game-dpad-button.down {
	grid-area: down;
}

.game-dpad-button.right {
	grid-area: right;
}

@media (pointer: coarse), (max-width: 480px) {
	.game-dpad {
		display: grid;
	}
}
</style>
