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
				<button
					class="add-button"
					data-testid="add-floor-button"
					:title="t('chorestrike', 'Add new floor')"
					@click="addFloor">
					<Icon name="Plus" :size="16" class="button-icon" />
					<span class="button-label">{{ t('chorestrike', 'Add Floor') }}</span>
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
			<button class="primary-button" @click="addFloor">
				<Icon name="Plus" :size="16" class="icon" />
				{{ t('chorestrike', 'Create First Floor') }}
			</button>
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
							<template v-if="editingFloorId === floor.id">
								<input
									ref="editFloorInput"
									v-model="editingFloorName"
									type="text"
									class="floor-name-input"
									data-testid="floor-name-edit-input"
									@keydown.enter="saveFloorName(floor)"
									@keydown.escape="cancelFloorEdit"
									@blur="saveFloorName(floor)"
									@click.stop>
							</template>
							<h4
								v-else
								class="floor-name"
								@click="editFloor(floor)">
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
							:title="isFloorExpanded(floor.id) ? t('chorestrike', 'Collapse floor') : t('chorestrike', 'Expand floor')"
							@click="toggleFloor(floor.id)">
							<Icon :name="isFloorExpanded(floor.id) ? 'ChevronDown' : 'ChevronRight'" :size="16" />
						</button>
						<button
							class="edit-button"
							:title="t('chorestrike', 'Edit floor')"
							@click="editFloor(floor)">
							<Icon name="Edit" :size="16" />
						</button>
						<button
							class="delete-button"
							:title="t('chorestrike', 'Delete floor')"
							@click="deleteFloor(floor)">
							<Icon name="Trash" :size="16" />
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
						<button
							class="add-room-button"
							:data-testid="`add-room-button-${floor.id}`"
							@click="addRoom(floor.id)">
							<Icon name="Plus" :size="16" class="icon" />
							{{ t('chorestrike', 'Add Room') }}
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
						<div class="add-room-card" :data-testid="`add-room-card-${floor.id}`" @click="addRoom(floor.id)">
							<div class="add-room-content">
								<Icon name="Plus" :size="24" class="add-icon" />
								<span class="add-text">{{ t('chorestrike', 'Add Room') }}</span>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Add Floor Modal -->
		<div v-if="showAddFloorModal" class="modal-overlay" @click="closeAddFloorModal">
			<div class="modal-content" @click.stop>
				<h3>{{ t('chorestrike', 'Add New Floor') }}</h3>
				<form @submit.prevent="handleAddFloor">
					<div class="form-group">
						<label for="floor-name">{{ t('chorestrike', 'Floor Name') }}</label>
						<input
							id="floor-name"
							v-model="newFloorName"
							type="text"
							:placeholder="t('chorestrike', 'e.g., Ground Floor, Second Floor')"
							required
							class="form-input">
					</div>
					<div class="form-actions">
						<button type="button" class="cancel-button" @click="closeAddFloorModal">
							{{ t('chorestrike', 'Cancel') }}
						</button>
						<button type="submit" class="submit-button" :disabled="!newFloorName.trim()">
							{{ t('chorestrike', 'Create Floor') }}
						</button>
					</div>
				</form>
			</div>
		</div>

		<!-- Add Room Modal -->
		<div v-if="showAddRoomModal" class="modal-overlay" @click="closeAddRoomModal">
			<div class="modal-content" @click.stop>
				<h3>{{ t('chorestrike', 'Add Room') }}</h3>
				<p class="modal-subtitle">
					{{ t('chorestrike', 'Floor: {name}', { name: selectedFloorName }) }}
				</p>
				<form @submit.prevent="handleAddRoom">
					<div class="room-icon-preview-row">
						<div class="room-icon-preview-shell">
							<Icon :name="selectedRoomIconName" :size="22" class="room-icon-preview" />
						</div>
						<span class="room-icon-preview-label">{{ t('chorestrike', 'Room icon') }}</span>
					</div>
					<div class="form-group">
						<label for="room-name">{{ t('chorestrike', 'Room Name') }}</label>
						<input
							id="room-name"
							v-model="newRoomName"
							type="text"
							data-testid="room-name-input"
							:placeholder="t('chorestrike', 'e.g., Kitchen, Bathroom')"
							required
							class="form-input">
					</div>
					<div class="form-group">
						<label for="room-icon">{{ t('chorestrike', 'Icon') }}</label>
						<select
							id="room-icon"
							v-model="newRoomIcon"
							class="form-input"
							data-testid="room-icon-select">
							<option
								v-for="option in roomIconOptions"
								:key="option.value"
								:value="option.value">
								{{ option.label }}
							</option>
						</select>
					</div>
					<div class="form-actions">
						<button type="button" class="cancel-button" @click="closeAddRoomModal">
							{{ t('chorestrike', 'Cancel') }}
						</button>
						<button
							type="submit"
							class="submit-button"
							data-testid="create-room-button"
							:disabled="!newRoomName.trim() || loading">
							{{ t('chorestrike', 'Create Room') }}
						</button>
					</div>
				</form>
			</div>
		</div>
	</div>
