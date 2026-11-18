<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<section class="form-group" data-testid="bonus-days-settings">
		<h4 class="setting-title">
			{{ t('chorestrike', 'Bonus days') }}
		</h4>
		<p class="setting-hint">
			{{ t('chorestrike', 'On bonus days everyone’s coins count × 1.5, on top of the coin factor. Today shows them while they run.') }}
		</p>

		<div v-if="period" class="period" data-testid="bonus-days-period">
			<span class="period-text">{{ periodLabel }}</span>
			<button
				type="button"
				class="secondary-button"
				data-testid="bonus-days-cancel"
				:disabled="busy"
				@click="cancel">
				{{ t('chorestrike', 'Cancel bonus days') }}
			</button>
		</div>
		<form v-else
			class="period-form"
			data-testid="bonus-days-form"
			@submit.prevent="start">
			<label class="field">
				<span>{{ t('chorestrike', 'First day') }}</span>
				<input
					v-model="startsOn"
					type="date"
					class="field-input"
					required
					:min="today"
					data-testid="bonus-days-start">
			</label>
			<label class="field">
				<span>{{ t('chorestrike', 'Length') }}</span>
				<select v-model.number="days" class="field-input" data-testid="bonus-days-length">
					<option v-for="length in LENGTHS" :key="length" :value="length">
						{{ daysLabel(length) }}
					</option>
				</select>
			</label>
			<button
				type="submit"
				class="primary-button"
				data-testid="bonus-days-submit"
				:disabled="busy">
				{{ t('chorestrike', 'Start bonus days') }}
			</button>
		</form>
	</section>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import { useHouseStore } from '../../store/house.js'
import { formatDayKey, todayKeyInZone } from '../../utils/dateKeys.js'
import { showServerRefusal } from '../../utils/errorToast.js'
import { daysLabel } from '../../utils/taskDisplay.js'

/**
 * Owner-only bonus days (CS-PLAN-091 WP-B5, ADR-021 decision 11): one to
 * three house-local days on which everyone's coins count × 1.5. The house
 * shows its running period, else the next planned one; cancelling keeps
 * what the days already paid.
 */
const LENGTHS = [1, 2, 3]

const houseStore = useHouseStore()

const today = computed(() => todayKeyInZone(houseStore.timezone))
const startsOn = ref(today.value)
const days = ref(1)
const busy = ref(false)

const period = computed(() => houseStore.bonusPeriod)
const periodLabel = computed(() => {
	if (!period.value) {
		return ''
	}
	const { startsOn: from, endsOn: to } = period.value
	if (from > today.value) {
		return from === to
			? t('chorestrike', 'Planned for {date}', { date: formatDayKey(from) })
			: t('chorestrike', 'Planned from {from} to {to}', { from: formatDayKey(from), to: formatDayKey(to) })
	}
	return to === today.value
		? t('chorestrike', 'Running today')
		: t('chorestrike', 'Running until {date}', { date: formatDayKey(to) })
})

async function start() {
	busy.value = true
	try {
		await houseStore.startBonusPeriod(startsOn.value, days.value)
	} catch (error) {
		// The store has logged it.
		showServerRefusal(error, t('chorestrike', 'Could not start the bonus days'))
	} finally {
		busy.value = false
	}
}

async function cancel() {
	busy.value = true
	try {
		await houseStore.cancelBonusPeriod(period.value.id)
		startsOn.value = today.value
	} catch (error) {
		// The store has logged it.
		showServerRefusal(error, t('chorestrike', 'Could not cancel the bonus days'))
	} finally {
		busy.value = false
	}
}
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

.period,
.period-form {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 0.75rem;
}

.period {
	align-items: center;
	justify-content: space-between;
}

.period-text {
	font-weight: 500;
}

.field {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	font-size: 0.85rem;
}

.field-input {
	min-height: 44px;
}

.primary-button,
.secondary-button {
	min-height: 44px;
	padding: 0.5rem 1rem;
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.9rem;
}

.primary-button {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border: 1px solid var(--color-primary-element);
}

.secondary-button {
	background: transparent;
	color: var(--color-main-text);
	border: 1px solid var(--color-border-dark);
}

.primary-button:disabled,
.secondary-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}
</style>
