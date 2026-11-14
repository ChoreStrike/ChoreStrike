<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="arcade-settings" data-testid="arcade-settings">
		<div class="setting-card">
			<ArcadeGateSetting />
		</div>
		<div class="setting-card">
			<ArcadeThresholdSettings />
		</div>
		<div class="setting-card">
			<ArcadeResetList />
		</div>
	</div>
</template>

<script setup>
import ArcadeGateSetting from '../Minigames/ArcadeGateSetting.vue'
import ArcadeResetList from '../Minigames/ArcadeResetList.vue'
import ArcadeThresholdSettings from '../Minigames/ArcadeThresholdSettings.vue'

/**
 * The household's arcade category (owner-only): the unlock gate
 * (CS-PLAN-086 D-086-02), the thresholds per game and the reset of one
 * person (CS-PLAN-090 WP-J, D-090-63/66). The three bring their own
 * styles, so the root does not carry `cs-settings-form`: the shared rules
 * of `settings.css` must not reach them.
 */
</script>

<style scoped>
.arcade-settings {
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
