<template>
	<NcContent app-name="chorewars">
		<NcAppNavigation>
			<NcAppNavigationItem
				:to="{ name: 'dashboard' }"
				icon="icon-home"
				:name="t('chorewars', 'Dashboard')" />
			<NcAppNavigationItem
				:to="{ name: 'tasks' }"
				icon="icon-checkmark"
				:name="t('chorewars', 'Tasks')" />
			<NcAppNavigationItem
				:to="{ name: 'leaderboard' }"
				icon="icon-star"
				:name="t('chorewars', 'Leaderboard')" />
			<NcAppNavigationItem
				:to="{ name: 'settings' }"
				icon="icon-settings"
				:name="t('chorewars', 'Settings')" />
		</NcAppNavigation>

		<NcAppContent>
			<router-view />
		</NcAppContent>
	</NcContent>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcContent from '@nextcloud/vue/dist/Components/NcContent.js'
import NcAppNavigation from '@nextcloud/vue/dist/Components/NcAppNavigation.js'
import NcAppNavigationItem from '@nextcloud/vue/dist/Components/NcAppNavigationItem.js'
import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js'

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
