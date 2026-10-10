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
					:to="{ name: 'today' }"
					icon="icon-clock"
					data-testid="nav-today"
					:name="t('chorestrike', 'My Day')" />
				<NcAppNavigationItem
					:to="{ name: 'dashboard' }"
					icon="icon-home"
					data-testid="nav-dashboard"
					:name="t('chorestrike', 'Dashboard')" />
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
				<!-- Optional-area gating (C5h, Q-077-05): the opt-in arcade
				     surface disappears from the navigation entirely while the
				     personal arcadeVisible preference is off. -->
				<NcAppNavigationItem
					v-if="arcadeVisible"
					:to="{ name: 'arcade' }"
					icon="icon-game"
					data-testid="nav-arcade"
					:name="t('chorestrike', 'Arcade')" />
				<!-- Reward catalog (WP8 §3.1): optional browsing behind BOTH
			     independent gates — the household gate (owner-controlled,
			     loaded through the guarded house read after membership
			     resolves) and the current person's loaded catalogVisible
			     preference. Fail-closed: unknown/loading/failed
			     preferences are not browsable. -->
				<NcAppNavigationItem
					v-if="catalogBrowsable"
					:to="{ name: 'rewards' }"
					icon="icon-gift"
					data-testid="nav-rewards"
					:name="t('chorestrike', 'Rewards')" />
				<!-- Own reward requests: ONE persistent entry, independent of
			     BOTH catalog gates and the request count — hiding or
			     disabling browsing never strands existing promises. -->
				<NcAppNavigationItem
					:to="{ name: 'reward-requests' }"
					icon="icon-reward"
					data-testid="nav-reward-requests"
					:name="t('chorestrike', 'My Requests')" />
				<NcAppNavigationItem
					:to="{ name: 'settings' }"
					icon="icon-settings"
					data-testid="nav-settings"
					:name="t('chorestrike', 'Settings')" />
				<!-- Essential help slice (FEAT-UX-023, WP6 §3.7): the plain
				     glossary, always reachable from the navigation. -->
				<HelpEntry />
			</NcAppNavigation>

			<NcAppContent>
				<router-view v-if="userStore.membership === 'member'" />
				<ErrorState v-else-if="userStore.error && !userStore.currentUserRequest" @retry="handleRetry" />
				<NcLoadingIcon v-else data-testid="membership-loading" />
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
	NcLoadingIcon,
} from '@nextcloud/vue'
import { mapStores } from 'pinia'
import UndoSnackbar from './components/Common/UndoSnackbar.vue'
import NotAuthorizedState from './components/Common/NotAuthorizedState.vue'
import ErrorState from './components/Common/ErrorState.vue'
import HelpEntry from './components/Help/HelpEntry.vue'
import { useUserStore } from './store/user.js'
import { usePreferencesStore } from './store/preferences.js'
import { useHouseStore } from './store/house.js'
import { resetIdentityStores } from './composables/useStoreReset.js'

export default {
	name: 'App',

	components: {
		NcContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppContent,
		NcLoadingIcon,
		UndoSnackbar,
		NotAuthorizedState,
		ErrorState,
		HelpEntry,
	},

	computed: {
		...mapStores(useUserStore, usePreferencesStore, useHouseStore),
		membershipDenied() {
			return this.userStore.membership === 'denied'
		},
		/**
		 * Optional-area consumer (C5h, Q-077-05): the arcade is an opt-in
		 * surface — default-on, hideable per person. The preference loads
		 * with the app's own Settings surface; before the first fetch the
		 * persisted default (visible) applies.
		 */
		arcadeVisible() {
			return this.preferencesStore.arcadeVisible !== false
		},
		/**
		 * Reward catalog browsing (WP8 §3.1): both independent gates must
		 * be open — the household gate AND the current person's LOADED
		 * catalogVisible preference. Unknown/loading/failed preferences
		 * are fail-closed (not browsable); a personal opt-out always wins
		 * over the house gate, and the house gate never enables personal
		 * browsing by itself.
		 */
		catalogBrowsable() {
			return this.houseStore.catalogEnabled
				&& this.preferencesStore.loaded
				&& this.preferencesStore.catalogVisible === true
		},
	},

	watch: {
		/**
		 * Load the house flag and the personal preferences through their
		 * guarded reads after membership resolves (WP8 §3.1) — never
		 * before, and never eagerly fetching catalog items for a hidden
		 * entry. The catalog navigation is fail-closed until BOTH reads
		 * land: the initial neutral defaults are not a completed read.
		 */
		'userStore.membership': {
			immediate: true,
			handler(membership) {
				if (membership === 'member') {
					this.loadHouseGate()
					this.loadPreferences()
				}
			},
		},
	},

	methods: {
		t,

		loadHouseGate() {
			this.houseStore.fetchHouse().catch(() => {
				// The navigation stays fail-closed (catalog hidden); the
				// requests surface does not depend on this optional read.
			})
		},

		loadPreferences() {
			if (this.preferencesStore.loaded) {
				return
			}
			this.preferencesStore.fetchPreferences().catch(() => {
				// Fail-closed: without an authoritative read the optional
				// catalog stays hidden; the persistent surfaces do not
				// depend on this optional read.
			})
		},

		handleRetry() {
			// CS-PLAN-070 WP6 §3.8 (C5h): a re-loaded identity drops EVERY
			// house-scoped store first — a shared device never renders a
			// previous login's data.
			resetIdentityStores()
			this.userStore.initializeCurrentUser().catch(() => {
				// State stays 'denied' on another 403; single transient
				// failures recover through the in-flight dedup retry.
			})
		},
	},
}
</script>

<style scoped>
/* App-wide styles */
</style>
