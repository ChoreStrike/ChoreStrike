<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<!-- Four key figures in one row (CS-PLAN-090 WP-E), from the one house
	     read Insights makes: house health first (D-090-11), then the rooms. -->
	<div class="house-stats-cards" data-testid="house-stats-cards">
		<div class="stats-card health" data-testid="stat-house-health">
			<span class="stats-label">{{ t('chorestrike', 'House health') }}</span>
			<span class="stats-value">{{ stats.houseHealth ?? 100 }}%</span>
		</div>
		<div class="stats-card clean" data-testid="stat-clean-rooms">
			<span class="stats-label">{{ t('chorestrike', 'Clean') }}</span>
			<span class="stats-value">{{ stats.cleanRooms ?? 0 }} / {{ stats.totalRooms ?? 0 }}</span>
		</div>
		<div class="stats-card dirty" data-testid="stat-dirty-rooms">
			<span class="stats-label">{{ t('chorestrike', 'Dirty') }}</span>
			<span class="stats-value">{{ stats.dirtyRooms ?? 0 }}</span>
		</div>
		<div class="stats-card attention" data-testid="stat-needing-cleaning">
			<span class="stats-label">{{ t('chorestrike', 'Need cleaning') }}</span>
			<span class="stats-value">{{ stats.roomsNeedingCleaning ?? 0 }}</span>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'HouseStatsCards',

	props: {
		stats: {
			type: Object,
			required: true,
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.house-stats-cards {
	display: grid;
	grid-template-columns: repeat(4, minmax(0, 1fr));
	gap: 0.4rem;
}

.stats-card {
	display: flex;
	flex-direction: column;
	justify-content: space-between;
	gap: 0.1rem;
	min-width: 0;
	padding: 0.4rem 0.5rem;
	background: var(--color-background-dark);
	border-radius: 10px;
}

.stats-value {
	font-weight: bold;
	color: var(--color-main-text);
}

.stats-label {
	font-size: 0.75rem;
	line-height: 1.2;
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.stats-card.clean .stats-value {
	color: var(--color-success-text);
}

.stats-card.dirty .stats-value {
	color: var(--color-warning-text);
}

/* Not `.warning`: Nextcloud styles that class globally. */
.stats-card.attention .stats-value {
	color: var(--color-error-text);
}

/* House health keeps the main text colour: at this size the primary colour
   reaches only 4.38:1 in the dark theme. */
</style>
