<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="template-picker" data-testid="task-template-picker">
		<div class="picker-header">
			<div class="picker-tabs">
				<button
					type="button"
					class="picker-tab active"
					data-testid="picker-tab-template">
					{{ t('chorestrike', 'From template') }}
				</button>
				<button
					type="button"
					class="picker-tab"
					data-testid="picker-tab-custom"
					@click="$emit('custom')">
					{{ t('chorestrike', 'Custom task') }}
				</button>
			</div>
			<div class="level-toggle">
				<button
					v-for="lvl in setupStore.levels"
					:key="lvl.id"
					type="button"
					class="level-pill"
					:class="{ selected: selectedLevel === lvl.id }"
					:data-testid="'picker-level-' + lvl.id"
					@click="selectedLevel = lvl.id">
					{{ lvl.label }}
				</button>
			</div>
		</div>

		<p v-if="setupStore.loading">
			{{ t('chorestrike', 'Loading...') }}
		</p>
		<ul v-else class="template-list">
			<li
				v-for="tpl in filteredTemplates"
				:key="tpl.id"
				class="template-item"
				:class="{ disabled: !isAvailable(tpl), expanded: expandedId === tpl.id }">
				<button
					type="button"
					class="template-row"
					:disabled="!isAvailable(tpl)"
					:data-testid="'picker-template-' + tpl.id"
					@click="select(tpl)">
					<span class="template-name">{{ tpl.name }}</span>
					<span class="badge" :class="isAvailable(tpl) ? 'ok' : 'off'">
						{{ isAvailable(tpl) ? t('chorestrike', 'Available') : t('chorestrike', 'Not in this level') }}
					</span>
				</button>
				<button
					v-if="isAvailable(tpl)"
					type="button"
					class="adjust-toggle"
					:data-testid="'picker-adjust-' + tpl.id"
					@click="toggleAdjust(tpl.id)">
					{{ t('chorestrike', 'Adjust') }}
				</button>
				<div v-if="expandedId === tpl.id && isAvailable(tpl)" class="adjust-form" :data-testid="'picker-overrides-' + tpl.id">
					<label>{{ t('chorestrike', 'Task Name') }}
						<input v-model="overrides.name" type="text" :placeholder="tpl.name">
					</label>
					<label>{{ t('chorestrike', 'Frequency') }}
						<select v-model="overrides.frequencyType">
							<option value="daily">{{ t('chorestrike', 'Daily') }}</option>
							<option value="weekly">{{ t('chorestrike', 'Weekly') }}</option>
							<option value="monthly">{{ t('chorestrike', 'Monthly') }}</option>
						</select>
					</label>
					<label>{{ t('chorestrike', 'Estimated time') }} ({{ t('chorestrike', 'Minutes') }})
						<input v-model.number="overrides.estimatedMinutes" type="number" min="1">
					</label>
					<label>{{ t('chorestrike', 'Energy') }} (1–5)
						<input v-model.number="overrides.energyCost"
							type="number"
							min="1"
							max="5">
					</label>
					<label>{{ t('chorestrike', 'Priority') }} (1–3)
						<input v-model.number="overrides.priority"
							type="number"
							min="1"
							max="3">
					</label>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { useSetupStore } from '../../store/setup.js'
import { useRoomTasksStore } from '../../store/roomTasks.js'
import { logger } from '../../utils/logger.js'

export default {
	name: 'TaskTemplatePicker',

	props: {
		roomId: { type: Number, required: true },
		roomType: { type: String, default: 'generic' },
	},

	emits: ['custom', 'created', 'error'],

	setup() {
		const setupStore = useSetupStore()
		const roomTasksStore = useRoomTasksStore()
		return { setupStore, roomTasksStore }
	},

	data() {
		return {
			selectedLevel: 'putzteufel',
			selectedTemplateId: null,
			expandedId: null,
			overrides: this.freshOverrides(),
		}
	},

	computed: {
		filteredTemplates() {
			const templates = this.setupStore.templates
			return templates.filter(tpl => {
				const types = tpl.roomTypes || []
				return types.includes(this.roomType) || types.includes('household')
			})
		},
	},

	async mounted() {
		if (!this.setupStore.hasCatalog) {
			try {
				await this.setupStore.fetchCatalog()
			} catch (error) {
				logger.error('Template picker catalog load failed', error, { component: 'TaskTemplatePicker', action: 'mounted' })
			}
		}
	},

	methods: {
		t,
		freshOverrides() {
			return {
				name: null,
				frequencyType: null,
				estimatedMinutes: null,
				energyCost: null,
				priority: null,
			}
		},
		isAvailable(tpl) {
			return (tpl.levels || {})[this.selectedLevel] != null
		},
		toggleAdjust(id) {
			this.expandedId = this.expandedId === id ? null : id
			this.selectedTemplateId = id
			this.overrides = this.freshOverrides()
		},
		async select(tpl) {
			this.selectedTemplateId = tpl.id
			const payload = {
				roomId: this.roomId,
				templateId: tpl.id,
				level: this.selectedLevel,
			}
			if (this.expandedId === tpl.id) {
				const overrides = {}
				for (const [key, value] of Object.entries(this.overrides)) {
					if (value !== null && value !== '' && value !== undefined) {
						overrides[key] = value
					}
				}
				if (Object.keys(overrides).length > 0) {
					payload.overrides = overrides
				}
			}
			try {
				const created = await this.roomTasksStore.createFromTemplate(payload)
				this.$emit('created', created)
			} catch (error) {
				this.$emit('error', error)
			}
		},
	},
}
</script>

<style scoped>
.template-picker { display: flex; flex-direction: column; gap: 0.75rem; }

.picker-header { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; flex-wrap: wrap; }

.picker-tabs { display: flex; gap: 0.25rem; }

.picker-tab { background: transparent; border: 1px solid var(--color-border); border-radius: 8px; padding: 0.4rem 0.75rem; cursor: pointer; }

.picker-tab.active { background: var(--color-primary); color: white; border-color: var(--color-primary); }

.level-toggle { display: flex; gap: 0.25rem; }

.level-pill { border: 1px solid var(--color-border); border-radius: 999px; padding: 0.25rem 0.6rem; background: transparent; cursor: pointer; font-size: 0.8rem; }

.level-pill.selected { background: var(--color-primary-light); border-color: var(--color-primary); }

.template-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.25rem; }

.template-item { border: 1px solid var(--color-border); border-radius: 8px; padding: 0.5rem; display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }

.template-item.disabled { opacity: 0.5; }

.template-row { flex: 1; display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; background: transparent; border: none; cursor: pointer; text-align: start; padding: 0.25rem; }

.template-row:disabled { cursor: not-allowed; }

.badge { font-size: 0.7rem; padding: 0.1rem 0.4rem; border-radius: 999px; }

.badge.ok { background: var(--color-success); color: white; }

.badge.off { background: var(--color-background-dark); }

.adjust-toggle { background: transparent; border: none; color: var(--color-primary); cursor: pointer; font-size: 0.8rem; }

.adjust-form { flex-basis: 100%; display: grid; grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr)); gap: 0.5rem; margin-top: 0.5rem; }

.adjust-form label { display: flex; flex-direction: column; font-size: 0.8rem; gap: 0.2rem; }

.adjust-form input, .adjust-form select { padding: 0.3rem; }
</style>
