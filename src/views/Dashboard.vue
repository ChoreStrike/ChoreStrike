<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="dashboard" data-testid="dashboard-view">
		<!-- Loading State -->
		<div v-if="isLoading" class="dashboard-loading">
			<div class="skeleton skeleton-header" />
			<div class="skeleton skeleton-health" />
			<div class="skeleton skeleton-stats" />
			<div class="skeleton skeleton-floors" />
		</div>

		<!-- Loaded Content -->
		<template v-else>
			<header class="dashboard-header">
				<div class="header-content">
					<div class="house-title-row">
						<div class="house-icon-shell" aria-hidden="true">
							<Icon name="House" :size="30" class="house-title-icon" />
						</div>
						<h1 data-testid="house-name">
							{{ houseName }}
						</h1>
					</div>
					<p class="tagline">
						{{ t('chorestrike', 'Defend your Home Base. Together.') }}
					</p>
				</div>
				<HouseHealthBar
					:health="houseHealth"
					:size="'large'"
					data-testid="house-health" />
			</header>

			<main class="dashboard-content">
				<QuickStats class="quick-stats-section" data-testid="quick-stats" />
				<ArcadeSection class="arcade-section" data-testid="arcade-section" />
				<FloorList class="floor-list-section" data-testid="floor-list" />
			</main>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { useHouseStore } from '../store/house.js'
import { useRoomsStore } from '../store/rooms.js'
import { logger } from '../utils/logger.js'
import Icon from '../components/Common/Icon.vue'
import HouseHealthBar from '../components/Dashboard/HouseHealthBar.vue'
import QuickStats from '../components/Dashboard/QuickStats.vue'
import FloorList from '../components/Dashboard/FloorList.vue'
import ArcadeSection from '../components/Dashboard/ArcadeSection.vue'

export default {
	name: 'Dashboard',

	components: {
		Icon,
		HouseHealthBar,
		QuickStats,
		FloorList,
		ArcadeSection,
	},

	setup() {
		const houseStore = useHouseStore()
		const roomsStore = useRoomsStore()
		return { houseStore, roomsStore }
	},

	computed: {
		houseName() {
			return this.houseStore.houseName
		},
		houseHealth() {
			return this.houseStore.houseHealth
		},
		isLoading() {
			return this.houseStore.loading || this.roomsStore.loading
		},
	},

	async mounted() {
		await this.initializeDashboard()
	},

	methods: {
		t,
		async initializeDashboard() {
			try {
				await this.houseStore.fetchHouse()
				await this.roomsStore.fetchFloorsWithRooms()
			} catch (error) {
				logger.error('Failed to initialize dashboard', error, { view: 'Dashboard', action: 'initializeDashboard' })
			}
		},
	},
}
</script>

<style scoped>
.dashboard {
	padding: 1rem 2rem 2rem;
	max-width: 1400px;
	margin: 0 auto;
}

.dashboard-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 2rem;
	gap: 2rem;
	flex-wrap: wrap;
}

.header-content {
	flex: 1;
}

.house-title-row {
	display: flex;
	align-items: center;
	gap: 1rem;
	margin-bottom: 0.5rem;
}

.house-icon-shell {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 3.25rem;
	height: 3.25rem;
	border-radius: 1rem;
	background: linear-gradient(135deg, rgba(87, 181, 255, 0.18), rgba(136, 84, 255, 0.26));
	border: 1px solid rgba(87, 181, 255, 0.28);
	box-shadow: 0 10px 24px rgba(87, 181, 255, 0.12);
}

.house-title-icon {
	color: #6ec8ff;
}

h1 {
	font-size: 2rem;
	font-weight: bold;
	margin: 0 0 0.5rem 0;
}

.tagline {
	font-size: 1.1rem;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.dashboard-content {
	display: flex;
	flex-direction: column;
	gap: 2rem;
}

.quick-stats-section {
	margin-bottom: 1rem;
}

.floor-list-section {
	flex: 1;
}

@media (max-width: 768px) {
	.house-title-row {
		gap: 0.75rem;
	}

	.house-icon-shell {
		width: 2.75rem;
		height: 2.75rem;
		border-radius: 0.85rem;
	}
}

/* Skeleton Loading States */
.dashboard-loading {
	padding: 1rem 0;
}

.skeleton {
	background: linear-gradient(90deg, var(--color-background-dark) 25%, var(--color-background) 50%, var(--color-background-dark) 75%);
	background-size: 200% 100%;
	animation: skeleton-loading 1.5s infinite;
	border-radius: var(--border-radius-large);
}

.skeleton-header {
	width: 200px;
	height: 2rem;
	margin-bottom: 0.5rem;
}

.skeleton-health {
	width: 300px;
	height: 24px;
	margin-bottom: 2rem;
}

.skeleton-stats {
	width: 100%;
	height: 100px;
	margin-bottom: 2rem;
}

.skeleton-floors {
	width: 100%;
	height: 300px;
}

@keyframes skeleton-loading {
	0% {
		background-position: 200% 0;
	}
	100% {
		background-position: -200% 0;
	}
}
</style>
