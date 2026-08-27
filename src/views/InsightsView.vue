<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="insights-view" data-testid="insights-view">
		<header class="insights-header">
			<h1 data-testid="insights-title">
				{{ t('chorestrike', 'Insights') }}
			</h1>
			<p class="subtitle">
				{{ t('chorestrike', 'How your household is really doing') }}
			</p>
		</header>

		<div v-if="loading" class="insights-loading" data-testid="insights-loading">
			<div class="skeleton skeleton-header" />
			<div class="skeleton skeleton-section" />
			<div class="skeleton skeleton-section" />
		</div>

		<div v-else-if="houseStore.error" class="insights-error" data-testid="insights-error">
			<ErrorState @retry="retryInsights" />
		</div>

		<template v-else>
			<section class="insights-section">
				<h2>{{ t('chorestrike', 'House Overview') }}</h2>
				<HouseStatsCards :stats="stats" />
			</section>

			<section class="insights-section">
				<h2>{{ t('chorestrike', 'Fairness') }}</h2>
				<ErrorState
					v-if="usersError"
					@retry="retryHouseUsers" />
				<p v-else-if="members.length === 0" class="empty-hint" data-testid="fairness-empty">
					{{ t('chorestrike', 'No members yet — invite your family to get started.') }}
				</p>
				<MemberFairnessTable
					v-else
					:members="members"
					:completed-energy-by-user="completedEnergyByUser" />
			</section>

			<section class="insights-section">
				<h2>{{ t('chorestrike', 'Recent Activity') }}</h2>
				<p v-if="houseStore.activityError" class="error-hint" data-testid="activity-error">
					{{ houseStore.activityError }}
				</p>
				<p v-else-if="activityLoading" class="empty-hint" data-testid="activity-loading">
					{{ t('chorestrike', 'Loading activity…') }}
				</p>
				<ActivityFeed
					v-else
					:items="houseStore.activity" />
			</section>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { useHouseStore } from '../store/house.js'
import { useUserStore } from '../store/user.js'
import { logger } from '../utils/logger.js'
import ErrorState from '../components/Common/ErrorState.vue'
import HouseStatsCards from '../components/Insights/HouseStatsCards.vue'
import MemberFairnessTable from '../components/Insights/MemberFairnessTable.vue'
import ActivityFeed from '../components/Insights/ActivityFeed.vue'

export default {
	name: 'InsightsView',

	components: {
		ErrorState,
		HouseStatsCards,
		MemberFairnessTable,
		ActivityFeed,
	},

	setup() {
		const houseStore = useHouseStore()
		const userStore = useUserStore()
		return { houseStore, userStore }
	},

	data() {
		return {
			loading: false,
			// Tracks a failed house-users fetch separately (CS-PLAN-051 D1):
			// the fairness section must never render the "No members yet"
			// hint for data it could not load.
			usersError: false,
		}
	},

	computed: {
		stats() {
			return this.houseStore.insights?.stats || {}
		},

		members() {
			return this.userStore.houseUsers
		},

		activityLoading() {
			return this.houseStore.activityLoading
		},

		/**
		 * Per-member completed energy over the activity window, normalized
		 * against each member's weekly capacity in the fairness table.
		 */
		completedEnergyByUser() {
			const byUser = {}
			for (const item of this.houseStore.activity) {
				if (item.energyCost == null) continue
				byUser[item.houseUserId] = (byUser[item.houseUserId] ?? 0) + item.energyCost
			}
			return byUser
		},
	},

	async mounted() {
		this.loading = true
		// allSettled so a members-fetch failure stays visible in its own
		// section instead of being swallowed by the shared catch (which
		// left `houseUsers` empty and rendered a false empty hint).
		const [insightsResult, usersResult] = await Promise.allSettled([
			this.houseStore.fetchInsights(),
			this.userStore.fetchHouseUsers(),
		])
		if (insightsResult.status === 'rejected') {
			logger.error('Failed to fetch insights', insightsResult.reason, { view: 'InsightsView', action: 'mounted' })
		}
		if (usersResult.status === 'rejected') {
			this.usersError = true
			logger.error('Failed to load house members', usersResult.reason, { view: 'InsightsView', action: 'mounted' })
		}
		this.loading = false

		// Activity loads independently so a failure cannot blank the stats.
		try {
			await this.houseStore.fetchActivity()
		} catch (error) {
			logger.error('Failed to load activity feed', error, { view: 'InsightsView', action: 'mounted' })
		}
	},

	methods: {
		t,

		/** User-triggered retry of the failed house-users fetch. */
		async retryHouseUsers() {
			this.usersError = false
			try {
				await this.userStore.fetchHouseUsers()
			} catch (error) {
				this.usersError = true
				logger.error('Failed to reload house members', error, { view: 'InsightsView', action: 'retryHouseUsers' })
			}
		},

		/** User-triggered retry of the failed insights fetch (CS-PLAN-051). */
		async retryInsights() {
			this.loading = true
			try {
				await this.houseStore.fetchInsights()
			} catch (error) {
				logger.error('Failed to reload insights', error, { view: 'InsightsView', action: 'retryInsights' })
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.insights-view {
	padding: 1rem 2rem 2rem;
	max-width: 1000px;
	margin: 0 auto;
}

.insights-header {
	text-align: center;
	margin-bottom: 2rem;
}

.insights-header h1 {
	font-size: 2rem;
	font-weight: bold;
	margin: 0 0 0.5rem 0;
}

.subtitle {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.insights-section {
	background: var(--color-background-dark);
	border-radius: 12px;
	padding: 1.5rem;
	margin-bottom: 2rem;
}

.insights-section h2 {
	margin: 0 0 1rem 0;
	font-size: 1.3rem;
	color: var(--color-main-text);
}

.empty-hint,
.error-hint {
	color: var(--color-text-maxcontrast);
	padding: 0.5rem 0;
	margin: 0;
}

.error-hint {
	color: var(--color-error);
}

.skeleton {
	background: linear-gradient(90deg, var(--color-background-dark) 25%, var(--color-background) 50%, var(--color-background-dark) 75%);
	background-size: 200% 100%;
	animation: skeleton-loading 1.5s infinite;
	border-radius: var(--border-radius-large);
}

.skeleton-header {
	width: 300px;
	height: 2rem;
	margin: 0 auto 2rem;
}

.skeleton-section {
	width: 100%;
	height: 160px;
	margin-bottom: 2rem;
}

@keyframes skeleton-loading {
	0% { background-position: 200% 0; }
	100% { background-position: -200% 0; }
}

@media (max-width: 768px) {
	.insights-view {
		padding: 1rem;
	}

	.insights-section {
		padding: 1rem;
	}
}
</style>
