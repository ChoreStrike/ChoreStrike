<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="task-group-list" data-testid="task-group-list">
		<EmptyState
			v-if="sections.length === 0"
			icon="List"
			:message="t('chorestrike', 'No tasks yet')" />
		<section v-for="section in sections" :key="section.key" class="group-section">
			<header v-if="section.label" class="section-heading" :data-testid="`task-floor-${section.key}`">
				<h2>{{ section.label }}</h2>
				<span class="group-count">{{ openLabel(section.open) }}</span>
			</header>
			<div v-for="group in section.groups"
				:key="group.key"
				class="group"
				:class="{ open: group.key === openKey }"
				:data-testid="`task-group-${group.key}`">
				<div class="group-heading">
					<button type="button"
						class="group-toggle"
						:aria-expanded="group.key === openKey ? 'true' : 'false'"
						:aria-controls="`task-group-rows-${group.key}`"
						data-testid="task-group-toggle"
						@click="$emit('toggle', group.key)">
						<ExpandChevron :open="group.key === openKey" />
						<span class="group-name" data-testid="task-group-name">{{ group.label }}</span>
						<span v-if="group.overdue > 0" class="group-overdue" data-testid="task-group-overdue">
							{{ t('chorestrike', '{count} overdue', { count: group.overdue }) }}
						</span>
						<span class="group-count" data-testid="task-group-open">{{ openLabel(group.open) }}</span>
						<span v-if="group.room" class="group-room-facts">
							<span :title="t('chorestrike', 'Cleanliness')" data-testid="cleanliness-value">{{ group.room.cleanliness }}%</span>
							<span v-if="group.room.lastCleaned" data-testid="room-last-cleaned">
								{{ t('chorestrike', 'Last cleaned: {date}', { date: dayLabel(group.room.lastCleaned) }) }}
							</span>
						</span>
					</button>
					<NcButton v-if="group.room && canAdd"
						:aria-label="t('chorestrike', 'New task in {room}', { room: group.label })"
						data-testid="room-add-task-button"
						@click="$emit('add', group.room)">
						<template #icon>
							<Icon name="Plus" :size="16" />
						</template>
						{{ t('chorestrike', 'New') }}
					</NcButton>
				</div>
				<!-- A group's rows render when it opens (one open group at a time). -->
				<div v-if="group.key === openKey" :id="`task-group-rows-${group.key}`" class="group-rows">
					<TaskList :roomTasks="group.items" @complete="(...args) => $emit('complete', ...args)" />
				</div>
			</div>
		</section>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import EmptyState from '../Common/EmptyState.vue'
import ExpandChevron from '../Common/ExpandChevron.vue'
import Icon from '../Common/Icon.vue'
import TaskList from './TaskList.vue'
import { useHouseStore } from '../../store/house.js'
import { dateKeyInZone, formatDayKey } from '../../utils/dateKeys.js'

/**
 * The grouped task list (CS-PLAN-090 WP-C): sections (floors, or one
 * unnamed section of people) of collapsible groups with their open and
 * overdue counts. A room group also shows its cleanliness and "Last
 * cleaned" and offers *New*. Presentation only — the view groups the rows
 * (`utils/taskGrouping.js`) and owns which group is open.
 */
export default {
	name: 'TaskGroupList',

	components: { EmptyState, ExpandChevron, Icon, NcButton, TaskList },

	props: {
		/** `[{ key, label, open, groups: [{ key, label, items, open, overdue, room? }] }]`. */
		sections: {
			type: Array,
			required: true,
		},

		/** Key of the one open group, or null. */
		openKey: {
			type: [String, Number],
			default: null,
		},

		/** Whether the viewer may add a task to a room. */
		canAdd: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['toggle', 'add', 'complete'],

	setup() {
		return { houseStore: useHouseStore() }
	},

	methods: {
		t,

		openLabel(count) {
			return t('chorestrike', '{count} open', { count })
		},

		dayLabel(instant) {
			return formatDayKey(dateKeyInZone(instant, this.houseStore.timezone))
		},
	},
}
</script>

<style scoped>
.section-heading {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 1rem;
	padding: 1rem 0.75rem 0.25rem;
}

.section-heading h2 {
	margin: 0;
	font-size: 0.85rem;
	font-weight: bold;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}

.group {
	border-block-end: 1px solid var(--color-border);
}

.group-heading {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding-inline-end: 0.5rem;
}

.group.open > .group-heading {
	background: var(--color-primary-element-light);
}

.group-toggle {
	display: flex;
	flex: 1;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.25rem 0.5rem;
	min-width: 0;
	min-height: 52px;
	margin: 0;
	padding: 0.25rem 0.75rem;
	border: none;
	border-radius: 0;
	background: transparent;
	color: var(--color-main-text);
	font-weight: normal;
	text-align: start;
	cursor: pointer;
}

.group-toggle:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: -2px;
}

.group-name {
	flex: 1;
	min-width: 0;
	font-weight: 600;
	overflow-wrap: anywhere;
}

.group-count,
.group-room-facts {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.group-room-facts {
	display: flex;
	flex-basis: 100%;
	gap: 0.75rem;
	padding-inline-start: 1.75rem;
}

.group-overdue {
	padding: 0.15rem 0.5rem;
	border-radius: var(--border-radius-pill);
	border: 1px solid var(--color-element-error);
	background: var(--color-error);
	color: var(--color-error-text);
	font-size: 0.8rem;
	font-weight: bold;
}

.group-rows {
	padding: 0.75rem 0;
}
</style>
