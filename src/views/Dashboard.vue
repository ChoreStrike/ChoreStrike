<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="dashboard" data-testid="dashboard-view">
		<header class="dashboard-header">
			<div class="header-content">
				<h1 data-testid="house-name">
					{{ houseName }}
				</h1>
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
			<FloorList class="floor-list-section" data-testid="floor-list" />
		</main>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { useHouseStore } from '../store/house.js'
import { useRoomsStore } from '../store/rooms.js'
import HouseHealthBar from '../components/Dashboard/HouseHealthBar.vue'
import QuickStats from '../components/Dashboard/QuickStats.vue'
import FloorList from '../components/Dashboard/FloorList.vue'

export default {
	name: 'Dashboard',

	components: {
		HouseHealthBar,
		QuickStats,
		FloorList,
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
				console.error('Failed to initialize dashboard:', error)
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
</style>
