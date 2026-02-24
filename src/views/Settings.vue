<template>
	<div class="settings">
		<div class="settings-header">
			<h1>{{ t('chorestrike', 'House Settings') }}</h1>
			<p>{{ t('chorestrike', 'Configure your shared home base.') }}</p>
		</div>

		<div v-if="loading" class="loading-state">
			<div class="loading-spinner" />
			<p>{{ t('chorestrike', 'Loading settings...') }}</p>
		</div>

		<div v-else class="settings-grid">
			<section class="settings-card">
				<h2>{{ t('chorestrike', 'General') }}</h2>
				<div class="form-group">
					<label for="house-name">{{ t('chorestrike', 'House Name') }}</label>
					<input
						id="house-name"
						v-model.trim="houseNameInput"
						type="text"
						class="form-input">
				</div>
				<div class="form-actions">
					<button
						class="primary-button"
						:disabled="!canSaveName"
						@click="saveHouseName">
						{{ t('chorestrike', 'Save Name') }}
					</button>
				</div>
			</section>

			<section class="settings-card">
				<h2>{{ t('chorestrike', 'Overview') }}</h2>
				<ul class="stat-list">
					<li><span>{{ t('chorestrike', 'House Health') }}</span><strong>{{ houseHealth }}%</strong></li>
					<li><span>{{ t('chorestrike', 'Floors') }}</span><strong>{{ floorsCount }}</strong></li>
					<li><span>{{ t('chorestrike', 'Rooms') }}</span><strong>{{ roomsCount }}</strong></li>
					<li><span>{{ t('chorestrike', 'Average Cleanliness') }}</span><strong>{{ averageCleanliness }}%</strong></li>
				</ul>
			</section>

			<section class="settings-card danger-zone">
				<h2>{{ t('chorestrike', 'Maintenance') }}</h2>
				<p>{{ t('chorestrike', 'Reset the house to an initial clean state.') }}</p>
				<button class="danger-button" @click="resetHouse">
					{{ t('chorestrike', 'Reset House') }}
				</button>
			</section>

			<section class="settings-card">
				<h2>{{ t('chorestrike', 'Floors') }}</h2>
				<div class="inline-form">
					<input
						v-model.trim="newFloorName"
						type="text"
						class="form-input"
						:placeholder="t('chorestrike', 'New floor name')">
					<button class="primary-button" :disabled="!newFloorName" @click="createFloor">
						{{ t('chorestrike', 'Add') }}
					</button>
				</div>
				<ul class="entity-list sortable-list">
					<li
						v-for="(floor, index) in floors"
						:key="floor.id"
						class="sortable-item"
						draggable="true"
						@dragstart="dragStart(index)"
						@dragover.prevent
						@drop="drop(index)">
						<span class="drag-handle">⋮⋮</span>
						<span class="item-name">{{ floor.name }}</span>
						<div class="item-actions">
							<button
								class="ghost-button"
								disabled="index === 0"
								@click="moveFloor(index, -1)">
								↑
							</button>
							<button
								class="ghost-button"
								disabled="index === floors.length - 1"
								@click="moveFloor(index, 1)">
								↓
							</button>
							<button class="ghost-button" @click="editFloor(floor)">
								{{ t('chorestrike', 'Edit') }}
							</button>
							<button class="ghost-danger" @click="deleteFloor(floor)">
								{{ t('chorestrike', 'Delete') }}
							</button>
						</div>
					</li>
				</ul>
			</section>

			<section class="settings-card">
				<h2>{{ t('chorestrike', 'Rooms') }}</h2>
				<div class="inline-form room-form-grid">
					<select v-model.number="newRoomFloorId" class="form-input">
						<option :value="null" disabled>
							{{ t('chorestrike', 'Select floor') }}
						</option>
						<option v-for="floor in floors" :key="floor.id" :value="floor.id">
							{{ floor.name }}
						</option>
					</select>
					<input
						v-model.trim="newRoomName"
						type="text"
						class="form-input"
						:placeholder="t('chorestrike', 'New room name')">
					<button class="primary-button" :disabled="!canCreateRoom" @click="createRoom">
						{{ t('chorestrike', 'Add') }}
					</button>
				</div>
				<ul class="entity-list">
					<li v-for="room in rooms" :key="room.id" class="room-item">
						<span class="room-name">{{ room.name }}</span>
						<span class="room-floor">{{ getFloorName(room.floorId) }}</span>
						<div class="item-actions">
							<button class="ghost-button" @click="editRoom(room)">
								{{ t('chorestrike', 'Edit') }}
							</button>
							<button class="ghost-danger" @click="deleteRoom(room)">
								{{ t('chorestrike', 'Delete') }}
							</button>
						</div>
					</li>
				</ul>
			</section>
		</div>

		<p v-if="error" class="error-message">
			{{ error }}
		</p>
		<p v-if="successMessage" class="success-message">
			{{ successMessage }}
		</p>

		<!-- Edit Floor Modal -->
		<div v-if="editingFloor" class="modal-overlay" @click="cancelFloorEdit">
			<div class="modal-content" @click.stop>
				<h3>{{ t('chorestrike', 'Edit Floor') }}</h3>
				<div class="form-group">
					<label for="edit-floor-name">{{ t('chorestrike', 'Floor Name') }}</label>
					<input
						id="edit-floor-name"
						v-model.trim="editFloorName"
						type="text"
						class="form-input">
				</div>
				<div class="form-actions">
					<button class="cancel-button" @click="cancelFloorEdit">
						{{ t('chorestrike', 'Cancel') }}
					</button>
					<button
						class="submit-button"
						:disabled="!editFloorName"
						@click="saveFloorEdit">
						{{ t('chorestrike', 'Save') }}
					</button>
				</div>
			</div>
		</div>

		<!-- Edit Room Modal -->
		<div v-if="editingRoom" class="modal-overlay" @click="cancelRoomEdit">
			<div class="modal-content" @click.stop>
				<h3>{{ t('chorestrike', 'Edit Room') }}</h3>
				<div class="form-group">
					<label for="edit-room-name">{{ t('chorestrike', 'Room Name') }}</label>
					<input
						id="edit-room-name"
						v-model.trim="editRoomName"
						type="text"
						class="form-input">
				</div>
				<div class="form-group">
					<label for="edit-room-floor">{{ t('chorestrike', 'Floor') }}</label>
					<select id="edit-room-floor" v-model.number="editRoomFloorId" class="form-input">
						<option v-for="floor in floors" :key="floor.id" :value="floor.id">
							{{ floor.name }}
						</option>
					</select>
				</div>
				<div class="form-actions">
					<button class="cancel-button" @click="cancelRoomEdit">
						{{ t('chorestrike', 'Cancel') }}
					</button>
					<button
						class="submit-button"
						:disabled="!editRoomName || !editRoomFloorId"
						@click="saveRoomEdit">
						{{ t('chorestrike', 'Save') }}
					</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { computed, onMounted, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useHouseStore } from '../store/house.js'
