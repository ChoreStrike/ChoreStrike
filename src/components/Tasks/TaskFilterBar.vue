<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="task-filter-bar">
		<div class="scope-filters" role="group" :aria-label="t('chorestrike', 'Task filter')">
			<button type="button"
				:aria-pressed="modelValue.scope === 'mine'"
				data-testid="tasks-filter-mine"
				@click="setScope('mine')">
				{{ t('chorestrike', 'My tasks') }}
			</button>
			<button type="button"
				:aria-pressed="modelValue.scope === 'unassigned'"
				data-testid="tasks-filter-unassigned"
				@click="setScope('unassigned')">
				{{ t('chorestrike', 'Unassigned') }}
			</button>
			<button type="button"
				:aria-pressed="modelValue.scope === 'all'"
				data-testid="tasks-filter-all"
				@click="setScope('all')">
				{{ t('chorestrike', 'Everyone') }}
			</button>
			<TaskPeopleFilter :scope="modelValue.scope"
				:people="modelValue.people"
				:unassigned="modelValue.unassigned"
				:members="members"
				:actorId="actorId"
				:disabled="membersDisabled"
				@change="update" />
			<button type="button"
				class="filters-toggle"
				aria-controls="task-filter-panel"
				:aria-expanded="open ? 'true' : 'false'"
				data-testid="tasks-filter-toggle"
				@click="open = !open">
				{{ activeCount > 0 ? t('chorestrike', 'Filters ({count} active)', { count: activeCount }) : t('chorestrike', 'Filters') }}
				<ExpandChevron :open="open" />
			</button>
		</div>
		<p v-if="modelValue.scope === 'mine'" class="scope-hint">
			{{ t('chorestrike', 'Your tasks and unassigned tasks') }}
		</p>
		<!-- The quick filter: a floor or one of its rooms. -->
		<RoomPicker class="room-quick-filter"
			filter
			:modelValue="modelValue.room ? Number(modelValue.room) : null"
			:floorId="modelValue.floor ? Number(modelValue.floor) : null"
			@select="update({ floor: $event.floorId === null ? '' : String($event.floorId), room: $event.roomId === null ? '' : String($event.roomId) })" />
		<div v-show="open" id="task-filter-panel" class="filters-section">
			<div v-for="select in optionSelects" :key="select.key" class="filter-group">
				<label :for="`task-${select.key}-filter`">{{ select.label }}</label>
				<select :id="`task-${select.key}-filter`"
					:value="modelValue[select.key]"
					class="filter-select"
					:data-testid="`${select.key}-filter`"
					@change="update({ [select.key]: $event.target.value })">
					<option v-for="option in select.options" :key="option.value" :value="option.value">
						{{ option.label }}
					</option>
				</select>
			</div>
			<div class="filter-group search-group">
				<label for="task-search-filter">{{ t('chorestrike', 'Search') }}</label>
				<div class="search-input-wrapper">
					<Icon name="Search" :size="16" class="search-icon" />
					<input id="task-search-filter"
						:value="modelValue.search"
						type="text"
						class="search-input"
						data-testid="search-input"
						:placeholder="t('chorestrike', 'Search tasks…')"
						@input="update({ search: $event.target.value })">
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import ExpandChevron from '../Common/ExpandChevron.vue'
import Icon from '../Common/Icon.vue'
import RoomPicker from './RoomPicker.vue'
import TaskPeopleFilter from './TaskPeopleFilter.vue'
import { ENERGY_LISTED_MAX, ENERGY_LONGER } from '../../constants/scheduling.js'
import { longerTaskLabel } from '../../utils/taskDisplay.js'

// Panel values that count as "no filter" for the active-filter badge.
const PANEL_DEFAULTS = { status: 'all', priority: 'all', difficulty: 'all', visibility: 'all', category: 'all' }

/**
 * Whether the viewport is phone-sized; the filter panel starts collapsed
 * there (CS-PLAN-084 D-084-02).
 * @return {boolean}
 */
function isPhoneViewport() {
	return typeof window !== 'undefined'
		&& typeof window.matchMedia === 'function'
		&& window.matchMedia('(max-width: 768px)').matches
}

/**
 * TaskManager filter surface (CS-PLAN-084 WP-D, RF-080-16; quick filters
 * from the GUI review of CS-PLAN-091 WP-B6): the scope buttons with the
 * people they stand for, the floor or room, and a collapsible panel for
 * the detail filters (status, priority, energy, visibility, category,
 * search). Stateless apart from the panel toggle — the filter object is a
 * v-model owned by the view.
 */
