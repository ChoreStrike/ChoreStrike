<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="form-group" data-testid="arcade-gate">
		<label for="arcade-gate-enabled">{{ t('chorestrike', 'Unlock games with finished chores') }}</label>
		<p class="setting-hint">
			{{ t('chorestrike', 'Games open one by one as members finish chores. Switch this off to open every game for everyone.') }}
		</p>
		<div class="input-group">
			<input id="arcade-gate-enabled"
				v-model="draft"
				type="checkbox"
				class="gate-toggle"
				data-testid="arcade-gate-toggle"
				:disabled="houseStore.loading">
			<button type="button"
				class="save-button"
				data-testid="arcade-gate-save"
				:disabled="!canSave || houseStore.loading"
				@click="save">
				{{ t('chorestrike', 'Save') }}
			</button>
		</div>
		<p v-if="saved"
			class="saved-note"
			data-testid="arcade-gate-saved"
			role="status">
			{{ t('chorestrike', 'The arcade setting was saved.') }}
		</p>
		<p v-else-if="failed"
			class="error-note"
			data-testid="arcade-gate-error"
			role="alert">
			{{ t('chorestrike', 'The arcade setting could not be saved. Please try again.') }}
		</p>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import { useHouseStore } from '../../store/house.js'
import { logger } from '../../utils/logger.js'

/**
 * Owner-only arcade unlock gate (CS-PLAN-086 D-086-02), next to the reward
 * catalog gate in the Settings. On: games open as permanent milestones of
 * finished chores. Off: every game is open for every member. The personal
 * `arcadeVisible` preference is never touched.
 */
const houseStore = useHouseStore()
const draft = ref(houseStore.arcadeGateEnabled)
const saved = ref(false)
const failed = ref(false)
const canSave = computed(() => draft.value !== houseStore.arcadeGateEnabled)

watch(() => houseStore.arcadeGateEnabled, (value) => {
	draft.value = value
})

async function save() {
	failed.value = false
	try {
		await houseStore.updateArcadeGateEnabled(draft.value)
		saved.value = true
		setTimeout(() => {
			saved.value = false
		}, 4000)
	} catch (error) {
		failed.value = true
		logger.error('Failed to save the arcade gate', error, { component: 'ArcadeGateSetting', action: 'save' })
	}
}
</script>

<style scoped>
.form-group {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.form-group label {
	font-size: 0.9rem;
	font-weight: 500;
}

.setting-hint {
	margin: 0;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.input-group {
	display: flex;
	gap: 0.75rem;
	align-items: center;
}

.gate-toggle {
	width: 1.1rem;
	height: 1.1rem;
	accent-color: var(--color-primary-element);
	cursor: pointer;
}

.save-button {
	min-height: 44px;
	padding: 0.6rem 1rem;
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.9rem;
	font-weight: 500;
}

.save-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.saved-note {
	margin: 0;
	font-size: 0.85rem;
	color: var(--color-success-text);
}

.error-note {
	margin: 0;
	padding: 0.5rem 0.75rem;
	border-inline-start: 3px solid var(--color-element-error);
	font-size: 0.9rem;
}
</style>
