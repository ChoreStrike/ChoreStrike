<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<fieldset class="sub-tasks" data-testid="task-subtasks">
		<legend>{{ t('chorestrike', 'Sub-tasks') }}</legend>
		<ul ref="list" class="sub-task-list">
			<li v-for="(entry, index) in modelValue" :key="entry.key" class="sub-task">
				<input :value="entry.label"
					type="text"
					class="sub-task-label"
					maxlength="255"
					:aria-label="t('chorestrike', 'Sub-task {number}', { number: index + 1 })"
					data-testid="task-subtask-label"
					@input="relabel(index, $event.target.value)">
				<button v-if="index > 0"
					type="button"
					class="icon-button"
					:aria-label="t('chorestrike', 'Move “{label}” up', { label: entry.label })"
					data-testid="task-subtask-up"
					@click="moveUp(index)">
					<Icon name="ChevronUp" :size="18" />
				</button>
				<button type="button"
					class="icon-button"
					:aria-label="t('chorestrike', 'Remove “{label}”', { label: entry.label })"
					data-testid="task-subtask-remove"
					@click="remove(index)">
					<Icon name="X" :size="18" />
				</button>
			</li>
		</ul>
		<!-- Inside the one form: no form of its own, and Enter adds instead of saving. -->
		<div class="sub-task-add">
			<input ref="newInput"
				v-model="draft"
				type="text"
				maxlength="255"
				class="sub-task-label"
				:placeholder="t('chorestrike', 'New sub-task')"
				:aria-label="t('chorestrike', 'New sub-task')"
				data-testid="task-subtask-new"
				@keydown.enter.prevent="add">
			<button type="button"
				class="add-button"
				:disabled="!draft.trim()"
				data-testid="task-subtask-add"
				@click="add">
				<Icon name="Plus" :size="18" />
				<span>{{ t('chorestrike', 'Add') }}</span>
			</button>
		</div>
	</fieldset>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { nextTick, ref } from 'vue'
import Icon from '../Common/Icon.vue'

/**
 * The sub-tasks of the one form (CS-PLAN-091 WP-B6): saved with the form
 * in one request, never one by one. An entry keeps its `id` while it is
 * the same sub-task, so its ticks stay with it; a new one has none.
 */
const props = defineProps({
	/** `[{ id?, label, key }]` in their order; `key` is stable while the form is open. */
	modelValue: { type: Array, required: true },
})
const emit = defineEmits(['update:modelValue'])

const draft = ref('')
const list = ref(null)
const newInput = ref(null)
let nextKey = 0

/**
 * Keep the keyboard where the change happened: the pressed control moved
 * or went with its entry.
 * @param {number} index The entry to focus; past the list, the new field.
 * @param {string} control The control of that entry, by its test id; its label when it has none.
 */
async function focusEntry(index, control) {
	await nextTick()
	const entry = list.value?.children[index]
	const target = entry?.querySelector(`[data-testid="${control}"]`) ?? entry?.querySelector('input') ?? newInput.value
	target?.focus()
}

/**
 * Emit the list with one change.
 * @param {object[]} entries The new list.
 */
function change(entries) {
	emit('update:modelValue', entries)
}

function relabel(index, label) {
	change(props.modelValue.map((entry, at) => (at === index ? { ...entry, label } : entry)))
}

function moveUp(index) {
	const entries = [...props.modelValue]
	entries.splice(index - 1, 2, entries[index], entries[index - 1])
	change(entries)
	// The first entry has no way up: its label takes the focus.
	focusEntry(index - 1, 'task-subtask-up')
}

function remove(index) {
	change(props.modelValue.filter((entry, at) => at !== index))
	// The next entry, else the one before, else the new field.
	focusEntry(Math.min(index, props.modelValue.length - 2), 'task-subtask-label')
}

function add() {
	const label = draft.value.trim()
	if (!label) {
		return
	}
	change([...props.modelValue, { label, key: `new-${nextKey++}` }])
	draft.value = ''
}
</script>

<style scoped>
.sub-tasks {
	margin: 0;
	padding: 0;
	border: none;
}

.sub-tasks legend {
	margin-bottom: 0.25rem;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.sub-task-list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.sub-task,
.sub-task-add {
	display: flex;
	align-items: center;
	gap: 0.25rem;
	min-height: 44px;
	border-bottom: 1px solid var(--color-border);
}

.sub-task-label {
	flex: 1;
	min-width: 0;
	min-height: 44px;
}

.icon-button,
.add-button {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 0.25rem;
	min-width: 44px;
	min-height: 44px;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
}

.add-button {
	color: var(--color-primary-element);
	font-weight: 600;
}

.icon-button:focus-visible,
.add-button:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}
</style>