import { useRoomsStore } from '../store/rooms.js'

export default {
	name: 'Settings',
	setup() {
		const houseStore = useHouseStore()
		const roomsStore = useRoomsStore()

		const loading = ref(false)
		const error = ref('')
		const successMessage = ref('')
		const houseNameInput = ref('')
		const newFloorName = ref('')
		const newRoomName = ref('')
		const newRoomFloorId = ref(null)
		const draggedFloorIndex = ref(null)
		const editingFloor = ref(null)
		const editingRoom = ref(null)
		const editFloorName = ref('')
		const editRoomName = ref('')
		const editRoomFloorId = ref(null)

		const houseHealth = computed(() => houseStore.houseHealth)
		const floorsCount = computed(() => roomsStore.floors.length)
		const floors = computed(() => roomsStore.floors)
		const rooms = computed(() => roomsStore.rooms)
		const roomsCount = computed(() => roomsStore.rooms.length)
		const averageCleanliness = computed(() => roomsStore.averageCleanliness)
		const canCreateRoom = computed(() => {
			return newRoomName.value.length > 0 && newRoomFloorId.value !== null
		})

		const canSaveName = computed(() => {
			return houseNameInput.value.length > 0 && houseNameInput.value !== houseStore.houseName
		})

		const loadData = async () => {
			loading.value = true
			error.value = ''
			successMessage.value = ''
			try {
				await Promise.all([
					houseStore.initializeHouse(),
					roomsStore.fetchFloorsWithRooms(),
				])
				houseNameInput.value = houseStore.houseName
				if (newRoomFloorId.value === null && roomsStore.floors.length > 0) {
					newRoomFloorId.value = roomsStore.floors[0].id
				}
			} catch (loadError) {
				error.value = loadError.message
			} finally {
				loading.value = false
			}
		}

		const createFloor = async () => {
			error.value = ''
			successMessage.value = ''
			try {
				await roomsStore.createFloor(newFloorName.value)
				newFloorName.value = ''
				successMessage.value = t('chorestrike', 'Floor created.')
				if (newRoomFloorId.value === null && roomsStore.floors.length > 0) {
					newRoomFloorId.value = roomsStore.floors[0].id
				}
			} catch (createError) {
				error.value = createError.message
			}
		}

		const deleteFloor = async (floor) => {
			if (!confirm(t('chorestrike', 'Delete floor "{name}" and its rooms?', { name: floor.name }))) {
				return
			}
			error.value = ''
			successMessage.value = ''
			try {
				await roomsStore.deleteFloor(floor.id)
				successMessage.value = t('chorestrike', 'Floor deleted.')
			} catch (deleteError) {
				error.value = deleteError.message
			}
		}

		const createRoom = async () => {
			error.value = ''
			successMessage.value = ''
			try {
				await roomsStore.createRoom(newRoomFloorId.value, newRoomName.value)
				newRoomName.value = ''
				successMessage.value = t('chorestrike', 'Room created.')
			} catch (createError) {
				error.value = createError.message
			}
		}

		const deleteRoom = async (room) => {
			if (!confirm(t('chorestrike', 'Delete room "{name}"?', { name: room.name }))) {
				return
			}
			error.value = ''
			successMessage.value = ''
			try {
				await roomsStore.deleteRoom(room.id)
				successMessage.value = t('chorestrike', 'Room deleted.')
			} catch (deleteError) {
				error.value = deleteError.message
			}
		}

		const saveHouseName = async () => {
			error.value = ''
			successMessage.value = ''
			try {
				await houseStore.updateHouseName(houseNameInput.value)
				successMessage.value = t('chorestrike', 'House name updated successfully.')
			} catch (saveError) {
				error.value = saveError.message
			}
		}

		const resetHouse = async () => {
			if (!confirm(t('chorestrike', 'Reset house to initial state?'))) {
				return
			}
			error.value = ''
			successMessage.value = ''
			try {
				await houseStore.resetHouse()
				await roomsStore.fetchFloorsWithRooms()
				houseNameInput.value = houseStore.houseName
				successMessage.value = t('chorestrike', 'House was reset successfully.')
			} catch (resetError) {
				error.value = resetError.message
			}
		}

		// Drag and drop for floors
		const dragStart = (index) => {
			draggedFloorIndex.value = index
		}

		const drop = (index) => {
			if (draggedFloorIndex.value === null || draggedFloorIndex.value === index) {
				draggedFloorIndex.value = null
				return
			}

			const newFloors = [...floors.value]
			const [moved] = newFloors.splice(draggedFloorIndex.value, 1)
			newFloors.splice(index, 0, moved)

			// Update sort order
			const floorOrder = newFloors.map(f => f.id)
			roomsStore.reorderFloors(floorOrder)

			draggedFloorIndex.value = null
		}

		const moveFloor = async (index, direction) => {
			const newIndex = index + direction
			if (newIndex < 0 || newIndex >= floors.value.length) {
				return
			}

			const newFloors = [...floors.value]
			const [moved] = newFloors.splice(index, 1)
			newFloors.splice(newIndex, 0, moved)

			const floorOrder = newFloors.map(f => f.id)
			try {
				await roomsStore.reorderFloors(floorOrder)
				successMessage.value = t('chorestrike', 'Floor order updated.')
			} catch (err) {
				error.value = err.message
			}
		}

		// Helper to get floor name
		const getFloorName = (floorId) => {
			const floor = floors.value.find(f => f.id === floorId)
			return floor?.name || t('chorestrike', 'Unknown')
		}

		// Edit floor
		const editFloor = (floor) => {
			editingFloor.value = floor
			editFloorName.value = floor.name
		}

		const saveFloorEdit = async () => {
			if (!editingFloor.value || !editFloorName.value.trim()) {
				return
			}
			try {
				await roomsStore.updateFloor(editingFloor.value.id, editFloorName.value.trim())
				successMessage.value = t('chorestrike', 'Floor updated.')
				cancelFloorEdit()
			} catch (err) {
				error.value = err.message
			}
		}

		const cancelFloorEdit = () => {
			editingFloor.value = null
			editFloorName.value = ''
		}

		// Edit room
		const editRoom = (room) => {
			editingRoom.value = room
			editRoomName.value = room.name
			editRoomFloorId.value = room.floorId
		}

		const saveRoomEdit = async () => {
			if (!editingRoom.value || !editRoomName.value.trim()) {
				return
			}
			error.value = ''
			successMessage.value = ''
			try {
				await roomsStore.updateRoom(editingRoom.value.id, {
					name: editRoomName.value.trim(),
					floorId: editRoomFloorId.value,
				})
				successMessage.value = t('chorestrike', 'Room updated.')
				cancelRoomEdit()
			} catch (err) {
				error.value = err.message
			}
		}

		const cancelRoomEdit = () => {
			editingRoom.value = null
			editRoomName.value = ''
			editRoomFloorId.value = null
		}

		onMounted(loadData)

		return {
			loading,
			error,
			successMessage,
			houseNameInput,
			newFloorName,
			newRoomName,
			newRoomFloorId,
			editingFloor,
			editingRoom,
			editFloorName,
			editRoomName,
			editRoomFloorId,
			houseHealth,
			floorsCount,
			floors,
			rooms,
			roomsCount,
			averageCleanliness,
			canCreateRoom,
			canSaveName,
			getFloorName,
			saveHouseName,
			resetHouse,
			createFloor,
			deleteFloor,
			editFloor,
			saveFloorEdit,
			cancelFloorEdit,
			createRoom,
			deleteRoom,
			editRoom,
			saveRoomEdit,
			cancelRoomEdit,
			dragStart,
			drop,
			moveFloor,
			t,
		}
	},
}
</script>

