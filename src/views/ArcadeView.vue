<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="arcade-view" data-testid="arcade-view">
		<!-- Optional-area consumer (C5h, Q-077-05): while the personal
		     arcadeVisible preference is off, the surface hides entirely —
		     a direct link shows the neutral placeholder, never the arcade. -->
		<template v-if="preferencesStore.arcadeVisible !== false">
			<header class="arcade-view-header">
				<h1>{{ t('chorestrike', 'Arcade') }}</h1>
				<p class="arcade-view-subtitle">
					{{ t('chorestrike', 'Scores-only family fun — outside the daily routine.') }}
				</p>
			</header>
			<ArcadeCatalog data-testid="arcade-surface" />
		</template>
		<div v-else class="arcade-hidden" data-testid="arcade-hidden">
			<p>{{ t('chorestrike', 'The arcade is hidden. You can turn it on again in the Settings.') }}</p>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import ArcadeCatalog from '../components/Minigames/ArcadeCatalog.vue'
import { usePreferencesStore } from '../store/preferences.js'

/**
 * The optional arcade surface (CS-PLAN-070 WP6 §3.3, Q-077-05 Option A):
 * reachable only through the secondary navigation and hidden entirely
 * while the personal `arcadeVisible` preference is off. Scores-only —
 * never a Coin surface (T10/D7d).
 */
export default {
	name: 'ArcadeView',

	components: {
		ArcadeCatalog,
	},

	setup() {
		const preferencesStore = usePreferencesStore()
		return { preferencesStore }
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.arcade-view {
	padding: 1rem var(--cs-view-gutter) 2rem;
	max-width: 1000px;
	margin: 0 auto;
}

.arcade-view-header {
	margin-bottom: 1.5rem;
	padding-inline-start: var(--cs-view-heading-inset);
}

.arcade-view-header h1 {
	font-size: 2rem;
	font-weight: bold;
	margin: 0 0 0.5rem 0;
}

.arcade-view-subtitle {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.arcade-hidden {
	padding: 3rem 1rem;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.arcade-hidden p {
	margin: 0;
}
</style>
