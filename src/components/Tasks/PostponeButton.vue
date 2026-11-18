<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<button
		v-if="cycle && cycle.cycleKey"
		type="button"
		class="postpone-button"
		data-testid="postpone-button"
		:disabled="!cycle.canPostpone || taskCycleStore.isPostponing(roomTaskId)"
		:title="reason"
		@click="postpone">
		<span>{{ t('chorestrike', 'Postpone') }}</span>
		<span v-if="cycle.canPostpone" class="postpone-left" data-testid="postpone-left">{{ leftLabel }}</span>
	</button>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'
import { useTaskCycleStore } from '../../store/taskCycle.js'
import { showPostponeError } from '../../utils/errorToast.js'

/**
 * Postpone the current window of a task (CS-PLAN-091 WP-B3): every member
 * may, up to three times per cycle, each press by half the flex days. The
 * row's `cycle` block says whether it is possible; a task without flex
 * days and a red task cannot be postponed, and the button says why.
 */
const props = defineProps({
	roomTaskId: { type: Number, required: true },
	/** The row's `cycle` block; a task without a cycle has no button. */
	cycle: { type: Object, default: null },
	/** The task's flex days: none means it cannot be postponed at all. */
	flexDays: { type: Number, default: 0 },
})

/** A cycle is postponed at most this often (ADR-021 decision 6). */
const MAX_POSTPONEMENTS = 3

const taskCycleStore = useTaskCycleStore()

const leftLabel = computed(() => t('chorestrike', '{count}× left', {
	count: MAX_POSTPONEMENTS - (props.cycle?.postponeCount ?? 0),
}))

/** Why the button is off; empty while it can be pressed. */
const reason = computed(() => {
	if (props.cycle?.canPostpone) {
		return ''
	}
	if (props.cycle?.state === 'overdue') {
		return t('chorestrike', 'An overdue task cannot be postponed')
	}
	return props.flexDays > 0
		? t('chorestrike', 'This task was already postponed three times')
		: t('chorestrike', 'A task without flex days cannot be postponed')
})

async function postpone() {
	try {
		await taskCycleStore.postpone(props.roomTaskId)
	} catch (error) {
		// The store logged it; the toast says which rule applied.
		showPostponeError(error)
	}
}
</script>

<style scoped>
.postpone-button {
	display: inline-flex;
	align-items: center;
	gap: 0.4rem;
	min-height: 44px;
	padding: 0 0.9rem;
	border: 1px solid var(--color-text-maxcontrast);
	border-radius: 22px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.postpone-button:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.postpone-button:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.postpone-left {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}
</style>
