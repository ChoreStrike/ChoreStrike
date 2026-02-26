<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div
		class="room-card"
		:class="cardClasses"
		@click="handleCardClick">
		<!-- Room Header -->
		<div class="room-header">
			<div class="room-info">
				<h4 class="room-name">
					{{ room.name }}
				</h4>
				<div class="room-status">
					<span class="status-badge" :class="statusClass">
						{{ statusText }}
					</span>
				</div>
			</div>
			<div class="room-actions">
				<button
					class="monster-toggle"
					:class="{ active: showMonsters }"
					title="Toggle monsters"
					@click.stop="toggleMonsters">
					<Emoji name="monster" :size="20" class="icon" />
					<span class="count">{{ room.monsterCount }}</span>
				</button>
			</div>
		</div>

		<!-- Cleanliness Bar -->
		<div class="cleanliness-section">
			<div class="cleanliness-header">
				<span class="cleanliness-label">Cleanliness</span>
				<span class="cleanliness-value">{{ room.cleanliness }}%</span>
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
				<span class="task-label">Active</span>
			</div>
			<div v-if="overdueTasks > 0" class="task-item">
				<Icon name="Warning" :size="16" class="task-icon" />
				<span class="task-count">{{ overdueTasks }}</span>
				<span class="task-label">Overdue</span>
			</div>
			<div v-if="dueTodayTasks > 0" class="task-item due-today">
				<Icon name="Calendar" :size="16" class="task-icon" />
				<span class="task-count">{{ dueTodayTasks }}</span>
				<span class="task-label">Due Today</span>
			</div>
		</div>

		<!-- Monsters Display -->
		<div v-if="showMonsters && room.monsterCount > 0" class="monsters-display">
			<div class="monsters-container">
				<div
					v-for="(monster, index) in monsters"
					:key="index"
					class="monster"
					:class="monster.type"
					:style="monster.position">
					<Emoji :name="getMonsterEmoji(monster.type)" class="monster-emoji" />
				</div>
			</div>
		</div>

		<!-- Last Updated -->
		<div class="room-footer">
			<span class="last-updated">
				Last cleaned: {{ lastCleanedText }}
			</span>
			<button
				class="quick-clean-button"
				:disabled="loading || room.cleanliness >= 90"
				title="Quick clean (+10% cleanliness)"
				@click.stop="quickClean">
				<Icon name="Sparkles" class="icon" />
			</button>
		</div>
	</div>
</template>

<script>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useRoomsStore } from '../../store/rooms.js'
import { useRoomTasksStore } from '../../store/roomTasks.js'
import { useGameStore } from '../../store/game.js'
import { Icon, Emoji } from '../Common'

export default {
	name: 'RoomCard',

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
		const router = useRouter()
		const roomsStore = useRoomsStore()
		const roomTasksStore = useRoomTasksStore()
		const gameStore = useGameStore()

		const showMonsters = ref(false)
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
			if (cleanliness >= 80) return 'Excellent'
			if (cleanliness >= 60) return 'Good'
			if (cleanliness >= 40) return 'Fair'
			if (cleanliness >= 20) return 'Poor'
			return 'Terrible'
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
			'has-monsters': props.room.monsterCount > 0,
			compact: props.compact,
		}))

		const lastCleanedText = computed(() => {
			if (!props.room.lastCleaned) return 'Never'

			const lastCleaned = new Date(props.room.lastCleaned)
			const now = new Date()
			const diffMs = now - lastCleaned
			const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24))

			if (diffDays === 0) return 'Today'
			if (diffDays === 1) return 'Yesterday'
			if (diffDays < 7) return `${diffDays} days ago`
			if (diffDays < 30) return `${Math.floor(diffDays / 7)} weeks ago`
			return `${Math.floor(diffDays / 30)} months ago`
		})

		// Task counts (would be fetched from roomTasks store)
		const activeTasks = computed(() => {
			const roomTasks = roomTasksStore.roomTasksByRoom[props.room.id] || []
			return roomTasks.filter(task => task.isActive).length
		})

		const overdueTasks = computed(() => {
			const roomTasks = roomTasksStore.roomTasksByRoom[props.room.id] || []
			return roomTasks.filter(task =>
				task.isActive && new Date(task.nextDue) < new Date(),
			).length
		})

		const dueTodayTasks = computed(() => {
			const roomTasks = roomTasksStore.roomTasksByRoom[props.room.id] || []
			const today = new Date()
			today.setHours(0, 0, 0, 0)
			const tomorrow = new Date(today)
			tomorrow.setDate(tomorrow.getDate() + 1)

			return roomTasks.filter(task => {
				if (!task.isActive) return false
				const dueDate = new Date(task.nextDue)
				return dueDate >= today && dueDate < tomorrow
			}).length
		})

		// Monsters
		const monsters = computed(() => {
			return gameStore.currentMonsters(props.room.id) || []
		})

		// Methods
		const handleCardClick = () => {
			router.push(`/rooms/${props.room.id}`)
		}

		const toggleMonsters = () => {
			showMonsters.value = !showMonsters.value

			// Generate monsters if not already generated
			if (showMonsters.value && monsters.value.length === 0) {
				gameStore.updateMonstersForCleanliness(props.room.id, props.room.cleanliness)
			}
		}

		const getMonsterEmoji = (type) => {
			const emojiMap = {
				'dust-bunny': '🐰',
				'slime-blob': '🟢',
				'pixel-spider': '🕷️',
				'dust-demon': '👹',
			}
			return emojiMap[type] || '👾'
		}

		const quickClean = async () => {
			loading.value = true
			try {
				await roomsStore.updateRoomCleanliness(props.room.id, 10)

				// Update monsters if visible
				if (showMonsters.value) {
					gameStore.updateMonstersForCleanliness(props.room.id, Math.min(100, props.room.cleanliness + 10))
				}
			} catch (error) {
				console.error('Failed to quick clean room:', error)
			} finally {
				loading.value = false
			}
		}

		return {
			showMonsters,
			loading,
			statusClass,
			statusText,
			cleanlinessClass,
			cardClasses,
			lastCleanedText,
			activeTasks,
			overdueTasks,
			dueTodayTasks,
			monsters,
			handleCardClick,
			toggleMonsters,
			getMonsterEmoji,
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
  border-color: #FF9800;
  background: rgba(255, 152, 0, 0.05);
}