</template>

<script>
import { computed, ref, onMounted, nextTick } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useRoomsStore } from '../../store/rooms.js'
import { useRouter } from 'vue-router'
import { logger } from '../../utils/logger.js'
import Icon from '../Common/Icon.vue'
import { ROOM_ICON_OPTIONS, resolveRoomIcon } from '../../utils/roomIcons.js'
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
		const showAddFloorModal = ref(false)
		const newFloorName = ref('')
		const showAddRoomModal = ref(false)
		const selectedFloorId = ref(null)
		const newRoomName = ref('')
		const newRoomIcon = ref('Home')
		const editingFloorId = ref(null)
		const editingFloorName = ref('')
		const editFloorInput = ref(null)

		// Computed properties
		const floors = computed(() => roomsStore.floors)
		const loading = computed(() => roomsStore.loading)
		const roomIconOptions = computed(() => ROOM_ICON_OPTIONS)
		const selectedRoomIconName = computed(() => resolveRoomIcon(newRoomIcon.value, newRoomName.value))

		const sortedFloors = computed(() => {
			return [...floors.value].sort((a, b) => a.sortOrder - b.sortOrder)
		})

		const selectedFloorName = computed(() => {
			if (selectedFloorId.value === null) {
				return t('chorestrike', 'Unknown')
			}

			const floor = roomsStore.getFloorById(selectedFloorId.value)
			return floor?.name || t('chorestrike', 'Unknown')
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
				const newFloor = await roomsStore.createFloor(newFloorName.value.trim())
				closeAddFloorModal()

				// Expand the new floor BEFORE refresh so it stays expanded after re-render
				if (newFloor) {
					roomsStore.setFloorExpanded(newFloor.id)
					await nextTick() // Ensure Vue processes the expanded state
				}

				// Force refresh to ensure UI updates in Nextcloud environment
				await roomsStore.fetchFloorsWithRooms()
			} catch (error) {
				logger.error('Failed to create floor', error, { component: 'FloorList', action: 'handleAddFloor' })
			}
		}

		/**
		 * Start inline editing of a floor name.
		 * @param {object} floor Floor object to edit.
		 */
		const editFloor = (floor) => {
			editingFloorId.value = floor.id
			editingFloorName.value = floor.name
			nextTick(() => {
				const input = editFloorInput.value
				if (input && typeof input.focus === 'function') {
					input.focus()
					if (typeof input.select === 'function') {
						input.select()
					}
				}
			})
		}

		/**
		 * Save the edited floor name.
		 * @param {object} floor Floor object being edited.
		 */
		const saveFloorName = async (floor) => {
			if (editingFloorId.value !== floor.id) return

			const trimmedName = editingFloorName.value.trim()

			// Cancel if name is empty or unchanged
			if (!trimmedName || trimmedName === floor.name) {
				cancelFloorEdit()
				return
			}

			try {
				await roomsStore.updateFloorName(floor.id, trimmedName)
			} catch (error) {
				logger.error('Failed to update floor name', error, { component: 'FloorList', action: 'saveFloorName', floorId: floor.id })
			} finally {
				cancelFloorEdit()
			}
		}

		/**
		 * Cancel floor name editing.
		 */
		const cancelFloorEdit = () => {
			editingFloorId.value = null
			editingFloorName.value = ''
		}

		const deleteFloor = async (floor) => {
			const message = t('chorestrike', 'Are you sure you want to delete "{name}" and all its rooms?', {
				name: floor.name,
			})

			if (!confirm(message)) {
				return
			}

			try {
				await roomsStore.deleteFloor(floor.id)

				// Remove from expanded floors
				roomsStore.setFloorCollapsed(floor.id)
			} catch (error) {
				logger.error('Failed to delete floor', error, { component: 'FloorList', action: 'deleteFloor', floorId: floor.id })
			}
		}

		const addRoom = (floorId) => {
			selectedFloorId.value = floorId
			newRoomName.value = ''
			newRoomIcon.value = 'Home'
			showAddRoomModal.value = true
		}

		const closeAddRoomModal = () => {
			showAddRoomModal.value = false
			selectedFloorId.value = null
			newRoomName.value = ''
			newRoomIcon.value = 'Home'
		}

		const handleAddRoom = async () => {
			if (selectedFloorId.value === null || !newRoomName.value.trim()) {
				return
			}

			const floorId = selectedFloorId.value
			const roomNameValue = newRoomName.value.trim()

			// Ensure floor is expanded BEFORE refresh
			roomsStore.setFloorExpanded(floorId)
			await nextTick() // Ensure Vue processes the expanded state

			try {
				await roomsStore.createRoom(floorId, roomNameValue, selectedRoomIconName.value)
				closeAddRoomModal()

				// Force refresh to ensure UI updates in Nextcloud environment
				await roomsStore.fetchFloorsWithRooms()
			} catch (error) {
				logger.error('Failed to create room', error, { component: 'FloorList', action: 'handleAddRoom', floorId })
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
			showAddFloorModal,
			newFloorName,
			showAddRoomModal,
			selectedFloorName,
			newRoomName,
			newRoomIcon,
			roomIconOptions,
			selectedRoomIconName,
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
			addFloor,
			closeAddFloorModal,
			handleAddFloor,
			editFloor,
			saveFloorName,
			cancelFloorEdit,
			editingFloorId,
			editingFloorName,
			editFloorInput,
			deleteFloor,
			addRoom,
			closeAddRoomModal,
			handleAddRoom,
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
  min-height: 34px;
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
  border: 1px solid rgba(255, 255, 255, 0.08);
  flex-shrink: 0;
}

.floor-icon-shell.ground-floor {
  background: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(14, 165, 233, 0.26));
  color: #7bd3ff;
}

