<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div
		class="room-card"
		:class="cardClasses"
		:data-testid="`room-card-${room.name}`">
		<!-- Room Header -->
		<div class="room-header">
			<div class="room-info">
				<div class="room-title-row">
					<div class="room-icon-shell" :class="statusClass">
						<Icon :name="roomIconName" :size="18" class="room-type-icon" />
					</div>
					<!-- Stretched link (CS-PLAN-084 WP-C): the name is the card's one
					     real link; its ::after covers the card so a click anywhere
					     opens the room, while Quick clean stays its own control. -->
					<h4 class="room-name">
						<RouterLink
							class="room-link"
							:to="{ name: 'room', params: { id: room.id } }"
							:data-testid="`room-link-${room.id}`">
							{{ room.name }}
						</RouterLink>
					</h4>
				</div>
				<div class="room-status">
					<span class="status-badge" :class="statusClass">
						{{ statusText }}
					</span>
				</div>
			</div>
		</div>

		<!-- Cleanliness Bar -->
		<div class="cleanliness-section">
			<div class="cleanliness-header">
				<span class="cleanliness-label">{{ t('chorestrike', 'Cleanliness') }}</span>
				<span class="cleanliness-value" data-testid="cleanliness-value">{{ room.cleanliness }}%</span>
			</div>
			<div class="cleanliness-bar">
				<div
					class="cleanliness-fill"
					:class="cleanlinessClass"
					:style="{ width: `${room.cleanliness}%` }" />
			</div>
		</div>

		<!-- Task Summary -->
		<div class="task-summary">
			<div class="task-item">
				<Icon name="List" :size="16" class="task-icon" />
				<span class="task-count">{{ activeTasks }}</span>
				<span class="task-label">{{ t('chorestrike', 'Active') }}</span>
			</div>
			<div v-if="overdueTasks > 0" class="task-item">
				<Icon name="Warning" :size="16" class="task-icon" />
				<span class="task-count">{{ overdueTasks }}</span>
				<span class="task-label">{{ t('chorestrike', 'Overdue') }}</span>
			</div>
			<div v-if="dueTodayTasks > 0" class="task-item due-today">
				<Icon name="Calendar" :size="16" class="task-icon" />
				<span class="task-count">{{ dueTodayTasks }}</span>
				<span class="task-label">{{ t('chorestrike', 'Due Today') }}</span>
			</div>
		</div>

		<!-- Last Updated -->
		<div class="room-footer">
			<span class="last-updated">
				{{ t('chorestrike', 'Last cleaned: {time}', { time: lastCleanedText }) }}
			</span>
			<HoldButton
				variant="icon"
				icon="Sparkles"
				:icon-size="18"
				:disabled="room.cleanliness >= 90"
				:busy="loading"
				:data-testid="`quick-clean-${room.id}`"
				:aria-label="t('chorestrike', 'Quick clean (+10% cleanliness)')"
				:title="t('chorestrike', 'Quick clean (+10% cleanliness)')"
				@confirm="quickClean" />
		</div>
	</div>
</template>

<script>
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { RouterLink } from 'vue-router'
import { useRoomsStore } from '../../store/rooms.js'
import { useRoomTasksStore } from '../../store/roomTasks.js'
import { useHouseStore } from '../../store/house.js'
import { dueClass, isOverdue } from '../../utils/taskDisplay.js'
import { logger } from '../../utils/logger.js'
import { showErrorWithReference } from '../../utils/errorToast.js'
import Icon from '../Common/Icon.vue'
import HoldButton from '../Common/HoldButton.vue'
import { resolveRoomIcon } from '../../utils/roomIcons.js'

