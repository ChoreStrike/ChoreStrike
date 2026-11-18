<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<section v-if="rows.length > 0" class="bonus-section" data-testid="today-section-bonus">
		<div class="bonus-heading">
			<h2>{{ t('chorestrike', 'Bonus · {count}', { count: rows.length }) }}</h2>
			<span class="bonus-hint">{{ t('chorestrike', 'Voluntary. Counts once per person and day.') }}</span>
		</div>
		<TodayBonusRow
			v-for="item in rows"
			:key="item.roomTask.id"
			:item="item"
			@complete="(row, split) => emit('complete', row, split)" />
	</section>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import TodayBonusRow from './TodayBonusRow.vue'

/**
 * The bonus tasks of Today in their own section (CS-PLAN-091 WP-B4,
 * simplified core: "the bonus tasks"): never due and never part of what is
 * planned for the day, so the section stands apart from the day's work and
 * shows only when there is a bonus task to offer.
 */
defineProps({
	/** The bonus rows the view's filter lets through. */
	rows: { type: Array, required: true },
})

const emit = defineEmits(['complete'])
</script>

<style scoped>
.bonus-section {
	margin-bottom: 1.25rem;
}

.bonus-heading {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: 0.25rem 1rem;
}

.bonus-heading h2 {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	font-weight: 700;
	letter-spacing: 0.04em;
	text-transform: uppercase;
}

.bonus-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}
</style>
