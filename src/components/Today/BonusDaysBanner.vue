<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div v-if="running"
		class="bonus-days"
		data-testid="today-bonus-days"
		role="status">
		<Icon name="Star" :size="18" class="bonus-days-icon" />
		<p class="bonus-days-text">
			{{ text }}
		</p>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'
import Icon from '../Common/Icon.vue'
import { formatDayKey } from '../../utils/dateKeys.js'

/**
 * The running bonus days on Today (CS-PLAN-091 WP-B5, ADR-021 decision
 * 11): while a period runs, everyone's coins count × 1.5. Only a running
 * period shows; a planned one is the owner's to see in the settings.
 */
const props = defineProps({
	/** `{ id, startsOn, endsOn }` — the running or next period of the house, or null. */
	period: { type: Object, default: null },
	/** Today as a house-local day key (`YYYY-MM-DD`). */
	today: { type: String, required: true },
})

const running = computed(() => props.period !== null
	&& props.period.startsOn <= props.today && props.today <= props.period.endsOn)

const text = computed(() => (props.period?.endsOn === props.today
	? t('chorestrike', 'Bonus day: coins count × 1.5 today.')
	: t('chorestrike', 'Bonus days: coins count × 1.5 until {date}.', { date: formatDayKey(props.period?.endsOn ?? '') })))
</script>

<style scoped>
.bonus-days {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	padding: 0.6rem 1rem;
	margin-bottom: 0.75rem;
	border: 1px solid var(--color-border);
	border-inline-start: 3px solid var(--cs-energy);
	border-radius: 10px;
	background: var(--color-background-dark);
}

.bonus-days-icon {
	flex-shrink: 0;
	color: var(--cs-energy);
}

.bonus-days-text {
	margin: 0;
	color: var(--color-main-text);
	font-size: 0.9rem;
	font-weight: 500;
}
</style>