<style scoped>
.settings {
	padding: 2rem;
	max-width: 960px;
	margin: 0 auto;
}

.settings-header {
	margin-bottom: 1.5rem;
}

.settings-header h1 {
	margin: 0 0 0.5rem;
}

.settings-header p {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.settings-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
	gap: 1rem;
}

.settings-card {
	border: 1px solid var(--color-border);
	border-radius: 10px;
	padding: 1rem;
	background: var(--color-main-background);
}

.settings-card h2 {
	margin: 0 0 1rem;
	font-size: 1.1rem;
}

.form-group label {
	display: block;
	font-size: 0.9rem;
	margin-bottom: 0.35rem;
}

.form-input {
	width: 100%;
	padding: 0.65rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
}

.form-actions {
	margin-top: 0.8rem;
}

.inline-form {
	display: grid;
	grid-template-columns: 1fr auto;
	gap: 0.5rem;
	margin-bottom: 0.75rem;
}

.room-form-grid {
	grid-template-columns: 1fr 1fr auto;
}

.primary-button,
.danger-button {
	border: none;
	border-radius: 6px;
	padding: 0.6rem 0.9rem;
	cursor: pointer;
}

.primary-button {
	background: var(--color-primary);
	color: #fff;
}

.primary-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.danger-zone {
	border-color: #e67e22;
}

