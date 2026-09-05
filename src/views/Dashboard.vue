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

		<!-- Initialization failed entirely (CS-PLAN-044, FEAT-UX-031) -->
		<ErrorState
			v-else-if="initError"
			data-testid="dashboard-error"
			:message="t('chorestrike', 'Could not load your household')"
			@retry="initializeDashboard" />

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
					<button v-if="roomsStore.floors.length === 0 && userStore.isHouseOwner"
						class="primary-button setup-entry-button"
						data-testid="open-setup-wizard"
						@click="showWizard = true">
						{{ t('chorestrike', 'Set up your home') }}
					</button>
				</div>
				<HouseHealthBar
					:health="houseHealth"
					:size="'large'"
					data-testid="house-health" />
			</header>

			<main class="dashboard-content">
				<HouseholdGoalBar class="household-goal-section" data-testid="household-goal-section" />
				<QuickStats
					class="quick-stats-section"
					data-testid="quick-stats"
					@toggle-private-filter="handleTogglePrivateFilter" />
				<ArcadeSection class="arcade-section" data-testid="arcade-section" />
				<FloorList class="floor-list-section" data-testid="floor-list" />
			</main>
		</template>

		<SetupWizard
			v-if="showWizard"
			@applied="handleWizardApplied"
			@skipped="handleWizardSkipped"
			@close="closeWizard" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { useRouter } from 'vue-router'
import { useHouseStore } from '../store/house.js'
import { useRoomsStore } from '../store/rooms.js'
import { useGameStore } from '../store/game.js'
import { useUserStore } from '../store/user.js'
import { logger } from '../utils/logger.js'
import Icon from '../components/Common/Icon.vue'
import ErrorState from '../components/Common/ErrorState.vue'
import HouseHealthBar from '../components/Dashboard/HouseHealthBar.vue'
import HouseholdGoalBar from '../components/Dashboard/HouseholdGoalBar.vue'
import QuickStats from '../components/Dashboard/QuickStats.vue'
import FloorList from '../components/Dashboard/FloorList.vue'
import ArcadeSection from '../components/Dashboard/ArcadeSection.vue'
import SetupWizard, { isWizardDismissedForHouse } from '../components/Setup/SetupWizard.vue'

export default {
	name: 'Dashboard',

	components: {
		Icon,
		ErrorState,
		HouseHealthBar,
		HouseholdGoalBar,
		QuickStats,
		FloorList,
		ArcadeSection,
		SetupWizard,
	},

	setup() {
		const houseStore = useHouseStore()
		const roomsStore = useRoomsStore()
		const gameStore = useGameStore()
		const userStore = useUserStore()
		const router = useRouter()
		return { houseStore, roomsStore, gameStore, userStore, router }
	},

	data() {
		return {
			showWizard: false,
			wizardDismissed: false,
			initError: false,
		}
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
			this.initError = false
			// Fetch house, floors, and game state in parallel — they are
			// independent. The game state call also runs the weekly-goal
			// computation, so overlapping it with the house/floor fetches
			// keeps Dashboard mount latency at the slowest call rather than
			// the sum.
			const results = await Promise.allSettled([
				this.houseStore.fetchHouse(),
				this.roomsStore.fetchFloorsWithRooms(),
				this.gameStore.fetchGameState(),
			])

			// Surface any non-fatal failures to the logger. The Dashboard
			// renders whatever state the stores currently hold, so a single
			// failed fetch doesn't break the others — but if everything
			// failed there is nothing to render (ErrorState + retry).
			const labels = ['fetchHouse', 'fetchFloorsWithRooms', 'fetchGameState']
			let failures = 0
			results.forEach((result, index) => {
				if (result.status === 'rejected') {
					failures++
					logger.error(
						`Dashboard fetch failed: ${labels[index]}`,
						result.reason,
						{ view: 'Dashboard', action: labels[index] },
					)
				}
			})
			if (failures === results.length) {
				this.initError = true
				return
			}

			// First-run detection (D3): a house with no floors is brand new.
			// Open the setup wizard automatically — only for the OWNER
			// (CS-PLAN-061 §3.9: a non-owner member of an empty house cannot
			// run the wizard; it would hit 403s on apply) — unless "Start
			// empty" was dismissed for this house in any previous session
			// (UX-011) or this session. The manual entry point (header
			// button) stays available for owners while the house is empty.
			if (this.userStore.isHouseOwner
				&& !this.wizardDismissed
				&& !isWizardDismissedForHouse(this.houseStore.house?.id ?? null)
				&& this.roomsStore.floors.length === 0) {
				this.showWizard = true
			}
		},
		handleWizardApplied() {
			this.showWizard = false
			this.wizardDismissed = true
			// The wizard created floors/rooms/tasks server-side and set the
			// house name inside the setup transaction (CS-PLAN-062 §3.2);
			// reload the structural view, the game state, and the house
			// payload so the Dashboard reflects all of it.
			Promise.allSettled([
				this.roomsStore.fetchFloorsWithRooms(),
				this.gameStore.fetchGameState(),
				this.houseStore.fetchHouse(),
			]).then(results => {
				results.forEach((result, index) => {
					if (result.status === 'rejected') {
						logger.error('Post-wizard reload failed', result.reason, { view: 'Dashboard', action: 'handleWizardApplied', step: index })
					}
				})
			})
		},
		handleWizardSkipped() {
			this.showWizard = false
			this.wizardDismissed = true
		},
		closeWizard() {
			this.showWizard = false
		},
		/**
		 * "My Private Tasks" chip (CS-PLAN-041): the Dashboard renders no task
		 * list of its own, so the binding routes to the Task Manager
		 * pre-filtered to the current user's private tasks — a client-side
		 * scope mechanism consistent with TodayView's My-tasks scope.
		 */
		handleTogglePrivateFilter() {
			this.router.push({ name: 'tasks', query: { visibility: 'private' } })
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
	color: var(--color-primary-element);
}

h1 {
	font-size: 2rem;
	font-weight: bold;
	margin: 0 0 0.5rem 0;
}

.tagline {
	font-size: 1.1rem;
	color: var(--color-text-maxcontrast);
	margin-bottom: 0;
}

.setup-entry-button {
	margin-top: 0.75rem;
}

.dashboard-content {
	display: flex;
	flex-direction: column;
	gap: 2rem;
}

.quick-stats-section {
	margin-bottom: 1rem;
}

.household-goal-section {
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
