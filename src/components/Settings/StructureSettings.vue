<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="structure-settings cs-settings-form">
		<div class="setting-card">
			<!-- A failed read is its own state with a retry, never an endless
			     spinner (CS-PLAN-066 §3.5). -->
			<ErrorState
				v-if="loadError"
				data-testid="structure-error"
				:message="t('chorestrike', 'Could not load the floors and rooms')"
				@retry="load" />
			<NcLoadingIcon v-else-if="!structureReady" data-testid="structure-loading" />
			<div v-else class="floors-section">
				<div class="floors-header">
					<h3>{{ t('chorestrike', 'Manage Floors') }}</h3>
					<button class="add-button" data-testid="add-floor-button" @click="showAddFloorModal = true">
						<Icon name="Plus" :size="16" />
						{{ t('chorestrike', 'Add Floor') }}
					</button>
				</div>

				<div v-if="orderedFloors.length === 0" class="empty-state" data-testid="no-floors-message">
					<p>{{ t('chorestrike', 'No floors created yet') }}</p>
					<SetupWizardEntry ready />
					<button class="primary-button" data-testid="create-first-floor" @click="showAddFloorModal = true">
						{{ t('chorestrike', 'Create First Floor') }}
					</button>
				</div>

				<div v-else class="floors-list" data-testid="floors-list">
					<div
						v-for="(floor, index) in orderedFloors"
						:key="floor.id"
						:data-testid="`floor-${floor.name}`"
						class="floor-item">
						<div class="floor-header-row">
							<div class="floor-info">
								<Icon name="Building" :size="18" />
								<span class="floor-name">{{ floor.name }}</span>
								<span class="room-count">
									{{ getRoomCount(floor.id) }} {{ t('chorestrike', 'rooms') }}
								</span>
							</div>
							<div class="floor-actions">
								<button
									class="icon-button"
									:data-testid="`expand-floor-${floor.id}`"
									:title="isFloorExpanded(floor.id) ? t('chorestrike', 'Hide rooms') : t('chorestrike', 'Show rooms')"
									:aria-expanded="isFloorExpanded(floor.id) ? 'true' : 'false'"
									@click="toggleFloorExpanded(floor.id)">
									<ExpandChevron :open="isFloorExpanded(floor.id)" />
								</button>
								<button
									class="icon-button"
									:data-testid="`move-floor-up-${floor.id}`"
									:title="t('chorestrike', 'Move floor up')"
									:disabled="index === 0 || roomsStore.loading"
									@click="moveFloor(floor, -1)">
									<Icon name="ArrowUp" :size="16" />
								</button>
								<button
									class="icon-button"
									:data-testid="`move-floor-down-${floor.id}`"
									:title="t('chorestrike', 'Move floor down')"
									:disabled="index === orderedFloors.length - 1 || roomsStore.loading"
									@click="moveFloor(floor, 1)">
									<Icon name="ArrowDown" :size="16" />
								</button>
								<button
									class="icon-button"
									:title="t('chorestrike', 'Edit floor')"
									@click="editFloor(floor)">
									<Icon name="Edit" :size="16" />
								</button>
								<button
									class="icon-button danger"
									:title="t('chorestrike', 'Delete floor')"
									@click="deleteFloor(floor)">
									<Icon name="Trash" :size="16" />
								</button>
							</div>
						</div>

						<!-- Room list of this floor (sole CRUD surface, UX-004) -->
						<div v-if="isFloorExpanded(floor.id)" class="floor-rooms" :data-testid="`floor-rooms-${floor.id}`">
							<div v-for="room in roomsStore.roomsByFloor[floor.id] || []" :key="room.id" class="room-row">
								<span class="room-row-name">{{ room.name }}</span>
								<span
									v-if="roomTaskCountsLoaded"
									class="room-row-count"
									:title="t('chorestrike', 'Tasks you can see in this room')"
									:data-testid="`room-task-count-${room.id}`">
									{{ roomTaskCountLabel(room.id) }}
								</span>
								<button
									class="icon-button"
									:data-testid="`rename-room-${room.id}`"
									:aria-label="t('chorestrike', 'Rename room')"
									:title="t('chorestrike', 'Rename room')"
									@click="roomToRename = room">
									<Icon name="Edit" :size="16" />
								</button>
								<button
									class="icon-button danger"
									:data-testid="`delete-room-${room.id}`"
									:title="t('chorestrike', 'Delete room')"
									@click="roomToDelete = room">
									<Icon name="Trash" :size="16" />
								</button>
							</div>
							<p v-if="(roomsStore.roomsByFloor[floor.id] || []).length === 0" class="no-rooms-hint">
								{{ t('chorestrike', 'No rooms on this floor yet.') }}
							</p>
							<div class="add-room-inline">
								<input
									v-model="newRoomNames[floor.id]"
									type="text"
									class="text-input"
									data-testid="new-room-name-input"
									:placeholder="t('chorestrike', 'New room name')"
									@keyup.enter="createRoomForFloor(floor)">
								<button
									class="add-button"
									data-testid="add-room-to-floor-button"
									:disabled="!(newRoomNames[floor.id] || '').trim() || roomsStore.loading"
									@click="createRoomForFloor(floor)">
									{{ t('chorestrike', 'Add Room') }}
								</button>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<RoomRenameDialog
			v-if="roomToRename !== null"
			:room="roomToRename"
			@close="roomToRename = null" />

		<!-- Add Floor Modal -->
		<Modal v-if="showAddFloorModal"
			persistent
			:aria-label="t('chorestrike', 'Add New Floor')"
			@close="showAddFloorModal = false">
			<div class="modal-content cs-settings-form">
				<h3>{{ t('chorestrike', 'Add New Floor') }}</h3>
				<div class="form-group">
					<label for="new-floor-name">{{ t('chorestrike', 'Floor Name') }}</label>
					<input
						id="new-floor-name"
						v-model="newFloorName"
						type="text"
						class="text-input"
						:placeholder="t('chorestrike', 'e.g., Ground Floor')"
						@keyup.enter="createFloor">
				</div>
				<div class="modal-actions cs-dialog-actions">
					<button class="secondary-button" @click="showAddFloorModal = false">
						{{ t('chorestrike', 'Cancel') }}
					</button>
					<button
						class="primary-button"
						:disabled="!newFloorName.trim() || roomsStore.loading"
						@click="createFloor">
						{{ t('chorestrike', 'Create') }}
					</button>
				</div>
			</div>
		</Modal>

		<!-- Edit Floor Modal -->
		<Modal v-if="showEditFloorModal"
			persistent
			:aria-label="t('chorestrike', 'Edit Floor')"
			@close="showEditFloorModal = false">
			<div class="modal-content cs-settings-form">
				<h3>{{ t('chorestrike', 'Edit Floor') }}</h3>
				<div class="form-group">
					<label for="edit-floor-name">{{ t('chorestrike', 'Floor Name') }}</label>
					<input
						id="edit-floor-name"
						v-model="editingFloorName"
						type="text"
						class="text-input"
						@keyup.enter="updateFloor">
				</div>
				<div class="modal-actions cs-dialog-actions">
					<button class="secondary-button" @click="showEditFloorModal = false">
						{{ t('chorestrike', 'Cancel') }}
					</button>
					<button
						class="primary-button"
						:disabled="!editingFloorName.trim() || roomsStore.loading"
						@click="updateFloor">
						{{ t('chorestrike', 'Save') }}
					</button>
				</div>
			</div>
		</Modal>

		<!-- Delete Floor Confirmation -->
		<ConfirmDialog
			v-if="floorToDelete !== null"
			:title="deleteFloorTitle"
			:description="t('chorestrike', 'All rooms and tasks on this floor will be deleted.')"
			:loading="deletingFloor"
			@confirm="confirmDeleteFloor"
			@cancel="floorToDelete = null" />

		<!-- Delete Room Confirmation (sole structure surface, UX-004) -->
		<ConfirmDialog
			v-if="roomToDelete !== null"
			:title="deleteRoomTitle"
			:description="t('chorestrike', 'All tasks in this room will be deleted.')"
			:loading="deletingRoom"
			@confirm="confirmDeleteRoom"
			@cancel="roomToDelete = null" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon } from '@nextcloud/vue'
