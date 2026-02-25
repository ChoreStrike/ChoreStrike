<template>
	<div class="task-list">
		<div v-if="loading" class="loading-state">
			<div class="loading-spinner" />
			<p>{{ t('chorewars', 'Loading tasks...') }}</p>
		</div>

		<div v-else-if="roomTasks.length === 0" class="empty-state">
			<div class="empty-icon">
				📋
			</div>
			<p>{{ t('chorewars', 'No tasks yet') }}</p>
			<p class="empty-hint">
				{{ t('chorewars', 'Add your first task to get started') }}
			</p>
		</div>

		<div v-else class="task-items">
			<div
				v-for="item in sortedTasks"
				:key="item.roomTask.id"
				class="task-item"
				:class="{ overdue: isOverdue(item.roomTask), completed: !item.roomTask.isActive }">
				<div class="task-main">
					<div class="task-status">
						<button
							v-if="item.roomTask.isActive"
							class="complete-button"
							title="Mark as complete"
							@click="$emit('complete', item.roomTask.id)">
							✓
						</button>
						<span v-else class="completed-check">✓</span>
					</div>

					<div class="task-content">
						<div class="task-header">
							<h4 class="task-name">
								{{ item.task.name }}
							</h4>
							<span class="priority-badge" :class="priorityClass(item.task.priority)">
								{{ priorityLabel(item.task.priority) }}
							</span>
						</div>

						<p v-if="item.task.description" class="task-description">
							{{ item.task.description }}
						</p>

						<div class="task-meta">
							<span class="meta-item energy">
								⚡ {{ item.task.energyCost }}
							</span>
							<span class="meta-item time">
								⏱️ {{ item.task.estimatedMinutes }}m
							</span>
							<span class="meta-item frequency">
								🔄 {{ frequencyLabel(item.task) }}
							</span>
							<span v-if="item.roomTask.assignedToUserId" class="meta-item assigned">
								👤 {{ t('chorewars', 'Assigned') }}
							</span>
						</div>

						<div class="task-due" :class="dueClass(item.roomTask)">
							{{ dueText(item.roomTask) }}
						</div>
					</div>

					<div class="task-actions">
						<button
							v-if="item.roomTask.isActive"
							class="action-btn"
							title="Edit"
							@click="$emit('edit', item.roomTask)">
							✏️
						</button>
						<button
							class="action-btn delete"
							title="Remove"
							@click="$emit('delete', item.roomTask.id)">
							🗑️
						</button>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'TaskList',

	props: {
		roomTasks: {
			type: Array,
			default: () => [],
		},
		loading: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['complete', 'edit', 'delete'],

	setup(props) {
		const sortedTasks = computed(() => {
			return [...props.roomTasks].sort((a, b) => {
				// Sort by due date
				const dateA = new Date(a.roomTask.nextDue)
				const dateB = new Date(b.roomTask.nextDue)
				if (dateA !== dateB) return dateA - dateB
				// Then by priority (high first)
				return b.task.priority - a.task.priority
			})
		})

		const isOverdue = (roomTask) => {
			if (!roomTask.isActive) return false
			return new Date(roomTask.nextDue) < new Date()
		}

		const priorityClass = (priority) => {
			return { 1: 'low', 2: 'medium', 3: 'high' }[priority] || 'medium'
		}

		const priorityLabel = (priority) => {
			return { 1: t('chorewars', 'Low'), 2: t('chorewars', 'Medium'), 3: t('chorewars', 'High') }[priority]
		}

		const frequencyLabel = (task) => {
			const labels = {
				daily: t('chorewars', 'Daily'),
				weekly: task.frequencyValue === 1 ? t('chorewars', 'Weekly') : t('chorewars', 'Every {n} weeks', { n: task.frequencyValue }),
				monthly: task.frequencyValue === 1 ? t('chorewars', 'Monthly') : t('chorewars', 'Every {n} months', { n: task.frequencyValue }),
				custom: t('chorewars', 'Custom'),
			}
			return labels[task.frequencyType] || task.frequencyType
		}

		const dueClass = (roomTask) => {
			if (!roomTask.isActive) return 'completed'
			const due = new Date(roomTask.nextDue)
			const now = new Date()
			if (due < now) return 'overdue'
			const today = new Date()
			today.setHours(0, 0, 0, 0)
			const tomorrow = new Date(today)
			tomorrow.setDate(tomorrow.getDate() + 1)
			if (due >= today && due < tomorrow) return 'today'
			return 'upcoming'
		}

		const dueText = (roomTask) => {
			if (!roomTask.isActive) return t('chorewars', 'Completed')
			const due = new Date(roomTask.nextDue)
			const now = new Date()
			const diffDays = Math.ceil((due - now) / (1000 * 60 * 60 * 24))
			if (diffDays < 0) return t('chorewars', 'Overdue by {days} days', { days: Math.abs(diffDays) })
			if (diffDays === 0) return t('chorewars', 'Due today')
			if (diffDays === 1) return t('chorewars', 'Due tomorrow')
			return t('chorewars', 'Due in {days} days', { days: diffDays })
		}

		return {
			sortedTasks,
			isOverdue,
			priorityClass,
			priorityLabel,
			frequencyLabel,
			dueClass,
			dueText,
			t,
		}
	},
}
</script>

