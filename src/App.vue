<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<NcContent app-name="chorestrike">
		<!-- Authorization gate (CS-PLAN-061 §3.9): while membership is
		     'denied' (403 from user#current), the whole app is replaced by
		     the explicit NotAuthorizedState — removed vs. never-invited is
		     deliberately indistinguishable (anti-enumeration). -->
		<NcAppContent v-if="membershipDenied">
			<NotAuthorizedState @retry="handleRetry" />
		</NcAppContent>

		<template v-else>
			<NcAppNavigation>
				<NcAppNavigationItem
					:to="{ name: 'dashboard' }"
					icon="icon-home"
					data-testid="nav-dashboard"
					:name="t('chorestrike', 'Dashboard')" />
				<NcAppNavigationItem
					:to="{ name: 'today' }"
					icon="icon-clock"
					data-testid="nav-today"
					:name="t('chorestrike', 'My Day')" />
				<NcAppNavigationItem
					:to="{ name: 'tasks' }"
					icon="icon-checkmark"
					data-testid="nav-tasks"
					:name="t('chorestrike', 'Tasks')" />
				<NcAppNavigationItem
					:to="{ name: 'leaderboard' }"
					icon="icon-star"
					data-testid="nav-leaderboard"
					:name="t('chorestrike', 'Leaderboard')" />
				<NcAppNavigationItem
					:to="{ name: 'insights' }"
					icon="icon-category-monitoring"
					data-testid="nav-insights"
					:name="t('chorestrike', 'Insights')" />
				<NcAppNavigationItem
					:to="{ name: 'scheduling' }"
					icon="icon-calendar"
					data-testid="nav-scheduling"
					:name="t('chorestrike', 'Scheduling')" />
				<NcAppNavigationItem
					:to="{ name: 'calendar' }"
					icon="icon-calendar-dark"
					data-testid="nav-calendar"
					:name="t('chorestrike', 'Calendar')" />
				<NcAppNavigationItem
					:to="{ name: 'settings' }"
					icon="icon-settings"
					data-testid="nav-settings"
					:name="t('chorestrike', 'Settings')" />
			</NcAppNavigation>

			<NcAppContent>
				<router-view />
				<UndoSnackbar />
			</NcAppContent>
		</template>
	</NcContent>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcContent,
	NcAppNavigation,
	NcAppNavigationItem,
	NcAppContent,
} from '@nextcloud/vue'
import { mapStores } from 'pinia'
import UndoSnackbar from './components/Common/UndoSnackbar.vue'
import NotAuthorizedState from './components/Common/NotAuthorizedState.vue'
import { useUserStore } from './store/user.js'
import { landingRouteForRole } from './router.js'

export default {
	name: 'App',

	components: {
		NcContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppContent,
		UndoSnackbar,
		NotAuthorizedState,
	},

	data() {
		return {
			// CS-PLAN-066 (D4): the child landing redirect is a ONE-SHOT for
			// the initial unqualified root visit — later explicit navigation
			// back to the Dashboard stays allowed.
			childLandingApplied: false,
		}
	},

	computed: {
		...mapStores(useUserStore),
		membershipDenied() {
			return this.userStore.membership === 'denied'
		},
	},

	watch: {
		// Membership resolution is async (userAutoInit fires the request);
		// apply the landing decision as soon as the role is known.
		'userStore.membership'() {
			this.applyChildLanding()
		},
	},

	mounted() {
		this.applyChildLanding()
	},

	methods: {
		t,
		handleRetry() {
			this.userStore.reset()
			this.userStore.initializeCurrentUser().catch(() => {
				// State stays 'denied' on another 403; single transient
				// failures recover through the in-flight dedup retry.
			})
		},
		/**
		 * D4: after membership resolves, redirect ONLY the initial
		 * unqualified root visit of a child to the shared My Day surface.
		 * Deep links and permitted shared screens remain usable; owner and
		 * member keep the Dashboard default.
		 */
		applyChildLanding() {
			if (this.childLandingApplied) {
				return
			}
			if (this.userStore.membership !== 'member') {
				return
			}
			this.childLandingApplied = true
			const target = landingRouteForRole(this.userStore.role, this.$route)
			if (target) {
				this.$router.replace(target)
			}
		},
	},
}
</script>

<style scoped>
/* App-wide styles */
</style>
