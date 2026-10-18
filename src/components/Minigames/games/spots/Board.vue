<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="spots-board"
		:class="{ 'is-double': view.double, 'is-calm': !motion }"
		:style="{ gridTemplateColumns: `repeat(${cols}, 1fr)` }"
		aria-hidden="true"
		@pointerdown="onPointerDown">
		<div v-for="(kind, index) in view.tiles"
			:key="index"
			class="spots-tile"
			:class="[`is-${kind}`, { 'is-cursor': index === view.cursor }]"
			:data-cell="index"
			:data-kind="kind">
			<svg v-if="SHAPES[kind]"
				class="spots-item"
				viewBox="0 0 48 48"
				focusable="false">
				<path :d="SHAPES[kind]" />
			</svg>
		</div>
	</div>
</template>

<script setup>
/**
 * Spot Sprint's floor (CS-PLAN-086 §3.1): a DOM grid of tiles driven by the
 * logic's `view()`. Pointer input on the board reports the tapped tile; the
 * keyboard stays with the game shell (arrows move the cursor, Enter
 * wipes). Decorative for assistive technology — the shell's live region
 * carries score and state. Items are drawn shapes, so they keep their form
 * without color: a round stain, a drying ring, a rectangular sponge, a sock.
 */
defineProps({
	view: {
		type: Object,
		required: true,
	},
	cols: {
		type: Number,
		required: true,
	},
	motion: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['tap'])

const STAIN = 'M24 7c7 0 9 6 14 8s5 9 1 13 1 10-6 12-9-1-14 1-11-3-12-9 3-9 1-14 9-11 16-11z'
const SHAPES = Object.freeze({
	stain: STAIN,
	drying: STAIN,
	golden: 'M9 12h30a4 4 0 0 1 4 4v16a4 4 0 0 1-4 4H9a4 4 0 0 1-4-4V16a4 4 0 0 1 4-4zm6 7a3 3 0 1 0 0 .1zm12 2a2 2 0 1 0 0 .1zm-7 8a2.5 2.5 0 1 0 0 .1zm13 1a3 3 0 1 0 0 .1z',
	sock: 'M17 5h14v20l9 9a6 6 0 0 1-8 9l-13-11a7 7 0 0 1-2-5z',
	wiped: 'M24 8l3 10 10 3-10 3-3 10-3-10-10-3 10-3zM37 32l1.5 4 4 1.5-4 1.5-1.5 4-1.5-4-4-1.5 4-1.5z',
})

function onPointerDown(event) {
	const tile = event.target.closest?.('[data-cell]')
	if (tile) emit('tap', Number(tile.dataset.cell))
}
</script>

<style scoped>
.spots-board {
	display: grid;
	gap: 4px;
	width: 100%;
	height: 100%;
	padding: 4px;
	box-sizing: border-box;
	background: var(--color-border);
	user-select: none;
	-webkit-user-select: none;
}

.spots-board.is-double {
	background: var(--cs-celebrate-gold, #FDCB6E);
}

.spots-tile {
	display: flex;
	align-items: center;
	justify-content: center;
	background: var(--color-background-dark);
	border-radius: 6px;
	cursor: pointer;
}

.spots-tile.is-cursor {
	outline: 3px solid var(--color-primary-element);
	outline-offset: -3px;
}

.spots-item {
	width: 70%;
	height: 70%;
	pointer-events: none;
}

.is-stain .spots-item path {
	fill: var(--cs-celebrate-bronze, #CD7F32);
}

.is-drying .spots-item path {
	fill: none;
	stroke: var(--cs-celebrate-bronze, #CD7F32);
	stroke-width: 3;
	stroke-dasharray: 4 3;
}

.is-golden .spots-item path {
	fill: var(--cs-celebrate-gold, #FDCB6E);
	fill-rule: evenodd;
	stroke: var(--color-main-text);
	stroke-width: 1.5;
}

.is-sock .spots-item path {
	fill: var(--color-primary-element);
}

.is-wiped .spots-item path {
	fill: var(--cs-celebrate-gold, #FDCB6E);
}

.spots-board:not(.is-calm) .is-wiped .spots-item {
	animation: spots-sparkle 250ms ease-out;
}

.spots-board.is-calm .is-wiped .spots-item {
	visibility: hidden;
}

@keyframes spots-sparkle {
	from {
		opacity: 1;
		transform: scale(0.6);
	}

	to {
		opacity: 0;
		transform: scale(1.2);
	}
}
</style>
