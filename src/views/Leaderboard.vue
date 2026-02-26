<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="leaderboard">
		<h1>{{ t('chorestrike', 'Leaderboard') }}</h1>
		<p>{{ t('chorestrike', 'Coming soon...') }}</p>
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
