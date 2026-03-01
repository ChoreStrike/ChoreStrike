<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="floor-list">
		<div class="floor-header">
			<h3 class="floor-title">
				<Icon name="Building" :size="20" class="icon" />
				Floors & Rooms
			</h3>
			<div class="floor-actions">
				<button
					class="refresh-button"
					:disabled="loading"
					title="Refresh floors"
					@click="refreshFloors">
					<Icon name="RefreshCw" :size="16" />
				</button>
				<button
					class="add-button"
					title="Add new floor"
					@click="addFloor">
					<Icon name="Plus" :size="16" />
				</button>
			</div>
		</div>

		<!-- Loading State -->
		<div v-if="loading && floors.length === 0" class="loading-state">
			<div class="loading-spinner" />
			<p>Loading floors...</p>
		</div>

		<!-- Empty State -->
		<div v-else-if="floors.length === 0" class="empty-state">
			<div class="empty-icon">
				<Icon name="Home" :size="48" />
			</div>
			<h4>No floors yet</h4>
			<p>Create your first floor to get started with organizing your home.</p>
			<button class="primary-button" @click="addFloor">
				<Icon name="Plus" :size="16" class="icon" />
				Create First Floor
			</button>
		</div>

		<!-- Floors List -->
		<div v-else class="floors-container">
			<div
				v-for="floor in sortedFloors"
				:key="floor.id"
				class="floor-section">
				<!-- Floor Header -->
				<div class="floor-section-header">
					<div class="floor-info">
						<h4 class="floor-name">
							{{ floor.name }}
						</h4>
						<div class="floor-stats">
							<span class="room-count">{{ getRoomCount(floor.id) }} rooms</span>
							<span class="cleanliness-badge" :class="getFloorCleanlinessClass(floor.id)">
								{{ getFloorCleanliness(floor.id) }}% clean
							</span>
						</div>
					</div>
					<div class="floor-section-actions">
						<button
							class="toggle-button"
							:class="{ expanded: expandedFloors.includes(floor.id) }"
							@click="toggleFloor(floor.id)">
							<Icon :name="expandedFloors.includes(floor.id) ? 'ChevronDown' : 'ChevronRight'" :size="16" />
						</button>
						<button
							class="edit-button"
							title="Edit floor"
							@click="editFloor(floor)">
							<Icon name="Edit" :size="16" />
						</button>
						<button
							class="delete-button"
							title="Delete floor"
							@click="deleteFloor(floor)">
							<Icon name="Trash" :size="16" />
						</button>
					</div>
				</div>

				<!-- Floor Content (Rooms) -->
				<div
					v-if="expandedFloors.includes(floor.id)"
					class="floor-content">
					<!-- Loading Rooms -->
					<div v-if="loadingRooms[floor.id]" class="loading-rooms">
						<div class="loading-spinner small" />
						<span>Loading rooms...</span>
					</div>

					<!-- Empty Floor -->
					<div v-else-if="getFloorRooms(floor.id).length === 0" class="empty-floor">
						<p>No rooms on this floor yet.</p>
						<button
							class="add-room-button"
							@click="addRoom(floor.id)">
							<Icon name="Plus" :size="16" class="icon" />
							Add Room
						</button>
					</div>

					<!-- Rooms Grid -->
					<div v-else class="rooms-grid">
						<RoomCard
							v-for="room in getFloorRooms(floor.id)"
							:key="room.id"
							:room="room"
							:compact="compact"
							@room-click="handleRoomClick" />

						<!-- Add Room Button -->
						<div class="add-room-card" @click="addRoom(floor.id)">
							<div class="add-room-content">
								<Icon name="Plus" :size="24" class="add-icon" />
								<span class="add-text">Add Room</span>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Add Floor Modal -->
		<div v-if="showAddFloorModal" class="modal-overlay" @click="closeAddFloorModal">
			<div class="modal-content" @click.stop>
				<h3>Add New Floor</h3>
				<form @submit.prevent="handleAddFloor">
					<div class="form-group">
						<label for="floor-name">Floor Name</label>
						<input
							id="floor-name"
							v-model="newFloorName"
							type="text"
							placeholder="e.g., Ground Floor, Second Floor"
							required
							class="form-input">
					</div>
					<div class="form-actions">
						<button type="button" class="cancel-button" @click="closeAddFloorModal">
							Cancel
						</button>
						<button type="submit" class="submit-button" :disabled="!newFloorName.trim()">
							Create Floor
						</button>
					</div>
				</form>
			</div>
		</div>
	</div>
