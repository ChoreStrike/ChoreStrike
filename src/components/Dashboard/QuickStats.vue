<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="quick-stats" :class="{ compact }" data-testid="quick-stats">
		<div class="stats-grid">
			<!-- Total Rooms -->
			<div class="stat-card" data-testid="stat-card">
				<Icon name="Home" :size="32" class="stat-icon" />
				<div class="stat-content">
					<div class="stat-value">
						{{ totalRooms }}
					</div>
					<div class="stat-label">
						Total Rooms
					</div>
				</div>
			</div>

			<!-- Clean Rooms -->
			<div class="stat-card" data-testid="stat-card">
				<Icon name="Sparkles" :size="32" class="stat-icon" />
				<div class="stat-content">
					<div class="stat-value">
						{{ cleanRooms }}
					</div>
					<div class="stat-label">
						Clean Rooms
					</div>
				</div>
			</div>

			<!-- Tasks Due Today -->
			<div class="stat-card" data-testid="stat-card">
				<Icon name="Calendar" :size="32" class="stat-icon" />
				<div class="stat-content">
					<div class="stat-value">
						{{ dueTodayTasks }}
					</div>
					<div class="stat-label">
						Due Today
					</div>
				</div>
			</div>

			<!-- Overdue Tasks -->
			<div v-if="overdueTasks > 0" class="stat-card urgent">
				<Icon name="AlertCircle" :size="32" class="stat-icon" />
				<div class="stat-content">
					<div class="stat-value">
						{{ overdueTasks }}
					</div>
					<div class="stat-label">
						Overdue
					</div>
				</div>
			</div>

			<!-- Total Monsters -->
			<div v-if="totalMonsters > 0" class="stat-card">
				<Emoji name="monster" :size="32" class="stat-icon" />
				<div class="stat-content">
					<div class="stat-value">
						{{ totalMonsters }}
					</div>
					<div class="stat-label">
						Monsters
					</div>
				</div>
			</div>

			<!-- Average Cleanliness -->
			<div class="stat-card">
				<Icon name="BarChart3" :size="32" class="stat-icon" />
				<div class="stat-content">
					<div class="stat-value">
						{{ averageCleanliness }}%
					</div>
					<div class="stat-label">
						Avg Cleanliness
					</div>
				</div>
			</div>
		</div>

		<!-- Action Buttons -->
		<div v-if="showActions" class="stats-actions">
			<button
				class="action-button"
				:disabled="loading"
				@click="refreshStats">
				<Icon name="RefreshCw" :size="16" class="icon" />
				Refresh
			</button>

			<button
				class="action-button primary"
				@click="viewAllTasks">
				<Icon name="List" :size="16" class="icon" />
				View All Tasks
			</button>
		</div>
	</div>
</template>

<script>
import { computed } from 'vue'
import { useRoomsStore } from '../../store/rooms.js'
import { useRoomTasksStore } from '../../store/roomTasks.js'
import { useRouter } from 'vue-router'

export default {
	name: 'QuickStats',

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

	setup(props) {
		const roomsStore = useRoomsStore()
		const roomTasksStore = useRoomTasksStore()
		const router = useRouter()

		// Computed properties
		const totalRooms = computed(() => roomsStore.totalRooms)
		const cleanRooms = computed(() => roomsStore.cleanRooms)
		const dirtyRooms = computed(() => roomsStore.dirtyRooms)
		const averageCleanliness = computed(() => roomsStore.averageCleanliness)
		const totalMonsters = computed(() => roomsStore.totalMonsters)

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
				console.error('Failed to refresh stats:', error)
			}
		}

		const viewAllTasks = () => {
			router.push('/tasks')
		}

		return {
			totalRooms,
			cleanRooms,
			dirtyRooms,
			averageCleanliness,
			totalMonsters,
			dueTodayTasks,
			overdueTasks,
			loading,
			refreshStats,
			viewAllTasks,
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
}

.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.stat-card.urgent {
  border-color: #F44336;
  background: rgba(244, 67, 54, 0.1);
  animation: pulse 2s infinite;
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.8; }
}

.stat-icon {
  font-size: 24px;
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 32px;
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
  padding: 8px 16px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-main-background);
  color: var(--color-main-text);
  cursor: pointer;
  font-size: 14px;
  transition: all 0.2s ease;
}

.action-button:hover:not(:disabled) {
  background: var(--color-background-hover);
  border-color: var(--color-primary);
}

.action-button.primary {
  background: var(--color-primary);
  color: var(--color-primary-text);
  border-color: var(--color-primary);
}

.action-button.primary:hover:not(:disabled) {
  background: var(--color-primary-hover);
  border-color: var(--color-primary-hover);
}

.action-button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.action-button .icon {
  font-size: 14px;
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
