<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="dashboard">
		<h1>{{ t('chorewars', 'ChoreWars Dashboard') }}</h1>
		<p class="tagline">
			{{ t('chorewars', 'Defend your Home Base. Together.') }}
		</p>

		<div class="welcome-message">
			<h2>{{ t('chorewars', 'Welcome to ChoreWars!') }}</h2>
			<p>{{ t('chorewars', 'This is the beginning of your gamified household management journey.') }}</p>
			<p>{{ t('chorewars', 'Setup is in progress. Stay tuned for more features!') }}</p>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'Dashboard',

	methods: {
		t,
	},
}
</script>

<style scoped>
.dashboard {
	padding: 2rem;
	max-width: 1200px;
	margin: 0 auto;
}

h1 {
	font-size: 2rem;
	margin-bottom: 0.5rem;
}

.tagline {
	font-size: 1.2rem;
	color: var(--color-text-maxcontrast);
	margin-bottom: 2rem;
}

.welcome-message {
	background: var(--color-background-dark);
	padding: 2rem;
	border-radius: 8px;
	margin-top: 2rem;
}

.welcome-message h2 {
	margin-top: 0;
}

.welcome-message p {
	line-height: 1.6;
}
</style>
