<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="floor-list">
		<div class="floor-header">
			<h3 class="floor-title">
				<Icon name="Building" :size="20" class="icon" />
				{{ t('chorestrike', 'Floors & Rooms') }}
			</h3>
			<div class="floor-actions">
				<button
					class="refresh-button"
					data-testid="refresh-floors-button"
					:disabled="loading"
					:title="t('chorestrike', 'Refresh floors')"
					@click="refreshFloors">
					<Icon name="RefreshCw" :size="16" class="button-icon" />
					<span class="button-label">{{ t('chorestrike', 'Refresh') }}</span>
				</button>
			</div>
		</div>

		<!-- Loading State -->
		<div v-if="loading && floors.length === 0" class="loading-state">
			<div class="loading-spinner" />
			<p>{{ t('chorestrike', 'Loading floors...') }}</p>
		</div>

		<!-- Empty State -->
		<div v-else-if="floors.length === 0" class="empty-state">
			<div class="empty-icon">
				<Icon name="Home" :size="48" />
			</div>
			<h4>{{ t('chorestrike', 'No floors yet') }}</h4>
			<p>{{ t('chorestrike', 'Create your first floor to get started with organizing your home.') }}</p>
		</div>

		<!-- Floors List -->
		<div v-else class="floors-container">
			<div
				v-for="floor in sortedFloors"
				:key="floor.id"
				:data-testid="`floor-${floor.name}`"
				class="floor-section">
				<!-- Floor Header -->
				<div class="floor-section-header">
					<div class="floor-info">
						<div class="floor-name-row">
							<div class="floor-icon-shell" :class="getFloorIconClass(floor)">
								<Icon :name="getFloorIconName(floor)" :size="18" class="floor-icon" />
							</div>
							<h4 class="floor-name">
								{{ floor.name }}
							</h4>
						</div>
						<div class="floor-stats">
							<span class="room-count">{{ t('chorestrike', '{count} rooms', { count: getRoomCount(floor.id) }) }}</span>
							<span class="cleanliness-badge" :class="getFloorCleanlinessClass(floor.id)">
								{{ t('chorestrike', '{percent}% clean', { percent: getFloorCleanliness(floor.id) }) }}
							</span>
						</div>
					</div>
					<div class="floor-section-actions">
						<button
							class="toggle-button"
							:class="{ expanded: isFloorExpanded(floor.id) }"
							:aria-label="isFloorExpanded(floor.id) ? t('chorestrike', 'Collapse floor') : t('chorestrike', 'Expand floor')"
							:title="isFloorExpanded(floor.id) ? t('chorestrike', 'Collapse floor') : t('chorestrike', 'Expand floor')"
							@click="toggleFloor(floor.id)">
							<Icon :name="isFloorExpanded(floor.id) ? 'ChevronDown' : 'ChevronRight'" :size="16" />
						</button>
					</div>
				</div>

				<!-- Floor Content (Rooms) -->
				<div
					v-if="isFloorExpanded(floor.id)"
					class="floor-content">
					<!-- Loading Rooms -->
					<div v-if="loadingRooms[floor.id]" class="loading-rooms">
						<div class="loading-spinner small" />
						<span>{{ t('chorestrike', 'Loading rooms...') }}</span>
					</div>

					<!-- Empty Floor -->
					<div v-else-if="getFloorRooms(floor.id).length === 0" class="empty-floor">
						<p>{{ t('chorestrike', 'No rooms on this floor yet.') }}</p>
					</div>

					<!-- Rooms Grid -->
					<div v-else class="rooms-grid">
						<RoomCard
							v-for="room in getFloorRooms(floor.id)"
							:key="room.id"
							:room="room"
							:compact="compact"
							@room-click="handleRoomClick" />
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { computed, ref, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useRoomsStore } from '../../store/rooms.js'
import { useRouter } from 'vue-router'
import { logger } from '../../utils/logger.js'
import Icon from '../Common/Icon.vue'
import RoomCard from './RoomCard.vue'

