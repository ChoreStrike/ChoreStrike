<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="quick-stats" :class="{ compact }" data-testid="quick-stats">
		<div class="stats-grid">
			<!-- Total Rooms -->
			<div class="stat-card total-rooms" data-testid="stat-card">
				<div class="stat-icon-shell total-rooms-icon">
					<Icon name="Home" :size="24" class="stat-icon" />
				</div>
				<div class="stat-content">
					<div class="stat-value">
						{{ totalRooms }}
					</div>
					<div class="stat-label">
						{{ t('chorestrike', 'Total Rooms') }}
					</div>
				</div>
			</div>

			<!-- Clean Rooms -->
			<div class="stat-card clean-rooms" data-testid="stat-card">
				<div class="stat-icon-shell clean-rooms-icon">
					<Icon name="Sparkles" :size="24" class="stat-icon" />
				</div>
				<div class="stat-content">
					<div class="stat-value">
						{{ cleanRooms }}
					</div>
					<div class="stat-label">
						{{ t('chorestrike', 'Clean Rooms') }}
					</div>
				</div>
			</div>

			<!-- Tasks Due Today -->
			<RouterLink :to="{ name: 'today' }" class="stat-card due-today" data-testid="stat-card">
				<div class="stat-icon-shell due-today-icon">
					<Icon name="Calendar" :size="24" class="stat-icon" />
				</div>
				<div class="stat-content">
					<div class="stat-value">
						{{ dueTodayTasks }}
					</div>
					<div class="stat-label">
						{{ t('chorestrike', 'Due Today') }}
					</div>
				</div>
			</RouterLink>

			<!-- Overdue Tasks -->
			<RouterLink v-if="overdueTasks > 0"
				:to="{ name: 'today', query: { scope: 'all' } }"
				class="stat-card urgent"
				data-testid="overdue-stat-card">
				<div class="stat-icon-shell urgent-icon">
					<Icon name="AlertCircle" :size="24" class="stat-icon" />
				</div>
				<div class="stat-content">
					<div class="stat-value">
						{{ overdueTasks }}
					</div>
					<div class="stat-label">
						{{ t('chorestrike', 'Overdue') }}
					</div>
				</div>
			</RouterLink>

			<!-- Average Cleanliness -->
			<div class="stat-card average-cleanliness">
				<div class="stat-icon-shell average-cleanliness-icon">
					<Icon name="BarChart3" :size="24" class="stat-icon" />
				</div>
				<div class="stat-content">
					<div class="stat-value">
						{{ averageCleanliness }}%
					</div>
					<div class="stat-label">
						{{ t('chorestrike', 'Avg Cleanliness') }}
					</div>
				</div>
			</div>
		</div>

		<!-- Action Buttons -->
		<div v-if="showActions" class="stats-actions">
			<button
				class="action-button filter-chip"
				data-testid="private-filter-toggle"
				@click="togglePrivateFilter">
				<Icon name="Lock" :size="16" class="icon" />
				{{ t('chorestrike', 'My Private Tasks') }}
			</button>

			<button
				class="action-button refresh-action"
				data-testid="refresh-dashboard-stats-button"
				:disabled="loading"
				@click="refreshStats">
				<Icon name="RefreshCw" :size="18" class="icon" />
				{{ t('chorestrike', 'Refresh') }}
			</button>

			<button
				class="action-button primary tasks-action"
				data-testid="view-all-tasks-button"
				@click="viewAllTasks">
				<Icon name="List" :size="18" class="icon" />
				{{ t('chorestrike', 'View All Tasks') }}
			</button>
		</div>
	</div>
</template>

<script>
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { RouterLink, useRouter } from 'vue-router'
import { useRoomsStore } from '../../store/rooms.js'
import { useRoomTasksStore } from '../../store/roomTasks.js'

import { logger } from '../../utils/logger.js'
import Icon from '../Common/Icon.vue'

