<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<form
		class="absence-form"
		:data-testid="`${idPrefix}-form`"
		@submit.prevent="$emit('submit')">
		<h2 class="absence-form-title">
			{{ title }}
		</h2>
		<slot />
		<div class="absence-range">
			<div class="absence-field">
				<label :for="`${idPrefix}-start`">{{ t('chorestrike', 'From') }}</label>
				<input
					:id="`${idPrefix}-start`"
					type="date"
					class="absence-input"
					:value="modelValue.startDate"
					:min="min"
					:max="max"
					:data-testid="`${idPrefix}-start`"
					@input="update('startDate', $event.target.value)">
			</div>
			<div class="absence-field">
				<label :for="`${idPrefix}-end`">{{ t('chorestrike', 'Until') }}</label>
				<input
					:id="`${idPrefix}-end`"
					type="date"
					class="absence-input"
					:value="modelValue.endDate"
					:min="modelValue.startDate || min"
					:max="max"
					:data-testid="`${idPrefix}-end`"
					@input="update('endDate', $event.target.value)">
			</div>
		</div>
		<button
			type="submit"
			class="absence-submit"
			:data-testid="`${idPrefix}-submit`"
			:disabled="!complete || busy">
			{{ t('chorestrike', 'Enter') }}
		</button>
		<p v-if="hint" class="absence-form-hint">
			{{ hint }}
		</p>
	</form>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

/**
 * One period, from and until (ADR-020): the form of an absence. The
 * parent owns the draft, so a failed command keeps what was typed.
 */
export default {
	name: 'AbsenceRangeForm',

	props: {
		/** The draft: `{ startDate, endDate }` as `YYYY-MM-DD` or ''. */
		modelValue: {
			type: Object,
			required: true,
		},

		/** Prefix of the element ids and test ids. */
		idPrefix: {
			type: String,
			required: true,
		},

		title: {
			type: String,
			required: true,
		},

		hint: {
			type: String,
			default: '',
		},

		/** Earliest and latest selectable day, or '' for none. */
		min: {
			type: String,
			default: '',
		},

		max: {
			type: String,
			default: '',
		},

		/** An additional required field in the slot is still empty. */
		incomplete: {
			type: Boolean,
			default: false,
		},

		busy: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:modelValue', 'submit'],

	computed: {
		complete() {
			const { startDate, endDate } = this.modelValue
			return !this.incomplete && Boolean(startDate) && Boolean(endDate) && startDate <= endDate
		},
	},

	methods: {
		t,

		update(field, value) {
			this.$emit('update:modelValue', { ...this.modelValue, [field]: value })
		},
	},
}
</script>

<style scoped>
.absence-form {
	display: flex;
	flex-direction: column;
	gap: 0.6rem;
	padding: 0.75rem;
	border-radius: 12px;
	background: var(--color-background-hover);
}

.absence-form-title {
	margin: 0;
	font-size: 1rem;
	font-weight: 700;
}

.absence-range {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 0.5rem;
}

.absence-field {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	min-width: 0;
}

.absence-field label,
.absence-form-hint {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.absence-form-hint {
	margin: 0;
}

.absence-input {
	box-sizing: border-box;
	width: 100%;
	min-width: 0;
	min-height: 44px;
	margin: 0;
	padding: 0 0.75rem;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: 10px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.absence-submit {
	min-height: 44px;
	margin: 0;
	border: none;
	border-radius: 22px;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: 600;
	cursor: pointer;
}

.absence-submit:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}
</style>
