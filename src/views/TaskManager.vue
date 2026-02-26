<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="task-manager">
		<h1>{{ t('chorewars', 'Task Manager') }}</h1>
		<p>{{ t('chorewars', 'Coming soon...') }}</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'TaskManager',

	methods: {
		t,
	},
}
</script>

<style scoped>
.task-manager {
	padding: 2rem;
}
</style>