export default {
	name: 'QuickStats',

	components: {
		Icon,
		RouterLink,
	},

	props: {
		showActions: {
			type: Boolean,
			default: true,
		},
		compact: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['toggle-private-filter'],

	setup(props, { emit }) {
		const roomsStore = useRoomsStore()
		const roomTasksStore = useRoomTasksStore()
		const router = useRouter()

		// Computed properties
		const totalRooms = computed(() => roomsStore.totalRooms)
		const cleanRooms = computed(() => roomsStore.cleanRooms)
		const dirtyRooms = computed(() => roomsStore.dirtyRooms)
		const averageCleanliness = computed(() => roomsStore.averageCleanliness)

		const dueTodayTasks = computed(() => roomTasksStore.totalDueTodayTasks)
		const overdueTasks = computed(() => roomTasksStore.totalOverdueTasks)

		const loading = computed(() => roomsStore.loading || roomTasksStore.loading)

		// Methods
		const refreshStats = async () => {
			try {
				await Promise.all([
					roomsStore.fetchFloorsWithRooms(),
					roomTasksStore.fetchDueTodayTasks(),
					roomTasksStore.fetchOverdueTasks(),
				])
			} catch (error) {
				logger.error('Failed to refresh stats', error, { component: 'QuickStats', action: 'refreshStats' })
			}
		}

		const viewAllTasks = () => {
			router.push('/tasks')
		}

		/**
		 * Request the private-task scope. The Dashboard binds this event and
		 * routes to the task list pre-filtered to the current user's private
		 * tasks (CS-PLAN-041) — QuickStats itself renders no task list.
		 */
		const togglePrivateFilter = () => {
			emit('toggle-private-filter', true)
		}

		return {
			t,
			totalRooms,
			cleanRooms,
			dirtyRooms,
			averageCleanliness,
			dueTodayTasks,
			overdueTasks,
			loading,
			refreshStats,
			viewAllTasks,
			togglePrivateFilter,
		}
	},
}
</script>

<style scoped>
.quick-stats {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 16px;
  margin-bottom: 16px;
}

.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}

.stat-card {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px;
  background: var(--color-background-dark);
  border-radius: 8px;
  border: 1px solid var(--color-border);
  transition: all 0.2s ease;
  text-decoration: none;
  color: inherit;
}

.stat-icon-shell {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 2.5rem;
	height: 2.5rem;
	border-radius: 0.9rem;
	flex-shrink: 0;
	border: 1px solid var(--color-border);
}

.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.stat-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  color: currentColor;
}

/* Status tint + matching text color in both themes (NC34+ pairs, UX-017). */
.total-rooms-icon {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.clean-rooms-icon {
	background: var(--color-success);
	color: var(--color-success-text);
}

.due-today-icon {
	background: var(--color-warning);
	color: var(--color-warning-text);
}

.urgent-icon {
	background: var(--color-error);
	color: var(--color-error-text);
}

.average-cleanliness-icon {
	background: var(--color-info);
	color: var(--color-info-text);
}

.stat-content {
  flex: 1;
}

.stat-value {
  font-size: 20px;
  font-weight: bold;
  color: var(--color-main-text);
  line-height: 1.2;
}

.stat-label {
  font-size: 12px;
  color: var(--color-text-maxcontrast);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.stats-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}

.action-button {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 10px 18px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-main-background);
  color: var(--color-main-text);
  cursor: pointer;
  font-size: 14px;
  font-weight: 600;
  transition: all 0.2s ease;
  box-shadow: 0 6px 18px rgba(0, 0, 0, 0.12);
}

.action-button:hover:not(:disabled) {
  background: var(--color-background-hover);
  border-color: var(--color-primary-element);
}

.action-button.primary {
  background: var(--color-primary);
  color: var(--color-primary-text);
  border-color: var(--color-primary-element);
}

.action-button.primary:hover:not(:disabled) {
  background: var(--color-primary-hover);
  border-color: var(--color-primary-hover);
}

.refresh-action {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.tasks-action {
	box-shadow: 0 10px 24px rgba(59, 130, 246, 0.22);
}

.action-button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.filter-chip.active {
	background: var(--color-primary);
	color: var(--color-primary-text);
	border-color: var(--color-primary-element);
}

.action-button .icon {
  font-size: 16px;
}

/* Compact mode */
.quick-stats.compact {
  padding: 12px;
}

.quick-stats.compact .stats-grid {
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
  gap: 8px;
  margin-bottom: 12px;
}

.quick-stats.compact .stat-card {
  padding: 8px;
  gap: 8px;
}

.quick-stats.compact .stat-icon {
  font-size: 20px;
  min-width: 24px;
}

.quick-stats.compact .stat-value {
  font-size: 18px;
}

.quick-stats.compact .stat-label {
  font-size: 11px;
}

/* Responsive design */
@media (max-width: 768px) {
  .quick-stats {
    padding: 12px;
  }

  .stats-grid {
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
  }

  .stat-card {
    padding: 10px;
    gap: 10px;
  }

  .stat-icon {
    font-size: 20px;
    min-width: 28px;
  }

  .stat-value {
    font-size: 18px;
  }

  .stats-actions {
    flex-direction: column;
  }

  .action-button {
    justify-content: center;
  }
}

@media (max-width: 480px) {
  .stats-grid {
    grid-template-columns: 1fr;
  }
}
</style>
