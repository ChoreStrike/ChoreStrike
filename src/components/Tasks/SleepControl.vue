<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div v-if="cycle" class="sleep-control" data-testid="sleep-control">
		<template v-if="asleep">
			<span class="sleep-state" data-testid="sleep-state">{{ stateLabel }}</span>
			<button
				type="button"
				class="sleep-button"
				data-testid="sleep-wake"
				:disabled="busy"
				@click="apply(false)">
				{{ t('chorestrike', 'Wake up') }}
			</button>
		</template>
		<button
			v-else-if="!asking"
			type="button"
			class="sleep-button"
			data-testid="sleep-open"
			@click="asking = true">
			{{ t('chorestrike', 'Put to sleep') }}
		</button>
		<form v-else
			class="sleep-form"
			data-testid="sleep-form"
			@submit.prevent="apply(true)">
			<label :for="inputId">{{ t('chorestrike', 'Wake up on (optional)') }}</label>
			<input
				:id="inputId"
				v-model="wakeOn"
				type="date"
				class="sleep-date"
				:min="tomorrow"
				data-testid="sleep-wake-on">
			<button
				type="submit"
				class="sleep-button"
				data-testid="sleep-confirm"
				:disabled="busy">
				{{ t('chorestrike', 'Put to sleep') }}
			</button>
			<button type="button"
				class="sleep-button"
				data-testid="sleep-cancel"
				@click="asking = false">
				{{ t('chorestrike', 'Cancel') }}
			</button>
			<p class="sleep-hint">
				{{ t('chorestrike', 'A sleeping task is not due and never overdue. Completing it wakes it up.') }}
			</p>
		</form>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import { useHouseStore } from '../../store/house.js'
import { useTaskCycleStore } from '../../store/taskCycle.js'
import { addDaysToKey, formatDayKey, todayKeyInZone } from '../../utils/dateKeys.js'
import { showServerRefusal } from '../../utils/errorToast.js'

/**
 * Put a task to sleep, optionally until a wake date, or wake it
 * (CS-PLAN-091 WP-B4, ADR-021 decision 7): for adults and the owner; the
 * caller shows it to them only and the server decides. A sleeping task has
 * no window; it wakes with a fresh one on its wake date, by this control
 * or by a completion.
 */
const props = defineProps({
	roomTaskId: { type: Number, required: true },
	/** The row's `cycle` block; a task without one (done, inactive) cannot sleep. */
	cycle: { type: Object, default: null },
})

const taskCycleStore = useTaskCycleStore()
const houseStore = useHouseStore()

const asking = ref(false)
const busy = ref(false)
const wakeOn = ref('')

const inputId = computed(() => `sleep-wake-on-${props.roomTaskId}`)
const asleep = computed(() => props.cycle?.state === 'asleep')
const tomorrow = computed(() => addDaysToKey(todayKeyInZone(houseStore.timezone), 1))
const stateLabel = computed(() => (props.cycle?.wakeOn
	? t('chorestrike', 'Asleep until {date}', { date: formatDayKey(props.cycle.wakeOn) })
	: t('chorestrike', 'Asleep')))

async function apply(sleeping) {
	busy.value = true
	try {
		await taskCycleStore.setSleeping(props.roomTaskId, sleeping, sleeping ? wakeOn.value || null : null)
		asking.value = false
		wakeOn.value = ''
	} catch (error) {
		// The store logged it; the toast says which rule applied.
		showServerRefusal(error, sleeping
			? t('chorestrike', 'Could not put the task to sleep')
			: t('chorestrike', 'Could not wake the task up'))
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.sleep-control,
.sleep-form {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.5rem;
}

.sleep-state {
	font-size: 0.9rem;
	font-weight: 500;
	color: var(--color-main-text);
}

.sleep-button {
	min-height: 44px;
	padding: 0 0.9rem;
	border: 1px solid var(--color-text-maxcontrast);
	border-radius: 22px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.sleep-button:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.sleep-button:focus-visible,
.sleep-date:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.sleep-date {
	min-height: 44px;
}

.sleep-hint {
	flex-basis: 100%;
	margin: 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}
</style>
