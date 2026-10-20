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
				<path class="spots-body" :d="SHAPES[kind].body" />
				<path v-if="SHAPES[kind].detail" class="spots-detail" :d="SHAPES[kind].detail" />
			</svg>
			<template v-if="kind === 'wiped'">
				<span class="spots-bubble" />
				<span class="spots-bubble" />
				<span class="spots-bubble" />
			</template>
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
 * without color: a round stain with a sheen, a drying stain with a crack
 * and a dashed edge, a rectangular sponge, a striped sock. With the motion
 * opt-in new items pop in, the sponge glows, the sock swings and a wiped
 * tile sends up soap bubbles.
 */
defineOptions({ name: 'SpotsBoard' })

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
	stain: { body: STAIN, detail: 'M19 16c3-3 7-3 10-1' },
	drying: { body: STAIN, detail: 'M17 25l6-4 4 6 6-3' },
	golden: { body: 'M9 12h30a4 4 0 0 1 4 4v16a4 4 0 0 1-4 4H9a4 4 0 0 1-4-4V16a4 4 0 0 1 4-4zm6 7a3 3 0 1 0 0 .1zm12 2a2 2 0 1 0 0 .1zm-7 8a2.5 2.5 0 1 0 0 .1zm13 1a3 3 0 1 0 0 .1z', detail: null },
	sock: { body: 'M17 5h14v20l9 9a6 6 0 0 1-8 9l-13-11a7 7 0 0 1-2-5z', detail: 'M17 11h14v3H17zM17 17h14v3H17z' },
	wiped: { body: 'M24 8l3 10 10 3-10 3-3 10-3-10-10-3 10-3zM37 32l1.5 4 4 1.5-4 1.5-1.5 4-1.5-4-4-1.5 4-1.5z', detail: null },
})

function onPointerDown(event) {
	const tile = event.target.closest?.('[data-cell]')
	if (tile) {
		emit('tap', Number(tile.dataset.cell))
	}
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
	background: var(--cs-arcade-grout, var(--color-border));
	user-select: none;
	-webkit-user-select: none;
}

.spots-board.is-double {
	background: var(--cs-arcade-yellow, #FDCB6E);
}

.spots-tile {
	position: relative;
	display: flex;
	align-items: center;
	justify-content: center;
	overflow: hidden;
	background: var(--cs-arcade-floor-alt, var(--color-background-dark));
	box-shadow: inset 0 0 0 2px var(--cs-arcade-floor, var(--color-main-background));
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

.is-stain .spots-body {
	fill: var(--cs-arcade-cocoa, #6D4C3D);
}

.is-stain .spots-detail {
	fill: none;
	stroke: var(--cs-arcade-tan, #B08968);
	stroke-width: 2.5;
	stroke-linecap: round;
}

.is-drying .spots-body {
	fill: var(--cs-arcade-tan, #B08968);
	fill-opacity: 0.45;
	stroke: var(--cs-arcade-cocoa, #6D4C3D);
	stroke-width: 2;
	stroke-dasharray: 4 3;
}

.is-drying .spots-detail {
	fill: none;
	stroke: var(--cs-arcade-cocoa, #6D4C3D);
	stroke-width: 2;
	stroke-linecap: round;
}

.is-golden .spots-body {
	fill: var(--cs-arcade-yellow, #FDCB6E);
	fill-rule: evenodd;
	stroke: var(--cs-arcade-ink, #10233D);
	stroke-width: 1.5;
}

.is-sock .spots-body {
	fill: var(--cs-arcade-coral, #FF7675);
}

.is-sock .spots-detail {
	fill: var(--cs-arcade-paper, #FFFFFF);
}

.is-wiped .spots-body {
	fill: var(--cs-arcade-yellow, #FDCB6E);
}

.spots-bubble {
	position: absolute;
	bottom: 30%;
	inset-inline-start: 50%;
	width: 18%;
	height: 18%;
	box-sizing: border-box;
	border: 2px solid var(--cs-arcade-teal, #00CEC9);
	border-radius: 50%;
	opacity: 0;
}

.spots-bubble:nth-of-type(1) {
	margin-inline-start: -35%;
}

.spots-bubble:nth-of-type(2) {
	width: 24%;
	height: 24%;
	margin-inline-start: -10%;
	animation-delay: 60ms;
}

.spots-bubble:nth-of-type(3) {
	margin-inline-start: 15%;
	animation-delay: 120ms;
}

.spots-board:not(.is-calm) .is-stain .spots-item {
	animation: spots-pop 180ms ease-out;
}

.spots-board:not(.is-calm) .is-golden .spots-item {
	animation: spots-pop 180ms ease-out, spots-glow 900ms ease-in-out 180ms infinite alternate;
}

.spots-board:not(.is-calm) .is-sock .spots-item {
	animation: spots-pop 180ms ease-out, spots-swing 1.2s ease-in-out 180ms infinite alternate;
}

.spots-board:not(.is-calm) .is-wiped .spots-item {
	animation: spots-sparkle 250ms ease-out;
}

.spots-board:not(.is-calm) .spots-bubble {
	animation: spots-bubble 420ms ease-out forwards;
}

.spots-board.is-calm .is-wiped .spots-item,
.spots-board.is-calm .spots-bubble {
	visibility: hidden;
}

@keyframes spots-pop {
	from {
		transform: scale(0.3);
	}

	to {
		transform: scale(1);
	}
}

@keyframes spots-glow {
	from {
		transform: scale(1);
	}

	to {
		transform: scale(1.12);
	}
}

@keyframes spots-swing {
	from {
		transform: rotate(-8deg);
	}

	to {
		transform: rotate(8deg);
	}
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

@keyframes spots-bubble {
	from {
		opacity: 1;
		transform: translateY(0) scale(0.6);
	}

	to {
		opacity: 0;
		transform: translateY(-140%) scale(1.1);
	}
}
</style>