export default {
	name: 'FloorList',

	components: {
		Icon,
		RoomCard,
	},

	props: {
		compact: {
			type: Boolean,
			default: false,
		},
		autoExpand: {
			type: Boolean,
			default: false,
		},
	},

	setup(props) {
		const router = useRouter()
		const roomsStore = useRoomsStore()

		// Reactive state
		const loadingRooms = ref({})

		// Computed properties
		const floors = computed(() => roomsStore.floors)
		const loading = computed(() => roomsStore.loading)

		const sortedFloors = computed(() => {
			return [...floors.value].sort((a, b) => a.sortOrder - b.sortOrder)
		})

		// Methods
		const getFloorRooms = (floorId) => {
			return roomsStore.roomsByFloor[floorId] || []
		}

		const getRoomCount = (floorId) => {
			return getFloorRooms(floorId).length
		}

		const getFloorCleanliness = (floorId) => {
			const rooms = getFloorRooms(floorId)
			if (rooms.length === 0) return 100

			const totalCleanliness = rooms.reduce((sum, room) => sum + room.cleanliness, 0)
			return Math.round(totalCleanliness / rooms.length)
		}

		const getFloorCleanlinessClass = (floorId) => {
			const cleanliness = getFloorCleanliness(floorId)
			if (cleanliness >= 80) return 'excellent'
			if (cleanliness >= 60) return 'good'
			if (cleanliness >= 40) return 'fair'
			if (cleanliness >= 20) return 'poor'
			return 'terrible'
		}

		const getFloorIconName = (floor) => {
			const sortOrder = Number(floor.sortOrder || 0)
			if (sortOrder <= 0) return 'House'
			if (sortOrder === 1) return 'Building'
			return 'LayoutGrid'
		}

		const getFloorIconClass = (floor) => {
			const sortOrder = Number(floor.sortOrder || 0)
			if (sortOrder <= 0) return 'ground-floor'
			if (sortOrder === 1) return 'first-floor'
			return 'upper-floor'
		}

		const isFloorExpanded = (floorId) => {
			// Access state directly for better compatibility
			return roomsStore.expandedFloors.some(id => String(id) === String(floorId))
		}

		const toggleFloor = async (floorId) => {
			roomsStore.toggleFloorExpanded(floorId)
		}

		const refreshFloors = async () => {
			try {
				await roomsStore.fetchFloorsWithRooms()
			} catch (error) {
				logger.error('Failed to refresh floors', error, { component: 'FloorList', action: 'refreshFloors' })
			}
		}

		const handleRoomClick = (room) => {
			router.push(`/room/${room.id}`)
		}

		// Lifecycle
		onMounted(() => {
			// Auto-expand first floor if enabled
			if (props.autoExpand && sortedFloors.value.length > 0) {
				roomsStore.setFloorExpanded(sortedFloors.value[0].id)
			}
		})

		return {
			t,
			loadingRooms,
			floors,
			loading,
			sortedFloors,
			getFloorRooms,
			getRoomCount,
			getFloorCleanliness,
			getFloorCleanlinessClass,
			getFloorIconName,
			getFloorIconClass,
			isFloorExpanded,
			toggleFloor,
			refreshFloors,
			handleRoomClick,
		}
	},
}
</script>

<style scoped>
.floor-list {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 16px;
}

.floor-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

.floor-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 18px;
  font-weight: 600;
  margin: 0;
  color: var(--color-main-text);
}

.floor-title .icon {
  font-size: 20px;
}

.floor-actions {
  display: flex;
  gap: 8px;
}

.refresh-button {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 44px;
  padding: 0 12px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-main-background);
  cursor: pointer;
  transition: all 0.2s ease;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
}

.button-icon {
  flex-shrink: 0;
}

.button-label {
  line-height: 1;
}

.refresh-button:hover:not(:disabled) {
  background: var(--color-background-hover);
  border-color: var(--color-primary);
}

