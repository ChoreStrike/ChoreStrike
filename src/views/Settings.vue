<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="settings">
		<h1>{{ t('chorestrike', 'Settings') }}</h1>
		<p>{{ t('chorestrike', 'Coming soon...') }}</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'Settings',

	methods: {
		t,
	},
}
</script>

<style scoped>
.settings {
	padding: 2rem;
}
</style>
