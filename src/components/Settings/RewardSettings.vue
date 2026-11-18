<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="reward-settings" data-testid="reward-settings">
		<div class="setting-card">
			<CoinFactorSettings />
		</div>
		<div class="setting-card">
			<BonusDaysSettings />
		</div>
		<!-- Catalog administration works while browsing is hidden or the
		     gate is off (CS-PLAN-070 WP8 §3.1). -->
		<div class="setting-card">
			<RewardCatalogEditor />
		</div>
	</div>
</template>

<script setup>
import RewardCatalogEditor from '../Rewards/RewardCatalogEditor.vue'
import BonusDaysSettings from './BonusDaysSettings.vue'
import CoinFactorSettings from './CoinFactorSettings.vue'

/**
 * The household's reward category (owner-only): the two coin multipliers
 * (CS-PLAN-091 WP-B5, ADR-021 decision 11) and the reward catalog. Each
 * brings its own styles, so the root does not carry `cs-settings-form`:
 * the shared rules of `settings.css` must not reach them.
 */
</script>

<style scoped>
.reward-settings {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.setting-card {
	background: var(--color-background-dark);
	border-radius: 12px;
	padding: 1.5rem;
}
</style>
