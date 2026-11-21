<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="template-fill" data-testid="task-template-fill">
		<button ref="toggleButton"
			type="button"
			class="template-toggle"
			:aria-expanded="open ? 'true' : 'false'"
			:aria-controls="panelId"
			data-testid="task-template-open"
			@click="toggle">
			<Icon name="ClipboardList" :size="18" />
			<span>{{ t('chorestrike', 'From template') }}</span>
		</button>
		<div v-if="open" :id="panelId" class="template-panel">
			<p v-if="failed" class="hint" role="alert">
				{{ t('chorestrike', 'Could not load the templates') }}
			</p>
			<template v-else>
				<label class="field">
					<span>{{ t('chorestrike', 'Room type') }}</span>
					<select v-model="roomType" data-testid="task-template-room-type">
						<option v-for="type in setupStore.roomTypes" :key="type.id" :value="type.id">
							{{ type.defaultName }}
						</option>
					</select>
				</label>
				<ul class="template-list">
					<li v-for="template in templates" :key="template.id">
						<button type="button"
							class="template-row"
							:data-testid="`task-template-${template.id}`"
							@click="pick(template)">
							<span class="template-name">{{ template.name }}</span>
							<span class="template-preview">{{ preview(template) }}</span>
						</button>
					</li>
				</ul>
				<p v-if="templates.length === 0" class="hint">
					{{ t('chorestrike', 'No templates for this room type') }}
				</p>
			</template>
		</div>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed, nextTick, ref, useId, watch } from 'vue'
import Icon from '../Common/Icon.vue'
import { useSetupStore } from '../../store/setup.js'
import { logger } from '../../utils/logger.js'
import { frequencyLabel } from '../../utils/taskDisplay.js'
import { templateFill } from '../../utils/taskFormModel.js'

/**
 * The *From template* panel of the one form (FEAT-UX-043, form part): a catalog
 * template only fills the form; whatever is saved is a task of its own.
 * The room type of the chosen room comes first.
 */
const props = defineProps({
	/** The room type of the room the form has chosen, when one is chosen. */
	preferredRoomType: { type: String, default: null },
})
const emit = defineEmits(['fill'])

/** The level whose rhythm a template brings (the setup wizard's default). */
const LEVEL = 'putzteufel'

const setupStore = useSetupStore()
const panelId = `task-template-panel-${useId()}`
const toggleButton = ref(null)
const open = ref(false)
const failed = ref(false)
const roomType = ref(props.preferredRoomType ?? 'generic')

watch(() => props.preferredRoomType, (type) => {
	if (type) {
		roomType.value = type
	}
})

// The household's templates fit every room (as the per-room picker had them).
const templates = computed(() => setupStore.templates.filter((template) => {
	const types = template.roomTypes || []
	return types.includes(roomType.value) || types.includes('household')
}))

async function toggle() {
	open.value = !open.value
	if (open.value && !setupStore.hasCatalog) {
		// Opening again is the retry of a failed read.
		failed.value = false
		try {
			await setupStore.fetchCatalog()
		} catch (error) {
			failed.value = true
			logger.error('Template catalog load failed', error, { component: 'TaskTemplateFill', action: 'toggle' })
		}
	}
}

/**
 * The short line under a template: its rhythm and energy.
 * @param {object} template A catalog template.
 * @return {string}
 */
function preview(template) {
	const rule = (template.levels || {})[LEVEL] || Object.values(template.levels || {}).find(Boolean) || {}
	return t('chorestrike', '{rhythm} · {energy} energy', { rhythm: frequencyLabel(rule), energy: template.energyCost })
}

async function pick(template) {
	emit('fill', templateFill(template, LEVEL))
	open.value = false
	// The pressed row is gone: focus stays in the form, on the button that opened it.
	await nextTick()
	toggleButton.value?.focus()
}
</script>

<style scoped>
.template-toggle {
	display: inline-flex;
	align-items: center;
	gap: 0.4rem;
	min-height: 44px;
	padding: 0 0.75rem;
	border: 1px solid var(--color-border-dark);
	border-radius: 22px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.template-panel {
	margin-top: 0.5rem;
	padding: 0.5rem;
	border: 1px solid var(--color-border);
	border-radius: 12px;
}

.field {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.field select {
	min-height: 44px;
	color: var(--color-main-text);
}

.template-list {
	margin: 0.5rem 0 0;
	padding: 0;
	max-height: 16rem;
	overflow-y: auto;
	list-style: none;
}

.template-row {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	width: 100%;
	min-height: 44px;
	padding: 0.4rem 0.5rem;
	border: none;
	border-bottom: 1px solid var(--color-border);
	background: transparent;
	color: var(--color-main-text);
	text-align: start;
	cursor: pointer;
}

.template-name {
	font-weight: 600;
}

.template-preview,
.hint {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}
</style>
