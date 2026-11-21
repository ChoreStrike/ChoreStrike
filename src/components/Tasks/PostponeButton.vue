<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<span v-if="cycle && cycle.cycleKey" class="postpone">
		<button
			type="button"
			class="postpone-button"
			data-testid="postpone-button"
			:aria-disabled="off ? 'true' : 'false'"
			:aria-describedby="reason ? reasonId : undefined"
			@click="postpone">
			<span>{{ t('chorestrike', 'Postpone') }}</span>
			<span v-if="cycle.canPostpone" class="postpone-left" data-testid="postpone-left">{{ leftLabel }}</span>
		</button>
		<!-- The reason stays readable without a pointer (RF-091-09). -->
		<span v-if="reason"
			:id="reasonId"
			class="postpone-reason"
			data-testid="postpone-reason">{{ reason }}</span>
	</span>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'
import { useTaskCycleStore } from '../../store/taskCycle.js'
import { showPostponeError } from '../../utils/errorToast.js'

/**
 * Postpone the current window of a task (CS-PLAN-091 WP-B3): every member
 * may, up to the limit the server names per cycle, each press by half the
 * flex days. The row's `cycle` block says whether it is possible; a task
 * without flex days and a red task cannot be postponed, and the button
 * says why next to it.
 */
const props = defineProps({
	roomTaskId: { type: Number, required: true },
	/** The row's `cycle` block; a task without a cycle has no button. */
	cycle: { type: Object, default: null },
	/** The task's flex days: none means it cannot be postponed at all. */
	flexDays: { type: Number, default: 0 },
})

const taskCycleStore = useTaskCycleStore()
const reasonId = `postpone-reason-${props.roomTaskId}`

const leftLabel = computed(() => t('chorestrike', '{count}× left', {
	count: Math.max(0, (props.cycle?.postponeLimit ?? 0) - (props.cycle?.postponeCount ?? 0)),
}))

// Off, never `disabled`: a disabled button drops the keyboard focus of
// the press that turned it off, and hides its reason from a focus.
const off = computed(() => !props.cycle?.canPostpone || taskCycleStore.isPostponing(props.roomTaskId))

/** Why the button is off; empty while it can be pressed. */
const reason = computed(() => {
	if (!props.cycle || props.cycle.canPostpone) {
		return ''
	}
	if (props.cycle.state === 'overdue') {
		return t('chorestrike', 'An overdue task cannot be postponed')
	}
	return props.flexDays > 0
		? t('chorestrike', 'This task was already postponed {count} times', { count: props.cycle.postponeLimit ?? props.cycle.postponeCount })
		: t('chorestrike', 'A task without flex days cannot be postponed')
})

async function postpone() {
	if (off.value) {
		return
	}
	try {
		await taskCycleStore.postpone(props.roomTaskId)
	} catch (error) {
		// The store logged it; the toast says which rule applied.
		showPostponeError(error)
	}
}
</script>

<style scoped>
.postpone {
	display: inline-flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.4rem;
}

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

.postpone-button[aria-disabled='true'] {
	opacity: 0.6;
	cursor: not-allowed;
}

.postpone-button:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.postpone-left,
.postpone-reason {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}
</style>
