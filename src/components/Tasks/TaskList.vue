<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="task-list" data-testid="room-task-list">
		<!-- Skeleton Loading State -->
		<div v-if="loading" class="skeleton-loading">
			<div v-for="i in 5" :key="i" class="skeleton-task-item">
				<div class="skeleton-task-status" />
				<div class="skeleton-task-content">
					<div class="skeleton-task-header">
						<div class="skeleton-task-name" />
						<div class="skeleton-priority" />
					</div>
					<div class="skeleton-task-meta">
						<div class="skeleton-meta-item" />
						<div class="skeleton-meta-item" />
						<div class="skeleton-meta-item" />
					</div>
				</div>
			</div>
		</div>

		<EmptyState
			v-else-if="!roomTasks || roomTasks.length === 0"
			icon="List"
			:message="t('chorestrike', 'No tasks yet')"
			:hint="t('chorestrike', 'Add your first task to get started')" />

		<div v-else class="task-items">
			<template v-for="(item, index) in sortedTasks" :key="item.roomTask.id">
				<h3 v-if="headingAt(index)" class="task-section-heading" data-testid="task-section-heading">
					{{ headingAt(index) }}
				</h3>
				<TaskRow :item="item" variant="list" @complete="(row, split) => $emit('complete', row.roomTask.id, split)" />
			</template>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'
import EmptyState from '../Common/EmptyState.vue'
import TaskRow from './TaskRow.vue'
import { compareTaskItems } from '../../utils/taskDisplay.js'

/**
 * A list of task rows (CS-PLAN-067 §3.4, CS-PLAN-091 WP-B6): sorted, with
 * a heading where the section of a row changes. Each row is the one task
 * row; it opens in place and edits through the one form. Assignees change
 * in the form only, so the list keeps no drafts, selection or claim.
 */
export default {
	name: 'TaskList',

	components: {
		EmptyState,
		TaskRow,
	},

	props: {
		roomTasks: {
			type: Array,
			default: () => [],
		},

		loading: {
			type: Boolean,
			default: false,
		},

		/** Row order; the list by due date keeps its due classes together. */
		compare: {
			type: Function,
			default: compareTaskItems,
		},

		/** Heading of the section a row belongs to; shown where it changes. */
		sectionLabel: {
			type: Function,
			default: null,
		},
	},

	emits: ['complete'],

	setup(props) {
		const sortedTasks = computed(() => [...(props.roomTasks || [])].sort(props.compare))

		// One label per row and pass; the template asks for each heading twice.
		const sectionLabels = computed(() => (props.sectionLabel ? sortedTasks.value.map(props.sectionLabel) : []))

		const headingAt = (index) => {
			const labels = sectionLabels.value
			if (labels.length === 0) {
				return ''
			}
			return index === 0 || labels[index] !== labels[index - 1] ? labels[index] : ''
		}

		return { sortedTasks, headingAt, t }
	},
}
</script>

<style scoped>
.task-list {
	width: 100%;
}

.task-items {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.task-section-heading {
	margin: 0.5rem 0 0;
	font-size: 0.85rem;
	font-weight: bold;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}

/* Skeleton Loading Styles */
.skeleton-loading {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.skeleton-task-item {
	display: flex;
	gap: 1rem;
	padding: 1rem;
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
}

.skeleton-task-status {
	width: 24px;
	height: 24px;
	border-radius: 50%;
	background: linear-gradient(90deg, var(--color-background-dark) 25%, var(--color-main-background) 50%, var(--color-background-dark) 75%);
	background-size: 200% 100%;
	animation: skeleton-loading 1.5s infinite;
}

.skeleton-task-content {
	flex: 1;
}

.skeleton-task-header {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-bottom: 0.5rem;
}

.skeleton-task-name {
	width: 150px;
	height: 16px;
	border-radius: var(--border-radius);
	background: linear-gradient(90deg, var(--color-background-dark) 25%, var(--color-main-background) 50%, var(--color-background-dark) 75%);
	background-size: 200% 100%;
	animation: skeleton-loading 1.5s infinite;
}

.skeleton-priority {
	width: 60px;
	height: 20px;
	border-radius: var(--border-radius-pill);
	background: linear-gradient(90deg, var(--color-background-dark) 25%, var(--color-main-background) 50%, var(--color-background-dark) 75%);
	background-size: 200% 100%;
	animation: skeleton-loading 1.5s infinite;
}

.skeleton-task-meta {
	display: flex;
	gap: 0.75rem;
}

.skeleton-meta-item {
	width: 50px;
	height: 12px;
	border-radius: var(--border-radius);
	background: linear-gradient(90deg, var(--color-background-dark) 25%, var(--color-main-background) 50%, var(--color-background-dark) 75%);
	background-size: 200% 100%;
	animation: skeleton-loading 1.5s infinite;
}

@keyframes skeleton-loading {
	0% {
		background-position: 200% 0;
	}
	100% {
		background-position: -200% 0;
	}
}
</style>