.room-card.has-monsters {
  border-color: #9C27B0;
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

.room-name {
  font-size: 16px;
  font-weight: 600;
  margin: 0 0 4px 0;
  color: var(--color-main-text);
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
  background: #4CAF50;
  color: white;
}

.status-badge.good {
  background: #2196F3;
  color: white;
}

.status-badge.fair {
  background: #FF9800;
  color: white;
}

.status-badge.poor {
  background: #FF5722;
  color: white;
}

.status-badge.terrible {
  background: #F44336;
  color: white;
}

.room-actions {
  display: flex;
  gap: 8px;
}

.monster-toggle {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 4px 8px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-main-background);
  cursor: pointer;
  transition: all 0.2s ease;
  font-size: 12px;
}

.monster-toggle:hover {
  background: var(--color-background-hover);
  border-color: #9C27B0;
}

.monster-toggle.active {
  background: #9C27B0;
  color: white;
  border-color: #9C27B0;
}

.monster-toggle .icon {
  font-size: 14px;
}

.monster-toggle .count {
  font-weight: bold;
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
  background: #4CAF50;
}

.cleanliness-fill.good {
  background: #2196F3;
}

.cleanliness-fill.fair {
  background: #FF9800;
}

.cleanliness-fill.poor {
  background: #FF5722;
}

.cleanliness-fill.terrible {
  background: #F44336;
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
  color: #F44336;
  font-weight: 500;
}

.task-item.due-today {
  color: #FF9800;
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

.monsters-display {
  margin-bottom: 12px;
  min-height: 60px;
  background: rgba(156, 39, 176, 0.1);
  border-radius: 8px;
  border: 1px solid rgba(156, 39, 176, 0.3);
  position: relative;
  overflow: hidden;
}

.monsters-container {
  position: relative;
  height: 60px;
}

.monster {
  position: absolute;
  font-size: 20px;
  transition: all 0.3s ease;
  cursor: pointer;
  animation: float 3s ease-in-out infinite;
}

.monster:hover {
  transform: scale(1.2);
}

.monster.dust-bunny {
  animation-delay: 0s;
}

.monster.slime-blob {
  animation-delay: 0.5s;
}

.monster.pixel-spider {
  animation-delay: 1s;
}

.monster.dust-demon {
  animation-delay: 1.5s;
}

@keyframes float {
  0%, 100% { transform: translateY(0px); }
  50% { transform: translateY(-10px); }
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

.quick-clean-button {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-main-background);
  cursor: pointer;
  transition: all 0.2s ease;
}

.quick-clean-button:hover:not(:disabled) {
  background: var(--color-primary);
  border-color: var(--color-primary);
  color: white;
}

.quick-clean-button:disabled {
  opacity: 0.3;
  cursor: not-allowed;
}

.quick-clean-button .icon {
  font-size: 14px;
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
