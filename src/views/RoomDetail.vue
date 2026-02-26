<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="room-detail">
		<h1>{{ t('chorestrike', 'Room Detail') }}</h1>
		<p>{{ t('chorestrike', 'Coming soon...') }}</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'RoomDetail',

	props: {
		id: {
			type: String,
			required: true,
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.room-detail {
	padding: 2rem;
}
</style>