.danger-button {
	background: #e67e22;
	color: #fff;
}

.stat-list {
	list-style: none;
	padding: 0;
	margin: 0;
	display: grid;
	gap: 0.5rem;
}

.stat-list li {
	display: flex;
	justify-content: space-between;
	color: var(--color-text-maxcontrast);
}

.entity-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: grid;
	gap: 0.4rem;
	max-height: 220px;
	overflow: auto;
}

.entity-list li {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.45rem 0.55rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
}

.ghost-danger {
	border: 1px solid #e2b4b4;
	background: #fff;
	color: #b03a2e;
	border-radius: 6px;
	padding: 0.3rem 0.55rem;
	cursor: pointer;
}

.ghost-button {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-text-maxcontrast);
	border-radius: 6px;
	padding: 0.3rem 0.55rem;
	cursor: pointer;
	font-size: 0.9rem;
	line-height: 1;
}

.ghost-button:hover:not(:disabled) {
	background: var(--color-background-hover);
}

.ghost-button:disabled {
	opacity: 0.3;
	cursor: not-allowed;
}

/* Sortable list styles */
.sortable-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: grid;
	gap: 0.4rem;
	max-height: 300px;
	overflow: auto;
}

.sortable-item {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.45rem 0.55rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	cursor: grab;
	transition: background-color 0.15s ease;
}

