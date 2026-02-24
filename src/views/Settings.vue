<template>
	<div class="settings">
		<h1>{{ t('chorewars', 'Settings') }}</h1>
		<p>{{ t('chorewars', 'Coming soon...') }}</p>
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
