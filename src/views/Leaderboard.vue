<template>
	<div class="leaderboard">
		<h1>{{ t('chorewars', 'Leaderboard') }}</h1>
		<p>{{ t('chorewars', 'Coming soon...') }}</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'Leaderboard',

	methods: {
		t,
	},
}
</script>

<style scoped>
.leaderboard {
	padding: 2rem;
}
</style>
