<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<section class="form-group" data-testid="arcade-reset">
		<h4 class="setting-title">
			{{ t('chorestrike', 'Start over') }}
		</h4>
		<p class="setting-hint">
			{{ t('chorestrike', 'A reset locks the games again for one person: from then on only chores finished after the reset count. Highscores stay.') }}
		</p>
		<p v-if="loading" class="setting-hint" data-testid="arcade-reset-loading">
			{{ t('chorestrike', 'Loading…') }}
		</p>
		<p v-else-if="loadFailed"
			class="error-note"
			data-testid="arcade-reset-load-error"
			role="alert">
			{{ t('chorestrike', 'The progress could not be loaded.') }}
			<button type="button" class="link-button" @click="load">
				{{ t('chorestrike', 'Try again') }}
			</button>
		</p>
		<ul v-else class="reset-list">
			<li v-for="member in store.progress ?? []"
				:key="member.userId"
				class="reset-row"
				data-testid="arcade-reset-row"
				:data-user-id="member.userId">
				<div class="reset-text">
					<span class="reset-name">{{ member.displayName }}</span>
					<span class="reset-count">{{ progressText(member) }}</span>
				</div>
				<button type="button"
					class="reset-button"
					data-testid="arcade-reset-button"
					:aria-label="t('chorestrike', 'Reset the arcade progress of {name}', { name: member.displayName })"
					@click="pending = member">
					{{ t('chorestrike', 'Reset') }}
				</button>
			</li>
		</ul>
		<p v-if="doneName"
			class="saved-note"
			data-testid="arcade-reset-done"
			role="status">
			{{ t('chorestrike', 'The arcade progress of {name} was reset.', { name: doneName }) }}
		</p>
		<p v-else-if="failed"
			class="error-note"
			data-testid="arcade-reset-error"
			role="alert">
			{{ t('chorestrike', 'The arcade progress could not be reset. Please try again.') }}
		</p>
		<Modal v-if="pending" :aria-label="t('chorestrike', 'Reset arcade progress?')" @close="pending = null">
			<div class="confirm" data-testid="arcade-reset-dialog">
				<h3>{{ t('chorestrike', 'Reset arcade progress?') }}</h3>
				<p>{{ t('chorestrike', '{name} starts over: the games lock again and open with newly finished chores. Highscores stay.', { name: pending.displayName }) }}</p>
				<div class="confirm-actions">
					<button type="button" class="secondary-button" @click="pending = null">
						{{ t('chorestrike', 'Cancel') }}
					</button>
					<button type="button"
						class="danger-button"
						data-testid="arcade-reset-confirm"
						:disabled="resetting"
						@click="confirm">
						{{ t('chorestrike', 'Reset') }}
					</button>
				</div>
			</div>
		</Modal>
	</section>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { onMounted, ref } from 'vue'
import Modal from '../Common/Modal.vue'
import { useHouseStore } from '../../store/house.js'
import { useMinigameStore } from '../../store/minigame.js'
import { dateKeyInZone, formatDayKey } from '../../utils/dateKeys.js'

/**
 * Owner-only arcade reset (CS-PLAN-090 WP-J, D-090-63): every member with
 * their finished chores since their last reset, in name order. A reset asks
 * first, then locks the games again for that person; highscores stay.
 */
const store = useMinigameStore()
const houseStore = useHouseStore()
const loading = ref(true)
const loadFailed = ref(false)
const pending = ref(null)
const resetting = ref(false)
const doneName = ref('')
const failed = ref(false)

/**
 * @param {{finishedChores: number, resetAt: string|null}} member A progress row
 * @return {string} The count, and since when it runs
 */
function progressText({ finishedChores: count, resetAt }) {
	if (resetAt === null) {
		return count === 1
			? t('chorestrike', '1 finished chore')
			: t('chorestrike', '{count} finished chores', { count })
	}
	const date = formatDayKey(dateKeyInZone(resetAt, houseStore.timezone))
	return count === 1
		? t('chorestrike', '1 finished chore since the reset on {date}', { date })
		: t('chorestrike', '{count} finished chores since the reset on {date}', { count, date })
}

async function load() {
	loading.value = true
	loadFailed.value = false
	try {
		await store.fetchProgress()
	} catch {
		// The store has logged it.
		loadFailed.value = true
	} finally {
		loading.value = false
	}
}

async function confirm() {
	const member = pending.value
	resetting.value = true
	failed.value = false
	doneName.value = ''
	try {
		await store.resetProgress(member.userId)
		doneName.value = member.displayName
	} catch {
		// The store has logged it.
		failed.value = true
	} finally {
		resetting.value = false
		pending.value = null
	}
}

onMounted(load)
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

.reset-list {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.reset-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 0.75rem;
	padding: 0.25rem 0;
	border-bottom: 1px solid var(--color-border);
}

.reset-text {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.reset-name {
	font-weight: 500;
	overflow-wrap: anywhere;
}

.reset-count {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.reset-button,
.secondary-button,
.danger-button {
	min-height: 44px;
	padding: 0.5rem 1rem;
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.9rem;
}

.reset-button,
.secondary-button {
	background: transparent;
	color: var(--color-main-text);
	border: 1px solid var(--color-border-dark);
}

/* Error-coloured text on the page background, as the Danger Zone headings. */
.danger-button {
	background: transparent;
	color: var(--color-error-text);
	border: 2px solid var(--color-element-error);
	font-weight: 500;
}

.danger-button:disabled {
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

.confirm {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	padding: 0.5rem;
}

.confirm h3 {
	margin: 0;
}

.confirm-actions {
	display: flex;
	flex-wrap: wrap;
	justify-content: flex-end;
	gap: 0.5rem;
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
