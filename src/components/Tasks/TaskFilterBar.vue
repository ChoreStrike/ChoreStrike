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
			<button type="button"
				class="filters-toggle"
				aria-controls="task-filter-panel"
				:aria-expanded="open ? 'true' : 'false'"
				data-testid="tasks-filter-toggle"
				@click="open = !open">
				{{ activeCount > 0 ? t('chorestrike', 'Filters ({count} active)', { count: activeCount }) : t('chorestrike', 'Filters') }}
				<Icon :name="open ? 'ChevronUp' : 'ChevronDown'" :size="16" />
			</button>
		</div>
		<p v-if="modelValue.scope === 'mine'" class="scope-hint">
			{{ t('chorestrike', 'Your tasks and unassigned tasks') }}
		</p>
		<div v-show="open" id="task-filter-panel" class="filters-section">
			<div class="filter-group">
				<label for="task-person-filter">{{ t('chorestrike', 'Person') }}</label>
				<select id="task-person-filter"
					:value="modelValue.person"
					:disabled="membersDisabled"
					class="filter-select"
					data-testid="person-filter"
					@change="update({ person: $event.target.value, scope: 'all' })">
					<option value="">
						{{ t('chorestrike', 'Everyone') }}
					</option>
					<option v-for="member in members" :key="member.nextcloudUserId" :value="member.nextcloudUserId">
						{{ member.displayName || member.nextcloudUserId }}
					</option>
				</select>
			</div>
			<div class="filter-group">
				<label for="task-floor-filter">{{ t('chorestrike', 'Floor') }}</label>
				<select id="task-floor-filter"
					:value="modelValue.floor"
					class="filter-select"
					data-testid="floor-filter"
					@change="update({ floor: $event.target.value, room: '' })">
					<option value="">
						{{ t('chorestrike', 'All floors') }}
					</option>
					<option v-for="floor in floors" :key="floor.id" :value="String(floor.id)">
						{{ floor.name }}
					</option>
				</select>
			</div>
			<div class="filter-group">
				<label for="task-room-filter">{{ t('chorestrike', 'Room') }}</label>
				<select id="task-room-filter"
					:value="modelValue.room"
					class="filter-select"
					data-testid="room-filter"
					@change="update({ room: $event.target.value })">
					<option value="">
						{{ t('chorestrike', 'All rooms') }}
					</option>
					<option v-for="room in floorRooms" :key="room.id" :value="String(room.id)">
						{{ room.name }}
					</option>
				</select>
			</div>
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
						:placeholder="t('chorestrike', 'Search tasks...')"
						@input="update({ search: $event.target.value })">
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import Icon from '../Common/Icon.vue'

// Panel values that count as "no filter" for the active-filter badge.
const PANEL_DEFAULTS = { person: '', floor: '', room: '', status: 'all', priority: 'all', difficulty: 'all', visibility: 'all' }

/**
 * Whether the viewport is phone-sized; the filter panel starts collapsed
 * there (CS-PLAN-084 D-084-02).
 * @return {boolean}
 */
function isPhoneViewport () {
  return typeof window !== 'undefined'
	&& typeof window.matchMedia === 'function'
	&& window.matchMedia('(max-width: 768px)').matches
}

/**
 * TaskManager filter surface (CS-PLAN-084 WP-D, RF-080-16): always-visible
 * scope buttons plus a collapsible panel for person/floor/room/status/
 * priority/difficulty/visibility/search. Stateless apart from the panel
 * toggle — the filter object is a v-model owned by the view.
 */
export default {
	name: 'TaskFilterBar',

	components: { Icon },

	props: {
		/** Filter values: { scope, person, floor, room, status, priority, difficulty, visibility, search }. */
		modelValue: {
			type: Object,
			required: true,
		},

		/** Household members for the person filter. */
		members: {
			type: Array,
			default: () => [],
		},

		/** Disables the person filter while member names are unavailable. */
		membersDisabled: {
			type: Boolean,
			default: false,
		},

		floors: {
			type: Array,
			default: () => [],
		},

		rooms: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:modelValue'],

	data() {
		return { open: !isPhoneViewport() }
	},

	computed: {
		floorRooms() {
			return this.rooms.filter((room) => !this.modelValue.floor || String(room.floorId) === this.modelValue.floor)
		},

		activeCount() {
			const changed = Object.keys(PANEL_DEFAULTS).filter((key) => this.modelValue[key] !== PANEL_DEFAULTS[key]).length
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
					label: t('chorestrike', 'Difficulty'),
					options: [
						{ value: 'all', label: t('chorestrike', 'All Difficulties') },
						{ value: '1', label: t('chorestrike', 'Level 1 (Quick)') },
						{ value: '2', label: t('chorestrike', 'Level 2 (Easy)') },
						{ value: '3', label: t('chorestrike', 'Level 3 (Medium)') },
						{ value: '4', label: t('chorestrike', 'Level 4 (Hard)') },
						{ value: '5', label: t('chorestrike', 'Level 5 (Major)') },
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
			]
		},
	},

	methods: {
		t,

		update(patch) {
			this.$emit('update:modelValue', { ...this.modelValue, ...patch })
		},

		setScope(scope) {
			this.update({ scope, person: '' })
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

.scope-filters button {
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

.scope-filters button[aria-pressed='true'] {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-color: var(--color-primary-element);
	font-weight: 700;
}

.scope-filters .filters-toggle {
	margin-inline-start: auto;
}

.scope-filters button:focus-visible,
.filter-select:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.scope-hint {
	margin-block: 0 1rem;
	color: var(--color-text-maxcontrast);
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