export default {
	name: 'RoomCard',
	components: {
		Icon,
		HoldButton,
		RouterLink,
	},

	props: {
		room: {
			type: Object,
			required: true,
		},
		compact: {
			type: Boolean,
			default: false,
		},
	},

	setup(props) {
		const roomsStore = useRoomsStore()
		const roomTasksStore = useRoomTasksStore()
		const houseStore = useHouseStore()

		const loading = ref(false)

		// Computed properties
		const statusClass = computed(() => {
			const cleanliness = props.room.cleanliness
			if (cleanliness >= 80) return 'excellent'
			if (cleanliness >= 60) return 'good'
			if (cleanliness >= 40) return 'fair'
			if (cleanliness >= 20) return 'poor'
			return 'terrible'
		})

		const statusText = computed(() => {
			const cleanliness = props.room.cleanliness
			if (cleanliness >= 80) return t('chorestrike', 'Excellent')
			if (cleanliness >= 60) return t('chorestrike', 'Good')
			if (cleanliness >= 40) return t('chorestrike', 'Fair')
			if (cleanliness >= 20) return t('chorestrike', 'Poor')
			return t('chorestrike', 'Terrible')
		})

		const cleanlinessClass = computed(() => {
			const cleanliness = props.room.cleanliness
			if (cleanliness >= 80) return 'excellent'
			if (cleanliness >= 60) return 'good'
			if (cleanliness >= 40) return 'fair'
			if (cleanliness >= 20) return 'poor'
			return 'terrible'
		})

		const cardClasses = computed(() => ({
			'needs-cleaning': props.room.cleanliness < 60,
			compact: props.compact,
		}))

		const roomIconName = computed(() => resolveRoomIcon(props.room.icon, props.room.name))

		const lastCleanedText = computed(() => {
			if (!props.room.lastCleaned) return t('chorestrike', 'Never')

			const lastCleaned = new Date(props.room.lastCleaned)
			const now = new Date()
			const diffMs = now - lastCleaned
			const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24))

			if (diffDays === 0) return t('chorestrike', 'Today')
			if (diffDays === 1) return t('chorestrike', 'Yesterday')
			if (diffDays < 7) return t('chorestrike', '{count} days ago', { count: diffDays })
			if (diffDays < 30) {
				return t('chorestrike', '{count} weeks ago', { count: Math.floor(diffDays / 7) })
			}

			return t('chorestrike', '{count} months ago', { count: Math.floor(diffDays / 30) })
		})

		// Task counts (would be fetched from roomTasks store)
		const activeTasks = computed(() => {
			const roomTasks = roomTasksStore.roomTasksByRoom[props.room.id] || []
			return roomTasks.filter(task => task.isActive).length
		})

		// The same missed-work / due rule as the task rows (CS-PLAN-084 D-084-08).
		const overdueTasks = computed(() => {
			const roomTasks = roomTasksStore.roomTasksByRoom[props.room.id] || []
			return roomTasks.filter(isOverdue).length
		})

		const dueTodayTasks = computed(() => {
			const roomTasks = roomTasksStore.roomTasksByRoom[props.room.id] || []
			return roomTasks.filter(task => task.nextDue && dueClass(task, houseStore.timezone) === 'today').length
		})

		// Methods
		const quickClean = async () => {
			if (loading.value) return
			loading.value = true
			try {
				await roomsStore.updateRoomCleanliness(props.room.id, 10)
			} catch (error) {
				showErrorWithReference(t('chorestrike', 'Could not clean the room'), error)
				logger.error('Failed to quick clean room', error, { component: 'RoomCard', action: 'quickClean', roomId: props.room.id })
			} finally {
				loading.value = false
			}
		}

		return {
			t,
			loading,
			statusClass,
			statusText,
			cleanlinessClass,
			cardClasses,
			roomIconName,
			lastCleanedText,
			activeTasks,
			overdueTasks,
			dueTodayTasks,
			quickClean,
		}
	},
}
</script>

<style scoped>
.room-card {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 16px;
  margin-bottom: 12px;
  cursor: pointer;
  transition: all 0.2s ease;
  position: relative;
  overflow: hidden;
}

.room-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.room-card.needs-cleaning {
  border-color: var(--color-element-warning);
  background: var(--color-warning);
}

.room-card.compact {
  padding: 12px;
  margin-bottom: 8px;
}