</template>

<script>
import { computed, ref, onMounted } from 'vue'
import { useRoomsStore } from '../../store/rooms.js'
import { useRouter } from 'vue-router'
import RoomCard from './RoomCard.vue'

export default {
	name: 'FloorList',

	components: {
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
		const expandedFloors = ref([])
		const loadingRooms = ref({})
		const showAddFloorModal = ref(false)
		const newFloorName = ref('')

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

		const toggleFloor = async (floorId) => {
			const index = expandedFloors.value.indexOf(floorId)

			if (index > -1) {
				expandedFloors.value.splice(index, 1)
			} else {
				expandedFloors.value.push(floorId)

				// Load rooms if not already loaded
				if (getFloorRooms(floorId).length === 0) {
					loadingRooms.value[floorId] = true
					try {
						await roomsStore.fetchRooms(floorId)
					} catch (error) {
						console.error('Failed to load rooms:', error)
					} finally {
						loadingRooms.value[floorId] = false
					}
				}
			}
		}

		const refreshFloors = async () => {
			try {
				await roomsStore.fetchFloorsWithRooms()
			} catch (error) {
				console.error('Failed to refresh floors:', error)
			}
		}

		const addFloor = () => {
			showAddFloorModal.value = true
			newFloorName.value = ''
		}

		const closeAddFloorModal = () => {
			showAddFloorModal.value = false
			newFloorName.value = ''
		}

		const handleAddFloor = async () => {
			if (!newFloorName.value.trim()) return

			try {
				const floor = await roomsStore.createFloor(newFloorName.value.trim())
				closeAddFloorModal()

				// Auto-expand the new floor
				expandedFloors.value.push(floor.id)
			} catch (error) {
				console.error('Failed to create floor:', error)
			}
		}

		const editFloor = () => {
			// TODO: Implement edit floor functionality
		}

		const deleteFloor = async (floor) => {
			if (!confirm(`Are you sure you want to delete "${floor.name}" and all its rooms?`)) {
				return
			}

			try {
				await roomsStore.deleteFloor(floor.id)

				// Remove from expanded floors
				const index = expandedFloors.value.indexOf(floor.id)
				if (index > -1) {
					expandedFloors.value.splice(index, 1)
				}
			} catch (error) {
				console.error('Failed to delete floor:', error)
			}
		}

		const addRoom = () => {
			// TODO: Implement add room functionality
		}

		const handleRoomClick = (room) => {
			router.push(`/room/${room.id}`)
		}

		// Lifecycle
		onMounted(() => {
			// Auto-expand first floor if enabled
			if (props.autoExpand && sortedFloors.value.length > 0) {
				expandedFloors.value.push(sortedFloors.value[0].id)
			}
		})

		return {
			expandedFloors,
			loadingRooms,
			showAddFloorModal,
			newFloorName,
			floors,
			loading,
			sortedFloors,
			getFloorRooms,
			getRoomCount,
			getFloorCleanliness,
			getFloorCleanlinessClass,
			toggleFloor,
			refreshFloors,
			addFloor,
			closeAddFloorModal,
			handleAddFloor,
			editFloor,
			deleteFloor,
			addRoom,
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

.refresh-button,
.add-button {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-main-background);
  cursor: pointer;
  transition: all 0.2s ease;
}

.refresh-button:hover:not(:disabled),
.add-button:hover {
  background: var(--color-background-hover);
  border-color: var(--color-primary);
}

.add-button {
  background: var(--color-primary);
  border-color: var(--color-primary);
  color: white;
}

.add-button:hover {
  background: var(--color-primary-hover);
  border-color: var(--color-primary-hover);
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
  margin-left: auto;
  margin-right: auto;
}

.primary-button {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 20px;
  background: var(--color-primary);
  color: white;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  font-size: 14px;
  transition: all 0.2s ease;
}

.primary-button:hover {
  background: var(--color-primary-hover);
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

.floor-name {
  font-size: 16px;
  font-weight: 600;
  margin: 0 0 4px 0;
  color: var(--color-main-text);
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
  background: #4CAF50;
  color: white;
}

.cleanliness-badge.good {
  background: #2196F3;
  color: white;
}

.cleanliness-badge.fair {
  background: #FF9800;
  color: white;
}

.cleanliness-badge.poor {
  background: #FF5722;
  color: white;
}

.cleanliness-badge.terrible {
  background: #F44336;
  color: white;
}

.floor-section-actions {
  display: flex;
  gap: 4px;
}

.toggle-button,
.edit-button,
.delete-button {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border: 1px solid var(--color-border);
  border-radius: 4px;
  background: var(--color-main-background);
  cursor: pointer;
  transition: all 0.2s ease;
  font-size: 12px;
}

.toggle-button:hover,
.edit-button:hover,
.delete-button:hover {
  background: var(--color-background-hover);
}

.delete-button:hover {
  border-color: #F44336;
  color: #F44336;
}

.toggle-button.expanded {
  background: var(--color-primary);
  border-color: var(--color-primary);
  color: white;
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

.add-room-button {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  border: 1px dashed var(--color-border);
  border-radius: 6px;
  background: transparent;
  color: var(--color-text-maxcontrast);
  cursor: pointer;
  transition: all 0.2s ease;
  margin: 0 auto;
}

.add-room-button:hover {
  border-color: var(--color-primary);
  color: var(--color-primary);
}

.rooms-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 12px;
}

.add-room-card {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 120px;
  border: 2px dashed var(--color-border);
  border-radius: 8px;
  background: var(--color-background-dark);
  cursor: pointer;
  transition: all 0.2s ease;
}

.add-room-card:hover {
  border-color: var(--color-primary);
  background: rgba(var(--color-primary-rgb), 0.1);
}

.add-room-content {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  color: var(--color-text-maxcontrast);
}

.add-room-card:hover .add-room-content {
  color: var(--color-primary);
}

.add-icon {
  font-size: 24px;
}

.add-text {
  font-size: 14px;
  font-weight: 500;
}

/* Modal */
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-content {
  background: var(--color-main-background);
  border-radius: 12px;
  padding: 24px;
  min-width: 400px;
  max-width: 90vw;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
}

.modal-content h3 {
  margin: 0 0 20px 0;
  color: var(--color-main-text);
}

.form-group {
  margin-bottom: 20px;
}

.form-group label {
  display: block;
  margin-bottom: 8px;
  font-weight: 500;
  color: var(--color-main-text);
}

.form-input {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  font-size: 14px;
  background: var(--color-main-background);
  color: var(--color-main-text);
}

.form-input:focus {
  outline: none;
  border-color: var(--color-primary);
  box-shadow: 0 0 0 2px rgba(var(--color-primary-rgb), 0.2);
}

.form-actions {
  display: flex;
  gap: 12px;
  justify-content: flex-end;
}

.cancel-button,
.submit-button {
  padding: 8px 16px;
  border-radius: 6px;
  font-size: 14px;
  cursor: pointer;
  transition: all 0.2s ease;
}

.cancel-button {
  background: var(--color-background-dark);
  border: 1px solid var(--color-border);
  color: var(--color-main-text);
}

.cancel-button:hover {
  background: var(--color-background-hover);
}

.submit-button {
  background: var(--color-primary);
  border: 1px solid var(--color-primary);
  color: white;
}

.submit-button:hover:not(:disabled) {
  background: var(--color-primary-hover);
}

.submit-button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
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

  .rooms-grid {
    grid-template-columns: 1fr;
  }

  .modal-content {
    min-width: auto;
    margin: 20px;
  }
}
</style>
