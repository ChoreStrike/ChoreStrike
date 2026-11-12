<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="planning-settings" data-testid="planning-settings">
		<NcLoadingIcon v-if="loading" data-testid="planning-settings-loading" />
		<ErrorState
			v-else-if="loadError"
			data-testid="planning-settings-error"
			:message="t('chorestrike', 'Could not load your planning settings')"
			@retry="load" />
		<template v-else>
			<SelfAvailabilitySettings />
			<EnergyBudgetSettings />
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon } from '@nextcloud/vue'
import ErrorState from '../Common/ErrorState.vue'
import EnergyBudgetSettings from '../Scheduling/EnergyBudgetSettings.vue'
import SelfAvailabilitySettings from '../Scheduling/SelfAvailabilitySettings.vue'
import { useSchedulingStore } from '../../store/scheduling.js'

/**
 * Own days and own energy (personal settings, CS-PLAN-090 WP-D): the
 * planning settings that left the weekly plan's page. Reads the viewer's
 * preferences when it opens; a failed read offers a retry here, and a failed
 * save is reported by the form it belongs to, never by replacing the page.
 */
export default {
	name: 'PlanningSettings',

	components: { NcLoadingIcon, ErrorState, SelfAvailabilitySettings, EnergyBudgetSettings },

	setup() {
		return { schedulingStore: useSchedulingStore() }
	},

	data() {
		return { loading: true, loadError: false }
	},

	mounted() {
		return this.load()
	},

	methods: {
		t,

		async load() {
			this.loading = true
			this.loadError = false
			try {
				await this.schedulingStore.fetchUserPreferences()
			} catch {
				// The store has logged it.
				this.loadError = true
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.planning-settings {
	display: flex;
	flex-direction: column;
	gap: 2rem;
}
</style>
