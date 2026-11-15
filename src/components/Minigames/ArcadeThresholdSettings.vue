<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<form class="form-group"
		data-testid="arcade-thresholds"
		novalidate
		@submit.prevent="save">
		<h4 class="setting-title">
			{{ t('chorestrike', 'Finished chores per game') }}
		</h4>
		<p class="setting-hint">
			{{ t('chorestrike', 'How many finished chores open each game while games unlock with finished chores. 0 opens a game right away.') }}
		</p>
		<p v-if="loadFailed"
			class="error-note"
			data-testid="arcade-thresholds-load-error"
			role="alert">
			{{ t('chorestrike', 'The games could not be loaded.') }}
			<button type="button" class="link-button" @click="load">
				{{ t('chorestrike', 'Try again') }}
			</button>
		</p>
		<ul v-else class="threshold-list">
			<li v-for="row in rows" :key="row.gameId" class="threshold-row">
				<label :for="`arcade-threshold-${row.gameId}`">{{ row.name }}</label>
				<input :id="`arcade-threshold-${row.gameId}`"
					v-model.number="draft[row.gameId]"
					type="number"
					min="0"
					:max="THRESHOLD_MAX"
					step="1"
					inputmode="numeric"
					class="threshold-input"
					:data-testid="`arcade-threshold-${row.gameId}`"
					:aria-invalid="isValid(draft[row.gameId]) ? 'false' : 'true'"
					:disabled="saving">
			</li>
		</ul>
		<p v-if="!allValid"
			class="error-note"
			data-testid="arcade-thresholds-invalid"
			role="alert">
			{{ t('chorestrike', 'Enter a whole number from 0 to {max} for every game.', { max: THRESHOLD_MAX }) }}
		</p>
		<div class="input-group">
			<button type="submit"
				class="save-button"
				data-testid="arcade-thresholds-save"
				:disabled="!canSave || saving">
				{{ t('chorestrike', 'Save') }}
			</button>
		</div>
		<p v-if="saved"
			class="saved-note"
			data-testid="arcade-thresholds-saved"
			role="status">
			{{ t('chorestrike', 'The thresholds were saved.') }}
		</p>
		<p v-else-if="failed"
			class="error-note"
			data-testid="arcade-thresholds-error"
			role="alert">
			{{ t('chorestrike', 'The thresholds could not be saved. Please try again.') }}
		</p>
	</form>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useMinigameStore } from '../../store/minigame.js'
import { findGame } from './gameRegistry.js'

/**
 * Owner-only arcade thresholds (CS-PLAN-090 WP-J, D-090-66): one whole
 * number from 0 to 500 per game, saved together. The values come from the
 * catalog, which already carries each game's threshold; a catalog that
 * arrives while the owner edits never overwrites an edited field.
 */
const THRESHOLD_MAX = 500

const store = useMinigameStore()
const draft = reactive({})
const baseline = ref({})
const loadFailed = ref(false)
const saving = ref(false)
const saved = ref(false)
const failed = ref(false)
let savedTimer = null

const serverValues = computed(() => Object.fromEntries((store.catalog?.games ?? []).map((game) => [game.gameId, game.threshold])))
const rows = computed(() => (store.catalog?.games ?? []).map((game) => ({
	gameId: game.gameId,
	name: findGame(game.gameId)?.name() ?? game.gameId,
})))
const dirty = computed(() => rows.value.some(({ gameId }) => draft[gameId] !== baseline.value[gameId]))
const allValid = computed(() => rows.value.every(({ gameId }) => isValid(draft[gameId])))
const canSave = computed(() => rows.value.length > 0 && dirty.value && allValid.value)

/**
 * @param {unknown} value The input's value
 * @return {boolean} Whether it is a whole number in range
 */
function isValid(value) {
	return Number.isInteger(value) && value >= 0 && value <= THRESHOLD_MAX
}

// A new catalog moves every field the owner has not touched; an edited
// field keeps the edit.
watch(serverValues, (values) => {
	const previous = baseline.value
	for (const [gameId, value] of Object.entries(values)) {
		if (!(gameId in draft) || draft[gameId] === previous[gameId]) {
			draft[gameId] = value
		}
	}
	baseline.value = values
}, { immediate: true })

async function load() {
	loadFailed.value = false
	await store.fetchCatalog()
	loadFailed.value = store.catalog === null
}

async function save() {
	if (!canSave.value) {
		return
	}
	saving.value = true
	failed.value = false
	try {
		const values = Object.fromEntries(rows.value.map(({ gameId }) => [gameId, draft[gameId]]))
		await store.saveThresholds(values)
		Object.assign(draft, serverValues.value)
		saved.value = true
		// A second save restarts the note's four seconds.
		clearTimeout(savedTimer)
		savedTimer = setTimeout(() => {
			saved.value = false
		}, 4000)
	} catch {
		// The store has logged it.
		failed.value = true
	} finally {
		saving.value = false
	}
}

onMounted(() => {
	if (store.catalog === null) {
		return load()
	}
})

onBeforeUnmount(() => clearTimeout(savedTimer))
</script>

<style scoped>
.form-group {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.setting-title {
	margin: 0;
	font-size: 0.9rem;
	font-weight: 500;
}

.setting-hint {
	margin: 0;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.threshold-list {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr));
	gap: 0.5rem 1rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.threshold-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 0.75rem;
}

.threshold-input {
	width: 5.5rem;
	min-height: 44px;
}

.input-group {
	display: flex;
	gap: 0.75rem;
	align-items: center;
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

.link-button {
	min-height: 44px;
	padding: 0 0.5rem;
	background: none;
	border: none;
	color: var(--color-primary-element);
	text-decoration: underline;
	cursor: pointer;
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