.refresh-button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.loading-state,
.empty-state {
  text-align: center;
  padding: 40px 20px;
  color: var(--color-text-maxcontrast);
}

.loading-spinner {
  width: 32px;
  height: 32px;
  border: 3px solid var(--color-border);
  border-top: 3px solid var(--color-primary);
  border-radius: 50%;
  animation: spin 1s linear infinite;
  margin: 0 auto 16px;
}

.loading-spinner.small {
  width: 20px;
  height: 20px;
  border-width: 2px;
  margin: 0 8px 0 0;
  display: inline-block;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

.empty-icon {
  font-size: 48px;
  margin-bottom: 16px;
}

.empty-state h4 {
  margin: 0 0 8px 0;
  color: var(--color-main-text);
}

.empty-state p {
  margin: 0 0 20px 0;
  max-width: 300px;
  margin-inline: auto;
}

.floors-container {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.floor-section {
  border: 1px solid var(--color-border);
  border-radius: 8px;
  overflow: hidden;
}

.floor-section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 16px;
  background: var(--color-background-dark);
}

.floor-info {
  flex: 1;
}

.floor-name-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 4px;
}

.floor-icon-shell {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  border-radius: 0.75rem;
  border: 1px solid var(--color-border);
  flex-shrink: 0;
}

.floor-icon-shell.ground-floor {
  background: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(14, 165, 233, 0.26));
  color: var(--color-primary-element);
}

.floor-icon-shell.first-floor {
  background: linear-gradient(135deg, rgba(245, 158, 11, 0.18), rgba(249, 115, 22, 0.24));
  color: var(--cs-tier-gold, #d4a017);
}

.floor-icon-shell.upper-floor {
  background: linear-gradient(135deg, rgba(34, 197, 94, 0.18), rgba(16, 185, 129, 0.24));
  color: var(--color-success);
}

.floor-name {
  font-size: 16px;
  font-weight: 600;
  margin: 0 0 4px 0;
  color: var(--color-main-text);
  padding: 2px 6px;
}

.floor-stats {
  display: flex;
  gap: 12px;
  align-items: center;
  font-size: 12px;
  color: var(--color-text-maxcontrast);
}

.cleanliness-badge {
  padding: 2px 6px;
  border-radius: 10px;
  font-weight: 500;
}

.cleanliness-badge.excellent {
  background: var(--color-success, #4CAF50);
  color: var(--color-primary-text, #ffffff);
}

.cleanliness-badge.good {
  background: var(--color-primary, #2196F3);
  color: var(--color-primary-text, #ffffff);
}

.cleanliness-badge.fair {
  background: var(--color-warning, #FF9800);
  color: var(--color-primary-text, #ffffff);
}

.cleanliness-badge.poor {
  background: var(--color-warning, #FF5722);
  color: var(--color-primary-text, #ffffff);
}

.cleanliness-badge.terrible {
  background: var(--color-error, #F44336);
  color: var(--color-primary-text, #ffffff);
}

.floor-section-actions {
  display: flex;
  gap: 6px;
}

.toggle-button {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-background-dark);
  cursor: pointer;
  transition: all 0.2s ease;
  font-size: 12px;
  color: var(--color-main-text);
}

.toggle-button:hover {
  background: var(--color-background-hover);
  transform: translateY(-1px);
}

.toggle-button {
  color: var(--color-primary);
}

.floor-content {
  padding: 16px;
}

.loading-rooms {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 20px;
  color: var(--color-text-maxcontrast);
}

.empty-floor {
  text-align: center;
  padding: 20px;
  color: var(--color-text-maxcontrast);
}

.rooms-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 12px;
}

/* Responsive design */
@media (max-width: 768px) {
  .floor-list {
    padding: 12px;
  }

  .floor-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
  }

  .button-label {
    display: none;
  }

  .refresh-button {
    padding: 0 10px;
  }

  .rooms-grid {
    grid-template-columns: 1fr;
  }
}
</style>
