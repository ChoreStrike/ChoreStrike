<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="people-filter" data-testid="people-filter">
		<button type="button"
			class="people-toggle"
			:aria-expanded="open ? 'true' : 'false'"
			:aria-controls="panelId"
			:disabled="disabled"
			data-testid="people-filter-toggle"
			@click="open = !open">
			{{ scope === 'custom' ? t('chorestrike', 'People ({count})', { count: people.length + (unassigned ? 1 : 0) }) : t('chorestrike', 'People') }}
			<ExpandChevron :open="open" />
		</button>
		<div v-if="open && !disabled"
			:id="panelId"
			class="people-panel"
			role="group"
			:aria-label="t('chorestrike', 'People')">
			<div class="people-actions">
				<button type="button" data-testid="people-filter-all" @click="emit('change', { scope: 'all', people: [], unassigned: true })">
					{{ t('chorestrike', 'All') }}
				</button>
				<button type="button" data-testid="people-filter-none" @click="change([], false)">
					{{ t('chorestrike', 'None') }}
				</button>
			</div>
			<label v-for="member in members" :key="member.nextcloudUserId" class="people-option">
				<input type="checkbox"
					:checked="chosen.people.includes(member.nextcloudUserId)"
					:data-testid="`people-filter-${member.nextcloudUserId}`"
					@change="toggle(member.nextcloudUserId)">
				<span>{{ member.displayName || member.nextcloudUserId }}</span>
			</label>
			<label class="people-option">
				<input type="checkbox"
					:checked="chosen.unassigned"
					data-testid="people-filter-unassigned"
					@change="change(chosen.people, !chosen.unassigned)">
				<span>{{ t('chorestrike', 'Unassigned') }}</span>
			</label>
		</div>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed, ref, useId } from 'vue'
import ExpandChevron from '../Common/ExpandChevron.vue'
import { peopleOfScope, scopeOfPeople } from '../../utils/taskFilters.js'

/**
 * The people of the task list (CS-PLAN-091 WP-B6, the operator's GUI
 * review): a tick per member and one for unassigned work, with the
 * buttons All and None. The three scope buttons are its presets, so both
 * always say the same.
 */
const props = defineProps({
	/** `mine`, `unassigned`, `all` or `custom`. */
	scope: { type: String, required: true },
	/** The ticked members of a custom choice. */
	people: { type: Array, default: () => [] },
	/** Whether a custom choice includes unassigned work. */
	unassigned: { type: Boolean, default: false },
	members: { type: Array, default: () => [] },
	/** The viewer, whose tasks *My tasks* shows. */
	actorId: { type: String, default: null },
	/** Off while the member names are unavailable. */
	disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['change'])

const panelId = `task-people-filter-panel-${useId()}`
const open = ref(false)

const memberIds = computed(() => props.members.map((member) => member.nextcloudUserId))
/** What the scope ticks. */
const chosen = computed(() => peopleOfScope(props, memberIds.value))

/**
 * @param {string[]} people The ticked members.
 * @param {boolean} unassigned Whether unassigned work is ticked.
 */
function change(people, unassigned) {
	emit('change', scopeOfPeople(people, unassigned, memberIds.value, props.actorId))
}

/** @param {string} userId The member whose tick flips. */
function toggle(userId) {
	const people = chosen.value.people.includes(userId)
		? chosen.value.people.filter((id) => id !== userId)
		: [...chosen.value.people, userId]
	change(people, chosen.value.unassigned)
}
</script>

<style scoped>
.people-filter {
	display: contents;
}

.people-panel {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.5rem 1rem;
	order: 10;
	flex-basis: 100%;
	padding: 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-background-dark);
}

.people-actions {
	display: flex;
	gap: 0.5rem;
}

.people-option {
	display: inline-flex;
	align-items: center;
	gap: 0.4rem;
	min-block-size: 44px;
	color: var(--color-main-text);
	cursor: pointer;
}

.people-option input {
	inline-size: 1.15rem;
	block-size: 1.15rem;
}
</style>