export default {
	name: 'TaskFilterBar',

	components: { ExpandChevron, Icon, RoomPicker, TaskPeopleFilter },

	props: {
		/** Filter values: { scope, people, unassigned, floor, room, status, priority, difficulty, visibility, category, search }. */
		modelValue: {
			type: Object,
			required: true,
		},

		/** Household members for the people filter. */
		members: {
			type: Array,
			default: () => [],
		},

		/** Disables the people filter while member names are unavailable. */
		membersDisabled: {
			type: Boolean,
			default: false,
		},

		/** The viewer, whose tasks *My tasks* shows. */
		actorId: {
			type: String,
			default: null,
		},

		/** The household's categories as `{ value, label }` (CS-PLAN-090 WP-I). */
		categories: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:modelValue'],

	data() {
		return { open: !isPhoneViewport() }
	},

	computed: {
		activeCount() {
			const changed = Object.keys(PANEL_DEFAULTS).filter((key) => (this.modelValue[key] ?? PANEL_DEFAULTS[key]) !== PANEL_DEFAULTS[key]).length
			return changed + (this.modelValue.search.trim() ? 1 : 0)
		},

		optionSelects() {
			return [
				{
					key: 'status',
					label: t('chorestrike', 'Status'),
					options: [
						{ value: 'all', label: t('chorestrike', 'All Tasks') },
						{ value: 'active', label: t('chorestrike', 'Active') },
						{ value: 'overdue', label: t('chorestrike', 'Overdue') },
						{ value: 'due-today', label: t('chorestrike', 'Due Today') },
						{ value: 'completed', label: t('chorestrike', 'Completed') },
					],
				},
				{
					key: 'priority',
					label: t('chorestrike', 'Priority'),
					options: [
						{ value: 'all', label: t('chorestrike', 'All Priorities') },
						{ value: 'urgent', label: t('chorestrike', 'High') },
						{ value: 'normal', label: t('chorestrike', 'Normal') },
						{ value: 'low', label: t('chorestrike', 'Low') },
					],
				},
				{
					key: 'difficulty',
					label: t('chorestrike', 'Energy'),
					options: [
						{ value: 'all', label: t('chorestrike', 'All energy levels') },
						...Array.from({ length: ENERGY_LISTED_MAX }, (_, index) => ({
							value: String(index + 1),
							label: '⚡'.repeat(index + 1),
						})),
						{ value: ENERGY_LONGER, label: longerTaskLabel() },
					],
				},
				{
					key: 'visibility',
					label: t('chorestrike', 'Visibility'),
					options: [
						{ value: 'all', label: t('chorestrike', 'All') },
						{ value: 'private', label: t('chorestrike', 'Private') },
					],
				},
				{
					key: 'category',
					label: t('chorestrike', 'Category'),
					options: [{ value: 'all', label: t('chorestrike', 'All categories') }, ...this.categories],
				},
			]
		},
	},

	methods: {
		t,

		update(patch) {
			this.$emit('update:modelValue', { ...this.modelValue, ...patch })
		},

		setScope(scope) {
			this.update({ scope, people: [], unassigned: true })
		},
	},
}
</script>

<style scoped>
.filters-section {
	display: flex;
	flex-wrap: wrap;
	gap: 1rem;
	margin-bottom: 1.5rem;
	padding: 1rem;
	background: var(--color-background-dark);
	border-radius: 8px;
}

.scope-filters {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	margin-block-end: 0.5rem;
}

/* The people filter's buttons are part of the row. */
.scope-filters :deep(button) {
	display: inline-flex;
	align-items: center;
	gap: 0.5rem;
	min-block-size: 44px;
	padding: 0.5rem 1rem;
	border: 1px solid var(--color-text-maxcontrast);
	border-radius: 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.scope-filters :deep(button[aria-pressed='true']) {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-color: var(--color-primary-element);
	font-weight: 700;
}

.scope-filters .filters-toggle {
	margin-inline-start: auto;
}

.scope-filters :deep(button:focus-visible),
.filter-select:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.scope-filters :deep(button:disabled) {
	opacity: 0.6;
	cursor: default;
}

.scope-hint {
	margin-block: 0 0.5rem;
	color: var(--color-text-maxcontrast);
}

.room-quick-filter {
	max-inline-size: 24rem;
	margin-block-end: 1rem;
}

.filter-group {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.filter-group label {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.filter-select {
	min-block-size: 44px;
	padding: 0.5rem 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.9rem;
	min-width: 150px;
}

.search-group {
	flex: 1;
	min-width: 200px;
}

.search-input-wrapper {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.5rem 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
}

.search-icon {
	color: var(--color-text-maxcontrast);
}

.search-input {
	flex: 1;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.9rem;
	outline: none;
}

@media (max-width: 768px) {
	.filters-section {
		flex-direction: column;
	}

	.filter-group {
		width: 100%;
	}

	.filter-select,
	.search-input-wrapper {
		width: 100%;
	}
}
</style>