import ConfirmDialog from '../Common/ConfirmDialog.vue'
import ErrorState from '../Common/ErrorState.vue'
import ExpandChevron from '../Common/ExpandChevron.vue'
import Icon from '../Common/Icon.vue'
import Modal from '../Common/Modal.vue'
import RoomRenameDialog from '../Common/RoomRenameDialog.vue'
import SetupWizardEntry from '../Setup/SetupWizardEntry.vue'
import { useRoomsStore } from '../../store/rooms.js'
import { useRoomTasksStore } from '../../store/roomTasks.js'
import { logger } from '../../utils/logger.js'

/**
 * Floors and rooms (household settings, owner only): the single surface
 * that edits the house structure (CS-PLAN-042). Reads the structure when it
 * opens and shows no cached controls before that read has answered.
 */
export default {
	name: 'StructureSettings',

	components: {
		NcLoadingIcon,
		ConfirmDialog,
		ErrorState,
		ExpandChevron,
		Icon,
		Modal,
		RoomRenameDialog,
		SetupWizardEntry,
	},

	setup() {
		return { roomsStore: useRoomsStore(), roomTasksStore: useRoomTasksStore() }
	},

	data() {
		return {
			structureReady: false,
			loadError: false,
			showAddFloorModal: false,
			showEditFloorModal: false,
			newFloorName: '',
			editingFloor: null,
			editingFloorName: '',
			floorToDelete: null,
			deletingFloor: false,
			expandedFloorIds: [],
			newRoomNames: {},
			roomToDelete: null,
			roomToRename: null,
			// FEAT-UX-049: read once, on the first expanded floor. An unknown
			// count stays hidden, never a misleading 0.
			roomTaskCountsLoaded: false,
			roomTaskCountsRequested: false,
			deletingRoom: false,
		}
	},

	computed: {
		/**
		 * Floors in persisted order — the reorder buttons operate on this.
		 */
		orderedFloors() {
			return [...this.roomsStore.floors].sort((a, b) => a.sortOrder - b.sortOrder)
		},

		deleteRoomTitle() {
			if (!this.roomToDelete) {
				return ''
			}
			return t('chorestrike', 'Delete "{name}"?', { name: this.roomToDelete.name })
		},

		deleteFloorTitle() {
			const floor = this.floorToDelete
			if (!floor) {
				return ''
			}
			return t('chorestrike', 'Delete "{name}"?', { name: floor.name })
		},
	},

	mounted() {
		return this.load()
	},

	methods: {
		t,

		async load() {
			this.loadError = false
			try {
				// A read of a cancelled reset generation answers null: not ready.
				this.structureReady = (await this.roomsStore.fetchFloorsWithRooms()) !== null
			} catch (error) {
				this.loadError = true
				logger.error('Failed to load the house structure', error, { component: 'StructureSettings', action: 'load' })
			}
		},

		getRoomCount(floorId) {
			return this.roomsStore.roomsByFloor[floorId]?.length || 0
		},

		isFloorExpanded(floorId) {
			return this.expandedFloorIds.includes(floorId)
		},

		toggleFloorExpanded(floorId) {
			this.expandedFloorIds = this.isFloorExpanded(floorId)
				? this.expandedFloorIds.filter((id) => id !== floorId)
				: [...this.expandedFloorIds, floorId]
			this.loadRoomTaskCounts()
		},

		async loadRoomTaskCounts() {
			if (this.roomTaskCountsRequested) {
				return
			}
			this.roomTaskCountsRequested = true
			try {
				if (!this.roomTasksStore.houseLoaded || this.roomTasksStore.houseStale) {
					await this.roomTasksStore.fetchHouseRoomTasks()
				}
				this.roomTaskCountsLoaded = true
			} catch {
				// The store has logged it; the next expanded floor tries again.
				this.roomTaskCountsRequested = false
			}
		},

		roomTaskCountLabel(roomId) {
			const count = (this.roomTasksStore.roomTasksByRoom[roomId] || []).filter((task) => task.isActive).length
			return count === 1 ? t('chorestrike', '1 task') : t('chorestrike', '{count} tasks', { count })
		},

		/**
		 * Create a room on the given floor from the inline input (UX-004:
		 * room creation moved here from the read-only Dashboard FloorList).
		 *
		 * @param {object} floor Floor receiving the new room.
		 */
		async createRoomForFloor(floor) {
			const name = (this.newRoomNames[floor.id] || '').trim()
			if (!name) {
				return
			}
			try {
				const room = await this.roomsStore.createRoom(floor.id, name)
				// FEAT-UX-044: a taken name comes back numbered.
				showSuccess(room && room.name !== name
					? t('chorestrike', 'Name already taken — room created as "{name}"', { name: room.name })
					: t('chorestrike', 'Room created'))
				this.newRoomNames[floor.id] = ''
			} catch (error) {
				showError(t('chorestrike', 'Failed to create room'))
				logger.error('Failed to create room', error, { component: 'StructureSettings', action: 'createRoomForFloor', floorId: floor.id })
			}
		},

		async confirmDeleteRoom() {
			const room = this.roomToDelete
			if (!room) {
				return
			}
			this.deletingRoom = true
			try {
				await this.roomsStore.deleteRoom(room.id)
				showSuccess(t('chorestrike', 'Room deleted'))
				this.roomToDelete = null
			} catch (error) {
				showError(t('chorestrike', 'Failed to delete room'))
				logger.error('Failed to delete room', error, { component: 'StructureSettings', action: 'confirmDeleteRoom', roomId: room.id })
			} finally {
				this.deletingRoom = false
			}
		},

		async createFloor() {
			if (!this.newFloorName.trim()) {
				return
			}
			try {
				await this.roomsStore.createFloor(this.newFloorName.trim())
				showSuccess(t('chorestrike', 'Floor created'))
				this.newFloorName = ''
				this.showAddFloorModal = false
			} catch (error) {
				showError(t('chorestrike', 'Failed to create floor'))
				logger.error('Failed to create floor', error, { component: 'StructureSettings', action: 'createFloor' })
			}
		},

		editFloor(floor) {
			this.editingFloor = floor
			this.editingFloorName = floor.name
			this.showEditFloorModal = true
		},

		/**
		 * Move a floor one position up/down (CS-PLAN-042, UX-004): swaps the
		 * floor with its neighbour and persists the full new order via
		 * POST /floors/reorder.
		 *
		 * @param {object} floor Floor to move.
		 * @param {number} direction -1 (up) or 1 (down).
		 */
		async moveFloor(floor, direction) {
			const ordered = this.orderedFloors
			const index = ordered.findIndex((f) => f.id === floor.id)
			const target = index + direction
			if (index < 0 || target < 0 || target >= ordered.length) {
				return
			}

			const next = [...ordered]
			const moved = next[index]
			next[index] = next[target]
			next[target] = moved

			try {
				await this.roomsStore.reorderFloors(next.map((f) => f.id))
				showSuccess(t('chorestrike', 'Floor order saved'))
			} catch (error) {
				showError(t('chorestrike', 'Failed to update floor'))
				logger.error('Failed to reorder floors', error, { component: 'StructureSettings', action: 'moveFloor', floorId: floor.id })
			}
		},

		async updateFloor() {
			if (!this.editingFloor || !this.editingFloorName.trim()) {
				return
			}
			try {
				await this.roomsStore.updateFloorName(this.editingFloor.id, this.editingFloorName.trim())
				showSuccess(t('chorestrike', 'Floor renamed'))
				this.editingFloor = null
				this.editingFloorName = ''
				this.showEditFloorModal = false
			} catch (error) {
				showError(t('chorestrike', 'Failed to update floor'))
				logger.error('Failed to update floor', error, { component: 'StructureSettings', action: 'updateFloor', floorId: this.editingFloor?.id })
			}
		},

		deleteFloor(floor) {
			this.floorToDelete = floor
		},

		async confirmDeleteFloor() {
			const floor = this.floorToDelete
			if (!floor) {
				return
			}
			this.deletingFloor = true
			try {
				await this.roomsStore.deleteFloor(floor.id)
				showSuccess(t('chorestrike', 'Floor deleted'))
				this.floorToDelete = null
			} catch (error) {
				showError(t('chorestrike', 'Failed to delete floor'))
				logger.error('Failed to delete floor', error, { component: 'StructureSettings', action: 'confirmDeleteFloor', floorId: floor.id })
			} finally {
				this.deletingFloor = false
			}
		},
	},
}
</script>