<style scoped>
.task-list {
	width: 100%;
}

.loading-state {
	text-align: center;
	padding: 2rem;
	color: var(--color-text-maxcontrast);
}

.loading-spinner {
	width: 32px;
	height: 32px;
	border: 3px solid var(--color-border);
	border-top: 3px solid var(--color-primary);
	border-radius: 50%;
	animation: spin 1s linear infinite;
	margin: 0 auto 0.5rem;
}

@keyframes spin {
	0% { transform: rotate(0deg); }
	100% { transform: rotate(360deg); }
}

.empty-state {
	text-align: center;
	padding: 3rem 2rem;
	color: var(--color-text-maxcontrast);
}

.empty-icon {
	font-size: 48px;
	margin-bottom: 0.5rem;
}

.empty-hint {
	font-size: 14px;
	opacity: 0.7;
}

.task-items {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.task-item {
	background: var(--color-background-hover);
	border-radius: 8px;
	padding: 1rem;
	border: 2px solid transparent;
	transition: all 0.2s ease;
}

.task-item:hover {
	border-color: var(--color-border);
}

.task-item.overdue {
	border-color: #e74c3c;
	background: #fdf2f2;
}

.task-item.completed {
	opacity: 0.6;
	background: var(--color-background-dark);
}

.task-main {
	display: flex;
	gap: 1rem;
	align-items: flex-start;
}

.task-status {
	flex-shrink: 0;
}

.complete-button {
	width: 28px;
	height: 28px;
	border-radius: 50%;
	border: 2px solid var(--color-primary);
	background: transparent;
	color: var(--color-primary);
	cursor: pointer;
	font-size: 14px;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: all 0.2s ease;
}

.complete-button:hover {
	background: var(--color-primary);
	color: white;
}

.completed-check {
	width: 28px;
	height: 28px;
	border-radius: 50%;
	background: #2ecc71;
	color: white;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 14px;
}

.task-content {
	flex: 1;
	min-width: 0;
}

.task-header {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-bottom: 0.25rem;
}

.task-name {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
	color: var(--color-main-text);
}

.priority-badge {
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 500;
	text-transform: uppercase;
}

.priority-badge.low {
	background: #d5f4e6;
	color: #27ae60;
}

.priority-badge.medium {
	background: #fef9e7;
	color: #f39c12;
}

.priority-badge.high {
	background: #fdedec;
	color: #e74c3c;
}

.task-description {
	margin: 0.25rem 0;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	line-height: 1.4;
}

.task-meta {
	display: flex;
	flex-wrap: wrap;
	gap: 0.75rem;
	margin-top: 0.5rem;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.task-due {
	margin-top: 0.5rem;
	font-size: 13px;
	font-weight: 500;
}

.task-due.overdue {
	color: #e74c3c;
}

.task-due.today {
	color: #f39c12;
}

.task-due.completed {
	color: #27ae60;
}

.task-actions {
	display: flex;
	gap: 0.25rem;
	flex-shrink: 0;
}

.action-btn {
	padding: 0.25rem;
	background: transparent;
	border: none;
	cursor: pointer;
	opacity: 0.6;
	transition: opacity 0.2s ease;
}

.action-btn:hover {
	opacity: 1;
}

@media (max-width: 600px) {
	.task-main {
		flex-wrap: wrap;
	}

	.task-actions {
		width: 100%;
		justify-content: flex-end;
	}
}
</style>
