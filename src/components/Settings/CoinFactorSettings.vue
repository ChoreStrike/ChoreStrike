<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<section class="form-group" data-testid="coin-factor-settings">
		<h4 class="setting-title">
			{{ t('chorestrike', 'Coin factor') }}
		</h4>
		<p class="setting-hint">
			{{ t('chorestrike', 'What a person’s coins count. With a higher factor, someone with less time can collect as much as the others. It applies to work recorded from now on. Energy, the weekly goal and the streak stay the same.') }}
		</p>
		<p v-if="loadFailed"
			class="error-note"
			data-testid="coin-factor-load-error"
			role="alert">
			{{ t('chorestrike', 'Failed to load members') }}
			<button type="button" class="link-button" @click="load">
				{{ t('chorestrike', 'Try again') }}
			</button>
		</p>
		<ul v-else class="factor-list">
			<li v-for="member in members"
				:key="member.nextcloudUserId"
				class="factor-row"
				data-testid="coin-factor-row"
				:data-user-id="member.nextcloudUserId">
				<span class="factor-name">{{ member.displayName || member.nextcloudUserId }}</span>
				<select
					class="factor-select"
					data-testid="coin-factor-select"
					:value="member.coinFactor ?? 1"
					:disabled="savingUserId !== null"
					:aria-label="t('chorestrike', 'Coin factor of {name}', { name: member.displayName || member.nextcloudUserId })"
					@change="save(member, Number($event.target.value))">
					<option v-for="factor in FACTORS" :key="factor" :value="factor">
						{{ t('chorestrike', '× {factor}', { factor }) }}
					</option>
				</select>
			</li>
		</ul>
		<p v-if="savedName"
			class="saved-note"
			data-testid="coin-factor-saved"
			role="status">
			{{ t('chorestrike', 'The coin factor of {name} was saved.', { name: savedName }) }}
		</p>
	</section>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed, onMounted, ref } from 'vue'
import { useUserStore } from '../../store/user.js'
import { showServerRefusal } from '../../utils/errorToast.js'

/**
 * Owner-only coin factor per member (CS-PLAN-091 WP-B5, ADR-021 decision
 * 11): × 1, × 2 or × 3 on coins only. The server freezes the factor on
 * every completion, so a change never reaches work that was already paid.
 */
const FACTORS = [1, 2, 3]

const userStore = useUserStore()
const loadFailed = ref(false)
const savingUserId = ref(null)
const savedName = ref('')

const members = computed(() => [...(userStore.houseUsers ?? [])]
	.sort((a, b) => String(a.displayName ?? '').localeCompare(String(b.displayName ?? ''))))

async function load() {
	loadFailed.value = false
	try {
		await userStore.fetchHouseUsers()
	} catch {
		// The store has logged it.
		loadFailed.value = true
	}
}

async function save(member, factor) {
	savingUserId.value = member.nextcloudUserId
	savedName.value = ''
	try {
		await userStore.setCoinFactor(member.nextcloudUserId, factor)
		savedName.value = member.displayName || member.nextcloudUserId
	} catch (error) {
		// The store has logged it; the select shows the stored factor again.
		showServerRefusal(error, t('chorestrike', 'Could not save the coin factor'))
	} finally {
		savingUserId.value = null
	}
}

onMounted(() => {
	if ((userStore.houseUsers ?? []).length === 0) {
		load()
	}
})
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

.factor-list {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.factor-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 0.75rem;
	padding: 0.25rem 0;
	border-bottom: 1px solid var(--color-border);
}

.factor-name {
	font-weight: 500;
	overflow-wrap: anywhere;
}

.factor-select {
	min-height: 44px;
	min-width: 5rem;
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
