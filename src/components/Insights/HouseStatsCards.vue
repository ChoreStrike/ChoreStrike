<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="house-stats-cards" data-testid="house-stats-cards">
		<div class="stats-card" data-testid="stat-total-rooms">
			<span class="stats-value">{{ stats.totalRooms ?? 0 }}</span>
			<span class="stats-label">{{ t('chorestrike', 'Rooms') }}</span>
		</div>
		<div class="stats-card clean" data-testid="stat-clean-rooms">
			<span class="stats-value">{{ stats.cleanRooms ?? 0 }}</span>
			<span class="stats-label">{{ t('chorestrike', 'Clean') }}</span>
		</div>
		<div class="stats-card dirty" data-testid="stat-dirty-rooms">
			<span class="stats-value">{{ stats.dirtyRooms ?? 0 }}</span>
			<span class="stats-label">{{ t('chorestrike', 'Dirty') }}</span>
		</div>
		<div class="stats-card warning" data-testid="stat-needing-cleaning">
			<span class="stats-value">{{ stats.roomsNeedingCleaning ?? 0 }}</span>
			<span class="stats-label">{{ t('chorestrike', 'Need cleaning') }}</span>
		</div>
		<div class="stats-card health" data-testid="stat-house-health">
			<span class="stats-value">{{ stats.houseHealth ?? 100 }}%</span>
			<span class="stats-label">{{ t('chorestrike', 'House health') }}</span>
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
	grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
	gap: 1rem;
}

.stats-card {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0.25rem;
	padding: 1.25rem 1rem;
	background: var(--color-background-dark);
	border-radius: 12px;
	text-align: center;
}

.stats-value {
	font-size: 1.75rem;
	font-weight: bold;
	color: var(--color-main-text);
}

.stats-label {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.stats-card.clean .stats-value {
	color: var(--color-success);
}

.stats-card.dirty .stats-value {
	color: var(--color-warning, #f39c12);
}

.stats-card.warning .stats-value {
	color: var(--color-error);
}

.stats-card.health .stats-value {
	color: var(--color-primary);
}
</style>