.sortable-item:hover {
	background: var(--color-background-hover);
}

.sortable-item[draggable="true"]:active {
	cursor: grabbing;
}

.sortable-item.dragging {
	opacity: 0.5;
	border-color: var(--color-primary);
	background: var(--color-primary-light);
}

.drag-handle {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	user-select: none;
	cursor: grab;
}

.item-name {
	flex: 1;
	font-weight: 500;
}

.item-actions {
	display: flex;
	gap: 0.25rem;
	align-items: center;
}

.error-message,
.success-message {
	margin-top: 1rem;
	padding: 0.6rem 0.8rem;
	border-radius: 6px;
}

.error-message {
	background: #fdeeee;
	color: #b03a2e;
}

.success-message {
	background: #eafaf1;
	color: #1e8449;
}

.loading-state {
	display: grid;
	justify-items: center;
	padding: 2rem 0;
}

.loading-spinner {
	width: 28px;
	height: 28px;
	border: 3px solid var(--color-border);
	border-top-color: var(--color-primary);
	border-radius: 50%;
	animation: spin 1s linear infinite;
}

.room-item {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.45rem 0.55rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
}

.room-name {
	flex: 1;
	font-weight: 500;
}

.room-floor {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
	padding: 0.2rem 0.5rem;
	border-radius: 4px;
}

/* Modal styles */
.modal-overlay {
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 1000;
}

.modal-content {
	background: var(--color-main-background);
	border-radius: 10px;
	padding: 1.5rem;
	min-width: 320px;
	max-width: 90vw;
	box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
}

.modal-content h3 {
	margin: 0 0 1rem;
	font-size: 1.2rem;
}

.cancel-button,
.submit-button {
	padding: 0.5rem 1rem;
	border-radius: 6px;
	font-size: 0.9rem;
	cursor: pointer;
	border: none;
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
	color: white;
}

.submit-button:hover:not(:disabled) {
	filter: brightness(1.1);
}

.submit-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

@media (max-width: 768px) {
	.settings {
		padding: 1rem;
	}

	.room-form-grid {
		grid-template-columns: 1fr;
	}

	.modal-content {
		min-width: auto;
		margin: 1rem;
	}

	.room-item {
		flex-wrap: wrap;
	}
}

@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}
</style>
