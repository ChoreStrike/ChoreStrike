<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="window-note">
		<label v-if="!locked" class="field">
			<span>{{ t('chorestrike', 'First date') }}</span>
			<input :value="firstDate"
				type="date"
				data-testid="task-next-due-input"
				@input="$emit('update:firstDate', $event.target.value)">
			<span class="hint">{{ roomTask ? t('chorestrike', 'The first window is around this day until the task is done for the first time.') : t('chorestrike', 'Leave empty to start tomorrow') }}</span>
		</label>
		<p v-else class="last-done" data-testid="task-last-done">
			{{ t('chorestrike', 'Last done: {date}', { date: lastDone }) }}
			<span class="hint">{{ t('chorestrike', 'To move the next window, change the date of the latest entry in the history.') }}</span>
		</p>
		<div class="next-window" data-testid="task-next-window">
			<strong role="status">{{ windowLabel }}</strong>
			<span>{{ t('chorestrike', 'It follows from the dates and the rhythm and is never edited directly.') }}</span>
		</div>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'
import { useHouseStore } from '../../store/house.js'
import { addDaysToKey, dateKeyInZone, formatDayKey, todayKeyInZone } from '../../utils/dateKeys.js'
import { asleepLabel } from '../../utils/taskDisplay.js'

/**
 * The first date and the read-only next window of the one form
 * (D-091-19, ADR-021 decision 1). Until the first completion the first
 * date moves the window; afterwards the date correction of the latest
 * entry does. The next window is the room task's `cycle` block, or for a
 * new task the first date with its flex days.
 */
const props = defineProps({
	firstDate: { type: String, default: '' },
	/** The task was done already: the first date no longer moves the window. */
	locked: { type: Boolean, default: false },
	/** The room task being edited, null for a new one. */
	roomTask: { type: Object, default: null },
	/** The form's rhythm, for the window of a new task. */
	rhythm: { type: Object, required: true },
	/** The saved rhythm of the task being edited. */
	storedRhythm: { type: Object, default: null },
})
defineEmits(['update:firstDate'])

const houseStore = useHouseStore()

const lastDone = computed(() => formatDayKey(dateKeyInZone(props.roomTask?.lastCompleted, houseStore.timezone)))

// A first date or rhythm edited before the first completion shows the
// window it will give; otherwise the saved window stands until the save.
const edited = computed(() => !props.locked && (props.firstDate !== (props.roomTask?.anchorOn ?? '')
	|| JSON.stringify(props.rhythm) !== JSON.stringify(props.storedRhythm)))

const windowLabel = computed(() => {
	const cycle = props.roomTask?.cycle
	if (cycle?.state === 'asleep') {
		return asleepLabel(cycle)
	}
	if (cycle?.windowStartOn && !edited.value) {
		return windowText(true, cycle.windowStartOn, cycle.windowEndOn)
	}
	const centre = props.firstDate || addDaysToKey(todayKeyInZone(houseStore.timezone), 1)
	const flex = props.rhythm.flexDays
	return windowText(false, addDaysToKey(centre, -flex), addDaysToKey(centre, flex))
})

/**
 * A window as one label.
 * @param {boolean} next The current window of a task, or the first one of a new task.
 * @param {string} from First day (`YYYY-MM-DD`).
 * @param {string} to Last day.
 * @return {string}
 */
function windowText(next, from, to) {
	const days = { from: formatDayKey(from), to: formatDayKey(to) }
	if (from === to) {
		return next ? t('chorestrike', 'Next window: {from}', days) : t('chorestrike', 'First window: {from}', days)
	}
	return next ? t('chorestrike', 'Next window: {from} – {to}', days) : t('chorestrike', 'First window: {from} – {to}', days)
}
</script>

<style scoped>
.window-note {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.field {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.field input {
	min-height: 44px;
	color: var(--color-main-text);
}

.last-done {
	display: flex;
	flex-direction: column;
	margin: 0;
}

.hint {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.next-window {
	display: flex;
	flex-direction: column;
	gap: 0.15rem;
	padding: 0.6rem 0.75rem;
	border-radius: 10px;
	background: var(--color-primary-element-light);
	color: var(--color-main-text);
	font-size: 0.9rem;
}
</style>
