<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="template-selector">
		<label class="selector-label">{{ t('chorestrike', 'Task Template') }}</label>
		<div class="template-grid">
			<button
				v-for="template in templates"
				:key="template.id"
				class="template-button"
				:class="{ active: selectedTemplate === template.id }"
				@click="selectTemplate(template)">
				<Icon :name="template.icon" :size="20" />
				<span class="template-name">{{ template.name }}</span>
			</button>
		</div>
	</div>
</template>

<script>
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { Icon } from '../Common/index.js'

export default {
	name: 'RecurrencePresetSelector',

	components: {
		Icon,
	},

	emits: ['select'],

	setup(props, { emit }) {
		const selectedTemplate = ref(null)

		const templates = [
			{
				id: 'daily',
				name: t('chorestrike', 'Daily'),
				icon: 'Calendar',
				recurrence: 'daily',
			},
			{
				id: 'weekly',
				name: t('chorestrike', 'Weekly'),
				icon: 'CalendarDays',
				recurrence: 'weekly',
			},
			{
				id: 'monthly',
				name: t('chorestrike', 'Monthly'),
				icon: 'CalendarRange',
				recurrence: 'monthly',
			},
			{
				id: 'one-time',
				name: t('chorestrike', 'One-time'),
				icon: 'CheckCircle',
				recurrence: null,
			},
		]

		const selectTemplate = (template) => {
			selectedTemplate.value = template.id
			emit('select', template)
		}

		return {
			t,
			templates,
			selectedTemplate,
			selectTemplate,
		}
	},
}
</script>

<style scoped>
.template-selector {
	margin-bottom: 1rem;
}

.selector-label {
	display: block;
	margin-bottom: 0.5rem;
	font-weight: 500;
	color: var(--color-main-text);
}

.template-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
	gap: 0.5rem;
}

.template-button {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0.5rem;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	transition: all 0.2s ease;
}

.template-button:hover {
	border-color: var(--color-primary);
	background: var(--color-background-dark);
}

.template-button.active {
	border-color: var(--color-primary);
	background: var(--color-primary);
	color: var(--color-primary-text);
}

.template-name {
	font-size: 0.85rem;
}
</style>
