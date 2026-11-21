<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<fieldset class="rhythm" data-testid="task-rhythm">
		<legend>{{ t('chorestrike', 'Repeat') }}</legend>
		<SegmentedSwitch
			:modelValue="modelValue.mode"
			:options="modeOptions"
			:label="t('chorestrike', 'Kind of repetition')"
			testidPrefix="task-rhythm"
			@update:modelValue="update({ mode: $event, unit: $event === 'fixed' && modelValue.unit === 'day' ? 'week' : modelValue.unit })" />

		<div v-if="modelValue.mode !== 'once'" class="rhythm-every">
			<label :for="`${uid}-every`">{{ t('chorestrike', 'Every') }}</label>
			<input :id="`${uid}-every`"
				:value="modelValue.every"
				type="number"
				min="1"
				max="52"
				class="rhythm-number"
				data-testid="task-frequency-value"
				@input="update({ every: Number.parseInt($event.target.value, 10) || 1 })">
			<select :value="modelValue.unit"
				class="rhythm-unit"
				:aria-label="t('chorestrike', 'Unit')"
				data-testid="task-frequency-unit"
				@change="update({ unit: $event.target.value })">
				<option v-for="unit in units" :key="unit.value" :value="unit.value">
					{{ unit.label }}
				</option>
			</select>
		</div>

		<div v-if="modelValue.mode === 'fixed' && modelValue.unit === 'week'"
			class="rhythm-weekdays"
			role="group"
			:aria-label="t('chorestrike', 'Days of Week')">
			<button v-for="day in weekDays"
				:key="day.key"
				type="button"
				class="weekday"
				:aria-pressed="modelValue.weekdays.includes(day.key) ? 'true' : 'false'"
				:data-testid="`task-weekday-${day.key}`"
				@click="toggleWeekday(day.key)">
				{{ day.short }}
			</button>
		</div>
		<div v-if="modelValue.mode === 'fixed' && modelValue.unit === 'month'" class="rhythm-every">
			<label :for="`${uid}-day`">{{ t('chorestrike', 'on day') }}</label>
			<input :id="`${uid}-day`"
				:value="modelValue.monthDay"
				type="number"
				min="1"
				max="31"
				class="rhythm-number"
				data-testid="task-month-day"
				@input="update({ monthDay: Number.parseInt($event.target.value, 10) || null })">
			<span>{{ t('chorestrike', 'of the month (a shorter month uses its last day)') }}</span>
		</div>

		<div class="rhythm-flex">
			<span :id="`${uid}-flex`" class="flex-label">{{ t('chorestrike', 'Flex days') }}</span>
			<button type="button"
				class="stepper"
				:aria-label="t('chorestrike', 'Fewer flex days')"
				:disabled="modelValue.flexDays <= 0"
				data-testid="task-flex-less"
				@click="update({ flexDays: modelValue.flexDays - 1 })">
				−
			</button>
			<output class="flex-value" :aria-labelledby="`${uid}-flex`" data-testid="task-flex-days">± {{ modelValue.flexDays }}</output>
			<button type="button"
				class="stepper"
				:aria-label="t('chorestrike', 'More flex days')"
				:disabled="modelValue.flexDays >= flexCap"
				data-testid="task-flex-more"
				@click="update({ flexDays: modelValue.flexDays + 1 })">
				+
			</button>
		</div>
		<p class="hint">
			{{ flexHint }}
		</p>
		<p v-if="problem"
			class="field-error"
			role="alert"
			data-testid="task-rhythm-problem">
			{{ problem }}
		</p>
	</fieldset>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed, useId } from 'vue'
import SegmentedSwitch from '../Common/SegmentedSwitch.vue'
import { weekDays } from '../../constants/scheduling.js'
import { flexCapOf, rhythmProblem } from '../../utils/taskFormModel.js'

