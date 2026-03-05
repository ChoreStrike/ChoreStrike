<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<NcContent app-name="chorestrike">
		<NcAppNavigation>
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
				:to="{ name: 'settings' }"
				icon="icon-settings"
				data-testid="nav-settings"
				:name="t('chorestrike', 'Settings')" />
		</NcAppNavigation>

		<NcAppContent>
			<router-view />
		</NcAppContent>
	</NcContent>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'

export default {
	name: 'App',

	components: {
		NcContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppContent,
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
/* App-wide styles */
</style>