<style scoped>
.floors-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 1rem;
}

.floors-header h3 {
	margin: 0;
	font-size: 1.1rem;
}

.add-button {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.5rem 0.75rem;
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.85rem;
	font-weight: 500;
}

.empty-state {
	text-align: center;
	padding: 2rem;
	color: var(--color-text-maxcontrast);
}

.empty-state p {
	margin: 0 0 1rem 0;
}

.floors-list {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.floor-item {
	background: var(--color-main-background);
	border-radius: 8px;
	padding: 1rem;
}

.floor-header-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	flex-wrap: wrap;
	gap: 0.75rem;
}

.floor-info {
	display: flex;
	align-items: center;
	gap: 0.75rem;
}

.floor-name {
	font-weight: 500;
}

.floor-rooms {
	margin-top: 0.75rem;
	padding: 0.5rem 0.75rem 0.75rem;
	border-top: 1px solid var(--color-border);
	display: flex;
	flex-direction: column;
	gap: 0.4rem;
}

.room-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 0.5rem;
	padding: 0.25rem 0.25rem 0.25rem 0.5rem;
	border-radius: 6px;
}

.room-row:hover {
	background: var(--color-background-hover);
}

.room-row-name {
	flex: 1;
	min-width: 0;
	font-size: 0.9rem;
	color: var(--color-main-text);
}

.room-row-count {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.no-rooms-hint {
	margin: 0.25rem 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.add-room-inline {
	display: flex;
	gap: 0.5rem;
	margin-top: 0.4rem;
}

.add-room-inline .text-input {
	flex: 1;
	padding: 0.4rem 0.6rem;
	font-size: 0.9rem;
}

.room-count {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.floor-actions {
	display: flex;
	gap: 0.5rem;
	flex-wrap: wrap;
}
</style>