/**
 * The rhythm of the one form (CS-PLAN-091 WP-B6): after completion, a
 * fixed weekday or day of the month, or once; how often; and the flex
 * days around the planned day (FEAT-UX-039). The owner holds the value.
 */
const props = defineProps({
	/** The rhythm `{ mode, every, unit, weekdays, monthDay, flexDays }`. */
	modelValue: { type: Object, required: true },
	/** The rhythm the task has, when editing: a stored flex above the cap stays allowed. */
	stored: { type: Object, default: null },
})
const emit = defineEmits(['update:modelValue'])

const uid = `rhythm-${useId()}`

const modeOptions = [
	{ value: 'after', label: t('chorestrike', 'After completion') },
	{ value: 'fixed', label: t('chorestrike', 'Fixed day') },
	{ value: 'once', label: t('chorestrike', 'Once') },
]

const units = computed(() => {
	const one = props.modelValue.every === 1
	const all = [
		{ value: 'day', label: one ? t('chorestrike', 'day') : t('chorestrike', 'days') },
		{ value: 'week', label: one ? t('chorestrike', 'week') : t('chorestrike', 'weeks') },
		{ value: 'month', label: one ? t('chorestrike', 'month') : t('chorestrike', 'months') },
	]
	// A fixed rule falls on a weekday or a day of the month.
	return props.modelValue.mode === 'fixed' ? all.filter((unit) => unit.value !== 'day') : all
})

const flexCap = computed(() => flexCapOf(props.modelValue, props.stored))

const flexHint = computed(() => {
	const count = props.modelValue.flexDays
	if (count === 0) {
		return t('chorestrike', 'Only the planned day counts as on time.')
	}
	return count === 1
		? t('chorestrike', 'One day earlier or later counts as on time.')
		: t('chorestrike', '{count} days earlier or later count as on time.', { count })
})

const problem = computed(() => ({
	weekdays: t('chorestrike', 'Please select at least one day'),
	monthDay: t('chorestrike', 'The day of the month must be between 1 and 31'),
	flex: t('chorestrike', 'Up to {max} flex days for this rhythm', { max: flexCap.value }),
	every: t('chorestrike', 'The interval must be at least 1'),
})[rhythmProblem(props.modelValue, props.stored)] ?? '')

/**
 * Emit the rhythm with some values changed.
 * @param {object} patch The changed values.
 */
function update(patch) {
	emit('update:modelValue', { ...props.modelValue, ...patch })
}

/**
 * Add or remove a weekday of a fixed weekly rule.
 * @param {string} day The weekday.
 */
function toggleWeekday(day) {
	const days = props.modelValue.weekdays
	update({ weekdays: days.includes(day) ? days.filter((entry) => entry !== day) : [...days, day] })
}
</script>

<style scoped>
.rhythm {
	display: flex;
	flex-direction: column;
	gap: 0.6rem;
	margin: 0;
	padding: 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: 12px;
}

.rhythm legend {
	padding: 0 0.25rem;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.rhythm-every,
.rhythm-flex {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.5rem;
}

.rhythm-number {
	width: 4.5rem;
	min-height: 44px;
	text-align: center;
}

.rhythm-unit {
	flex: 1;
	min-height: 44px;
}

.rhythm-weekdays {
	display: grid;
	/* Seven in a row where they fit; a narrow dialog wraps them instead of overlapping. */
	grid-template-columns: repeat(auto-fill, minmax(44px, 1fr));
	gap: 0.25rem;
}

.weekday,
.stepper {
	min-width: 44px;
	min-height: 44px;
	border: 1px solid var(--color-border-dark);
	border-radius: 22px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.weekday[aria-pressed='true'] {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: 700;
}

.flex-label {
	flex: 1;
}

.flex-value {
	min-width: 3rem;
	text-align: center;
	font-weight: 600;
}

.hint {
	margin: -0.25rem 0 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.field-error {
	margin: 0;
	color: var(--color-error-text);
	font-size: 0.85rem;
}
</style>