.floor-icon-shell.first-floor {
  background: linear-gradient(135deg, rgba(245, 158, 11, 0.18), rgba(249, 115, 22, 0.24));
  color: #ffc973;
}

.floor-icon-shell.upper-floor {
  background: linear-gradient(135deg, rgba(34, 197, 94, 0.18), rgba(16, 185, 129, 0.24));
  color: #78e7a8;
}

.floor-name {
  font-size: 16px;
  font-weight: 600;
  margin: 0 0 4px 0;
  color: var(--color-main-text);
  cursor: pointer;
  padding: 2px 6px;
  border-radius: 4px;
  transition: background 0.15s ease;
}

.floor-name:hover {
  background: rgba(255, 255, 255, 0.08);
}

.floor-name-input {
  font-size: 16px;
  font-weight: 600;
  color: var(--color-main-text);
  background: var(--color-main-background);
  border: 1px solid var(--color-primary);
  border-radius: 4px;
  padding: 2px 6px;
  min-width: 120px;
  max-width: 200px;
  outline: none;
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

.toggle-button,
.edit-button,
.delete-button {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.04);
  cursor: pointer;
  transition: all 0.2s ease;
  font-size: 12px;
  color: var(--color-main-text);
}

.toggle-button:hover,
.edit-button:hover,
.delete-button:hover {
  background: rgba(255, 255, 255, 0.1);
  transform: translateY(-1px);
}

.toggle-button {
  color: #7bd3ff;
}

.edit-button {
  color: #ffc973;
}

.delete-button {
  color: #ff9d9d;
}

.delete-button:hover {
  border-color: var(--color-error, #F44336);
  color: var(--color-error, #F44336);
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

.modal-subtitle {
  margin: -10px 0 14px;
  color: var(--color-text-maxcontrast);
  font-size: 13px;
}

.room-icon-preview-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 16px;
}

.room-icon-preview-shell {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.85rem;
  background: linear-gradient(135deg, rgba(96, 165, 250, 0.16), rgba(168, 85, 247, 0.24));
  color: #9bd5ff;
  border: 1px solid rgba(255, 255, 255, 0.08);
}

.room-icon-preview-label {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-main-text);
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

  .button-label {
    display: none;
  }

  .refresh-button,
  .add-button {
    padding: 0 10px;
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
