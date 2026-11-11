<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<section class="status-line" data-testid="today-status-line" :aria-label="t('chorestrike', 'My status')">
		<div class="status-tile" :class="{ over: overCapacity }" data-testid="today-energy">
			<span class="status-label">{{ t('chorestrike', 'Energy') }}</span>
			<span class="status-value">{{ energyLabel }}</span>
		</div>
		<div class="status-tile" data-testid="today-weekly-goal">
			<span class="status-label">{{ t('chorestrike', 'Weekly goal') }}</span>
			<span class="status-value">{{ weeklyGoalLabel }}</span>
		</div>
		<div class="status-tile" data-testid="today-streak">
			<span class="status-label">{{ t('chorestrike', 'Streak') }}</span>
			<span class="status-value">{{ streakLabel }}</span>
			<span v-if="rhythm?.pending" class="status-note">{{ t('chorestrike', 'today pending') }}</span>
		</div>
		<div class="status-tile" data-testid="today-coins">
			<span class="status-label">{{ t('chorestrike', 'Coins') }}</span>
			<span class="status-value" data-testid="own-wallet-available">{{ coinsLabel }}</span>
		</div>
	</section>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'
import { formatCoinMinor } from '../../utils/minorUnits.js'
import { daysLabel } from '../../utils/taskDisplay.js'

/**
 * The viewer's own status on Today (CS-PLAN-090 WP-B): open work against
 * today's energy, the shared weekly goal, the confirmed streak and the
 * available coins. Pure presentation of server facts; a value that no read
 * has delivered shows a dash instead of a zero.
 */
const props = defineProps({
	/** `{ planned, capacity }` in energy units, or null while unknown. */
	energy: { type: Object, default: null },
	/** The weekly-goal snapshot (`percent`), or null while unknown. */
	weeklyGoal: { type: Object, default: null },
	/** The rhythm projection (`confirmed`, `pending`), or null while unknown. */
	rhythm: { type: Object, default: null },
	/** Available coins in minor units, or null while unknown. */
	coinMinor: { type: Number, default: null },
})

const UNKNOWN = '–'

const overCapacity = computed(() => props.energy !== null && props.energy.planned > props.energy.capacity)
const energyLabel = computed(() => (props.energy
	? t('chorestrike', '{planned} of {capacity}', props.energy)
	: UNKNOWN))
const weeklyGoalLabel = computed(() => (props.weeklyGoal
	? t('chorestrike', '{percent}%', { percent: props.weeklyGoal.percent })
	: UNKNOWN))
const streakLabel = computed(() => (props.rhythm ? daysLabel(props.rhythm.confirmed) : UNKNOWN))
const coinsLabel = computed(() => (props.coinMinor === null ? UNKNOWN : formatCoinMinor(props.coinMinor)))
</script>

<style scoped>
.status-line {
	display: grid;
	grid-template-columns: repeat(4, minmax(0, 1fr));
	gap: 0.4rem;
	margin-bottom: 0.75rem;
}

.status-tile {
	display: flex;
	flex-direction: column;
	gap: 0.1rem;
	min-width: 0;
	padding: 0.4rem 0.5rem;
	border-radius: 10px;
	background: var(--color-background-dark);
}

.status-label,
.status-note {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.status-value {
	font-weight: 700;
	color: var(--color-main-text);
	font-variant-numeric: tabular-nums;
	overflow-wrap: anywhere;
}

/* More open work than today's energy: the neutral note below the line
   explains it, the tile only marks where the amount is. */
.status-tile.over {
	box-shadow: inset 0 0 0 2px var(--color-element-warning);
}
</style>