.room-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 12px;
}

.room-info {
  flex: 1;
}

.room-title-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 4px;
}

.room-icon-shell {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  border-radius: 0.75rem;
  background: var(--color-background-dark);
  border: 1px solid var(--color-border);
  flex-shrink: 0;
}

/* Status tint + matching text color (NC34+ semantic pairs, UX-017). */
.room-icon-shell.excellent {
  color: var(--color-success-text);
  background: var(--color-success);
}

.room-icon-shell.good {
  color: var(--color-primary-element-light-text);
  background: var(--color-primary-element-light);
}

.room-icon-shell.fair {
  color: var(--color-warning-text);
  background: var(--color-warning);
}

.room-icon-shell.poor,
.room-icon-shell.terrible {
  color: var(--color-error-text);
  background: var(--color-error);
}

.room-name {
  font-size: 16px;
  font-weight: 600;
  margin: 0 0 4px 0;
  color: var(--color-main-text);
}

.room-link {
  color: inherit;
  text-decoration: none;
}

.room-link::after {
  content: '';
  position: absolute;
  inset: 0;
  border-radius: inherit;
}

.room-link:focus-visible {
  outline: none;
}

.room-link:focus-visible::after {
  outline: 2px solid var(--color-main-text);
  outline-offset: -2px;
}

/* Quick clean sits above the stretched link (HoldButton's root is positioned). */
.room-footer .hold-button {
  z-index: 1;
}

.room-status {
  display: flex;
  align-items: center;
  gap: 8px;
}

.status-badge {
  font-size: 12px;
  font-weight: 500;
  padding: 2px 8px;
  border-radius: 12px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.status-badge.excellent {
  background: var(--color-success);
  color: var(--color-success-text);
}

.status-badge.good {
  background: var(--color-primary-element-light);
  color: var(--color-primary-element-light-text);
}

.status-badge.fair {
  background: var(--color-warning);
  color: var(--color-warning-text);
}

.status-badge.poor {
  background: var(--color-warning);
  color: var(--color-warning-text);
}

.status-badge.terrible {
  background: var(--color-error);
  color: var(--color-error-text);
}

.room-actions {
  display: flex;
  gap: 8px;
}

.cleanliness-section {
  margin-bottom: 12px;
}

.cleanliness-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 4px;
}

.cleanliness-label {
  font-size: 12px;
  color: var(--color-text-maxcontrast);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.cleanliness-value {
  font-size: 12px;
  font-weight: bold;
  color: var(--color-main-text);
}

.cleanliness-bar {
  height: 6px;
  background: var(--color-background-dark);
  border-radius: 3px;
  overflow: hidden;
}

.cleanliness-fill {
  height: 100%;
  transition: width 0.6s ease, background-color 0.3s ease;
}

.cleanliness-fill.excellent {
  background: var(--color-element-success);
}

.cleanliness-fill.good {
  background: var(--color-primary-element);
}

.cleanliness-fill.fair {
  background: var(--color-element-warning);
}

.cleanliness-fill.poor {
  background: var(--color-element-warning);
}

.cleanliness-fill.terrible {
  background: var(--color-element-error);
}

.task-summary {
  display: flex;
  gap: 12px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}

.task-item {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  color: var(--color-text-maxcontrast);
}

.task-item.overdue {
  color: var(--color-error-text);
  font-weight: 500;
}

.task-item.due-today {
  color: var(--color-warning-text);
  font-weight: 500;
}

.task-icon {
  font-size: 12px;
}

.task-count {
  font-weight: bold;
}

.task-label {
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.room-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 11px;
  color: var(--color-text-maxcontrast);
}

.last-updated {
  font-style: italic;
}

/* Responsive design */
@media (max-width: 768px) {
  .room-card {
    padding: 12px;
  }

  .room-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
  }

  .room-name {
    font-size: 14px;
  }

  .task-summary {
    gap: 8px;
  }

  .room-footer {
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
  }
}
</style>
