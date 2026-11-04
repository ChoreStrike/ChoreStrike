<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="energy-field">
		<select id="energyCost"
			v-model="choice"
			class="form-input"
			data-testid="task-energy-select">
			<option v-for="energy in ENERGY_LISTED_MAX" :key="energy" :value="String(energy)">
				{{ '⚡'.repeat(energy) }} {{ durationLabel(energy) }}
			</option>
			<option :value="ENERGY_LONGER">
				{{ longerTaskLabel() }}
			</option>
		</select>
		<template v-if="isLongerTask">
			<div class="energy-custom">
				<Icon name="Zap" :size="16" />
				<input v-model.number="draft"
					type="number"
					class="form-input"
					data-testid="task-energy-custom"
					required
					:min="LONGER_TASK_MIN"
					:max="ENERGY_MAX"
					:aria-label="t('chorestrike', 'Energy Cost')">
				<span>{{ durationLabel(modelValue) }}</span>
			</div>
			<p class="energy-hint" data-testid="task-energy-hint">
				{{ t('chorestrike', 'The planner only places a task on a day when it fits a person\'s energy budget. By default that is {weekday} energy on weekdays and {weekend} at weekends.', { weekday: DEFAULT_ENERGY_BUDGET.monday, weekend: DEFAULT_ENERGY_BUDGET.saturday }) }}
			</p>
		</template>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import { ENERGY_LISTED_MAX, ENERGY_LONGER, ENERGY_MAX } from '../../constants/scheduling.js'
import { DEFAULT_ENERGY_BUDGET } from '../../utils/energyBudgets.js'
import { longerTaskLabel } from '../../utils/taskDisplay.js'
import { Icon } from '../Common/index.js'

const props = defineProps({
	modelValue: { type: Number, required: true },
})
const emit = defineEmits(['update:modelValue'])

const LONGER_TASK_MIN = ENERGY_LISTED_MAX + 1
const MINUTES_PER_ENERGY = 10

const isLongerTask = computed(() => props.modelValue > ENERGY_LISTED_MAX)

const choice = computed({
	get: () => (isLongerTask.value ? ENERGY_LONGER : String(props.modelValue)),
	set: (value) => emit('update:modelValue', value === ENERGY_LONGER ? LONGER_TASK_MIN : Number(value)),
})

// The input keeps what is being typed: on the way to 12 the 1 is no task
// energy yet, and passing it on would close this input.
const draft = ref(props.modelValue)
watch(() => props.modelValue, (value) => {
	draft.value = value
})
watch(draft, (value) => {
	if (Number.isInteger(value) && value >= LONGER_TASK_MIN && value <= ENERGY_MAX) {
		emit('update:modelValue', value)
	}
})

/**
 * The approximate time of an energy value.
 *
 * @param {number} energy Energy value
 * @return {string} Translated label
 */
function durationLabel(energy) {
	return t('chorestrike', 'about {minutes} min', { minutes: energy * MINUTES_PER_ENERGY })
}
</script>

<style scoped>
.form-input {
	width: 100%;
	padding: 0.6rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.95rem;
}

.energy-custom {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-top: 0.5rem;
}

.energy-custom .form-input {
	width: 5rem;
}

.energy-hint {
	margin: 0.5rem 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}
</style>
