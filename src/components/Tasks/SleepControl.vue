<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div v-if="asleep || woke" class="sleep-control" data-testid="sleep-control">
		<!-- Says the state, and keeps the focus when the button goes (RF-091-09). -->
		<span ref="state"
			class="sleep-state"
			role="status"
			tabindex="-1"
			data-testid="sleep-state">{{ asleep ? asleepLabel(cycle) : t('chorestrike', 'Awake again') }}</span>
		<button v-if="asleep"
			type="button"
			class="sleep-button"
			data-testid="sleep-wake"
			:disabled="busy"
			@click="wake">
			{{ t('chorestrike', 'Wake up') }}
		</button>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed, nextTick, ref } from 'vue'
import { useTaskCycleStore } from '../../store/taskCycle.js'
import { showRefusal } from '../../utils/errorToast.js'
import { asleepLabel } from '../../utils/taskDisplay.js'

/**
 * Wake a sleeping task from its opened row (CS-PLAN-091 WP-B4, ADR-021
 * decision 7): for adults and the owner; the caller shows it to them only
 * and the server decides. A task is put to sleep in the one form (the
 * operator's GUI review of WP-B6); it also wakes on its wake date and by a
 * completion.
 */
const props = defineProps({
	roomTaskId: { type: Number, required: true },
	/** The row's `cycle` block. */
	cycle: { type: Object, default: null },
})

const taskCycleStore = useTaskCycleStore()

const busy = ref(false)
const woke = ref(false)
const state = ref(null)

const asleep = computed(() => props.cycle?.state === 'asleep')

async function wake() {
	busy.value = true
	try {
		await taskCycleStore.setSleeping(props.roomTaskId, false, null)
		woke.value = true
		await nextTick()
		state.value?.focus()
	} catch (error) {
		// The store logged it; the toast says which rule applied.
		showRefusal(error, t('chorestrike', 'Could not wake the task up'))
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.sleep-control {
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
.sleep-state:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}
</style>
