<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="socks-board"
		:class="{ 'is-calm': !motion }"
		:style="{ gridTemplateColumns: `repeat(${cols}, 1fr)` }"
		aria-hidden="true"
		@pointerdown="onPointerDown">
		<div v-for="(card, index) in view.cards"
			:key="index"
			class="socks-card"
			:class="[`is-${card.face}`, { 'is-cursor': index === view.cursor }]"
			:data-cell="index"
			:data-face="card.face"
			:data-pattern="card.pattern">
			<svg class="socks-item"
				viewBox="0 0 48 48"
				focusable="false">
				<template v-if="card.pattern">
					<path class="socks-sock" :d="SOCK" />
					<path class="socks-emblem" :d="CUFF + EMBLEMS[card.pattern]" />
					<path v-if="card.face === 'matched'" class="socks-check" d="M33 8.5l3 3 6.5-6.5" />
				</template>
				<path v-else class="socks-basket" :d="BASKET" />
			</svg>
		</div>
	</div>
</template>

<script setup>
/**
 * Sock Match's laundry basket (CS-PLAN-086 §3.1): a DOM grid of cards
 * driven by the logic's `view()`. Pointer input on the board reports the
 * tapped card; the keyboard stays with the game shell (arrows move the
 * cursor, Enter turns). Decorative for assistive technology — the shell's
 * live region carries score and state. Patterns differ by shape, never by
 * color alone: stripes, dots, star, heart, diamond, triangle, checks, plus.
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

const SOCK = 'M13 4h18v23l8.5 8.5a6 6 0 0 1-8.5 8.5L16 31.5A10 10 0 0 1 13 24.5z'
const CUFF = 'M13 4h18v4H13z'
const EMBLEMS = Object.freeze({
	stripes: 'M13 12h18v3.5H13zM13 19h18v3.5H13z',
	dots: 'M18.5 11a3 3 0 1 1 0 6a3 3 0 1 1 0-6zM25.5 18a3 3 0 1 1 0 6a3 3 0 1 1 0-6z',
	star: 'M22 10.5l1.5 4.4 4.7.1-3.7 2.8 1.3 4.5-3.8-2.7-3.8 2.7 1.3-4.5-3.7-2.8 4.7-.1z',
	heart: 'M22 23.5l-5.2-5.1a3.3 3.3 0 0 1 5.2-4.2a3.3 3.3 0 0 1 5.2 4.2z',
	diamond: 'M22 10.5l5.5 6.5-5.5 6.5-5.5-6.5z',
	triangle: 'M22 11l6.5 11.5h-13z',
	checks: 'M16 11h6v6h-6zM22 17h6v6h-6z',
	plus: 'M20 11h4v4.5h4.5v4H24V24h-4v-4.5h-4.5v-4H20z',
})
const BASKET = 'M15 19a9 9 0 0 1 18 0h-3a6 6 0 0 0-12 0zM9 20h30l-3.5 16.5A3 3 0 0 1 32.6 39H15.4a3 3 0 0 1-2.9-2.5zm6 4v3h4v-3zm7 0v3h4v-3zm7 0v3h4v-3zm-12 6v3h4v-3zm8 0v3h4v-3z'

function onPointerDown(event) {
	const card = event.target.closest?.('[data-cell]')
	if (card) emit('tap', Number(card.dataset.cell))
}
</script>

<style scoped>
.socks-board {
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

.socks-card {
	display: flex;
	align-items: center;
	justify-content: center;
	box-sizing: border-box;
	border: 2px solid transparent;
	border-radius: 6px;
	background: var(--color-main-background);
	cursor: pointer;
}

.socks-card.is-down {
	background: var(--color-background-dark);
}

.socks-card.is-miss {
	border: 2px dashed var(--color-text-maxcontrast);
}

.socks-card.is-matched {
	background: var(--color-primary-element-light, var(--color-background-dark));
	cursor: default;
}

.socks-card.is-cursor {
	outline: 3px solid var(--color-primary-element);
	outline-offset: -3px;
}

.socks-item {
	width: 75%;
	height: 75%;
	pointer-events: none;
}

.socks-sock {
	fill: var(--color-primary-element);
}

.socks-emblem {
	fill: var(--color-primary-element-text);
}

.socks-check {
	fill: none;
	stroke: var(--color-primary-element);
	stroke-width: 3;
	stroke-linecap: round;
	stroke-linejoin: round;
}

.socks-basket {
	fill: var(--color-text-maxcontrast);
	fill-rule: evenodd;
}

.socks-board:not(.is-calm) .is-up .socks-item,
.socks-board:not(.is-calm) .is-miss .socks-item {
	animation: socks-turn 180ms ease-out;
}

.socks-board:not(.is-calm) .is-matched .socks-item {
	animation: socks-pair 250ms ease-out;
}

@keyframes socks-turn {
	from {
		transform: scaleX(0.1);
	}

	to {
		transform: scaleX(1);
	}
}

@keyframes socks-pair {
	from {
		transform: scale(1.15);
	}

	to {
		transform: scale(1);
	}
}
</style>
