<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="settings" data-testid="settings-view">
		<header class="settings-header">
			<h1 data-testid="settings-title">
				{{ t('chorestrike', 'Settings') }}
			</h1>
		</header>

		<div class="settings-content">
			<!-- Profile title -->
			<section class="settings-section">
				<h2>{{ t('chorestrike', 'Your Title') }}</h2>
				<div class="setting-card title-card">
					<div class="title-display">
						<TitleBadge
							v-if="currentTitle"
							:title="currentTitle"
							:unlocked="titlesStore.unlockedTitles" />
						<span v-else class="no-title" data-testid="no-title">
							{{ t('chorestrike', 'No title yet — pick a track to get started!') }}
						</span>
					</div>
					<button
						class="primary-button"
						data-testid="change-title-button"
						@click="openTitlePicker">
						<Icon name="Award" :size="16" />
						{{ t('chorestrike', 'Change title') }}
					</button>
				</div>
			</section>

			<!-- House Settings (owner only: PUT /house requires the owner role) -->
			<section v-if="userStore.isHouseOwner" class="settings-section" data-testid="house-section">
				<h2>{{ t('chorestrike', 'House') }}</h2>
				<div class="setting-card">
					<div class="form-group">
						<label for="house-name">{{ t('chorestrike', 'House Name') }}</label>
						<div class="input-group">
							<input
								id="house-name"
								v-model="houseName"
								type="text"
								class="text-input"
								data-testid="house-name-input"
								:placeholder="t('chorestrike', 'Enter house name')">
							<button
								class="save-button"
								data-testid="save-house-name"
								:disabled="!canSaveHouseName || houseStore.loading"
								@click="saveHouseName">
								<Icon name="Save" :size="16" />
								{{ t('chorestrike', 'Save') }}
							</button>
						</div>
					</div>
					<div class="form-group">
						<label for="house-timezone">{{ t('chorestrike', 'Timezone') }}</label>
						<p class="setting-hint">
							{{ t('chorestrike', 'Calendar days, streaks and weekly goals are counted by this timezone.') }}
						</p>
						<div class="input-group">
							<select
								id="house-timezone"
								v-model="houseTimezone"
								class="text-input"
								data-testid="house-timezone-select"
								:disabled="houseStore.loading">
								<option v-for="zone in curatedTimezones" :key="zone" :value="zone">
									{{ zone }}
								</option>
							</select>
							<button
								class="save-button"
								data-testid="save-house-timezone"
								:disabled="!canSaveHouseTimezone || houseStore.loading"
								@click="saveHouseTimezone">
								<Icon name="Save" :size="16" />
								{{ t('chorestrike', 'Save') }}
							</button>
						</div>
					</div>
				</div>
			</section>

			<!-- House Members (owner only) -->
			<section v-if="userStore.isHouseOwner" class="settings-section" data-testid="members-section">
				<h2>{{ t('chorestrike', 'Members') }}</h2>
				<div class="setting-card">
					<MemberList />
				</div>
			</section>

			<!-- Floors & Rooms Management (owner only — the single structure
			     editing surface; the Dashboard FloorList is read-only) -->
			<section v-if="userStore.isHouseOwner" class="settings-section" data-testid="floors-section">
				<h2>{{ t('chorestrike', 'Floors & Rooms') }}</h2>
				<div class="setting-card">
					<div class="floors-section">
						<div class="floors-header">
							<h3>{{ t('chorestrike', 'Manage Floors') }}</h3>
							<button class="add-button" data-testid="add-floor-button" @click="showAddFloorModal = true">
								<Icon name="Plus" :size="16" />
								{{ t('chorestrike', 'Add Floor') }}
							</button>
						</div>

						<div v-if="orderedFloors.length === 0" class="empty-state" data-testid="no-floors-message">
							<p>{{ t('chorestrike', 'No floors created yet') }}</p>
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
											@click="toggleFloorExpanded(floor.id)">
											<Icon :name="isFloorExpanded(floor.id) ? 'ChevronDown' : 'ChevronRight'" :size="16" />
										</button>
										<button
											class="icon-button"
											:data-testid="`move-floor-up-${floor.id}`"
											:title="t('chorestrike', 'Move floor up')"
											:disabled="index === 0 || roomsStore.loading"
											@click="moveFloor(floor, -1)">
											<Icon name="ChevronUp" :size="16" />
										</button>
										<button
											class="icon-button"
											:data-testid="`move-floor-down-${floor.id}`"
											:title="t('chorestrike', 'Move floor down')"
											:disabled="index === orderedFloors.length - 1 || roomsStore.loading"
											@click="moveFloor(floor, 1)">
											<Icon name="ChevronDown" :size="16" />
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
			</section>

			<!-- Danger Zone (owner only) -->
			<section v-if="userStore.isHouseOwner" class="settings-section danger-zone" data-testid="danger-zone">
				<h2>{{ t('chorestrike', 'Danger Zone') }}</h2>
				<div class="setting-card danger">
					<div class="danger-item">
						<div class="danger-info">
							<h3>{{ t('chorestrike', 'Reset House') }}</h3>
							<p>{{ t('chorestrike', 'Reset all progress and start fresh. This cannot be undone.') }}</p>
						</div>
						<button
							class="danger-button"
							:disabled="houseStore.loading"
							@click="confirmResetHouse">
							{{ t('chorestrike', 'Reset') }}
						</button>
					</div>
				</div>
			</section>
		</div>

		<!-- Add Floor Modal -->
		<Modal v-if="showAddFloorModal" :aria-label="t('chorestrike', 'Add New Floor')" @close="showAddFloorModal = false">
			<div class="modal-content">
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
				<div class="modal-actions">
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
		<Modal v-if="showEditFloorModal" :aria-label="t('chorestrike', 'Edit Floor')" @close="showEditFloorModal = false">
			<div class="modal-content">
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
				<div class="modal-actions">
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

		<!-- Reset Confirmation Modal -->
		<Modal v-if="showResetModal" :aria-label="t('chorestrike', 'Reset House?')" @close="showResetModal = false">
			<div class="modal-content danger-modal">
				<div class="warning-icon">
					<Icon name="AlertTriangle" :size="48" />
				</div>
				<h3>{{ t('chorestrike', 'Reset House?') }}</h3>
				<p>{{ t('chorestrike', 'This will delete all floors, rooms, tasks, and progress. This action cannot be undone.') }}</p>
				<div class="confirmation-input">
					<label>{{ t('chorestrike', 'Type "RESET" to confirm') }}</label>
					<input
						v-model="resetConfirmation"
						type="text"
						class="text-input"
						:placeholder="t('chorestrike', 'RESET')">
				</div>
				<div class="modal-actions">
					<button class="secondary-button" @click="showResetModal = false">
						{{ t('chorestrike', 'Cancel') }}
					</button>
					<button
						class="danger-button"
						:disabled="resetConfirmation !== 'RESET' || houseStore.loading"
						@click="resetHouse">
						{{ t('chorestrike', 'Reset House') }}
					</button>
				</div>
			</div>
		</Modal>

		<!-- Title Picker Modal -->
		<TitlePicker
			:show="showTitlePicker"
			:house-user-id="houseUserId"
			@close="showTitlePicker = false"
			@selected="onTitleSelected" />

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
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { useHouseStore } from '../store/house.js'
import { useRoomsStore } from '../store/rooms.js'
import { useUserStore } from '../store/user.js'
import { useTitlesStore } from '../store/titles.js'
import { CURATED_TIMEZONES } from '../constants/timezones.js'
import { logger } from '../utils/logger.js'
import Icon from '../components/Common/Icon.vue'
import Modal from '../components/Common/Modal.vue'
import ConfirmDialog from '../components/Common/ConfirmDialog.vue'
import MemberList from '../components/Members/MemberList.vue'
import TitleBadge from '../components/Profile/TitleBadge.vue'
import TitlePicker from '../components/Profile/TitlePicker.vue'

export default {
	name: 'Settings',

	components: {
		Icon,
		Modal,
		ConfirmDialog,
		MemberList,
		TitleBadge,
		TitlePicker,
	},

	setup() {
		const houseStore = useHouseStore()
		const roomsStore = useRoomsStore()
		const userStore = useUserStore()
		const titlesStore = useTitlesStore()
		return { houseStore, roomsStore, userStore, titlesStore }
	},

	data() {
		return {
			houseName: '',
			houseTimezone: 'UTC',
			showAddFloorModal: false,
			showEditFloorModal: false,
			showResetModal: false,
			showTitlePicker: false,
			newFloorName: '',
			editingFloor: null,
			editingFloorName: '',
			resetConfirmation: '',
			floorToDelete: null,
			deletingFloor: false,
			expandedFloorIds: [],
			newRoomNames: {},
			roomToDelete: null,
			deletingRoom: false,
		}
	},

	computed: {
		canSaveHouseName() {
			return this.houseName.trim() && this.houseName !== this.houseStore.houseName
		},

		canSaveHouseTimezone() {
			return this.houseTimezone && this.houseTimezone !== this.houseStore.timezone
		},

		curatedTimezones() {
			return CURATED_TIMEZONES
		},

		/**
		 * Floors in persisted order — the reorder buttons operate on this.
		 */
		orderedFloors() {
			return [...this.roomsStore.floors].sort((a, b) => a.sortOrder - b.sortOrder)
		},

		deleteRoomTitle() {
			if (!this.roomToDelete) return ''
			return t('chorestrike', 'Delete "{name}"?', { name: this.roomToDelete.name })
		},

		currentTitle() {
			return this.titlesStore.currentTitle
		},

		houseUserId() {
			return this.userStore.userId
		},

		deleteFloorTitle() {
			const floor = this.floorToDelete
			if (!floor) return ''
			return t('chorestrike', 'Delete "{name}"?', { name: floor.name })
		},
	},

	async mounted() {
		await this.initializeSettings()
	},

	methods: {
		t,

		async initializeSettings() {
			try {
				await this.houseStore.fetchHouse()
				await this.roomsStore.fetchFloorsWithRooms()
				this.houseName = this.houseStore.houseName
				this.houseTimezone = this.houseStore.timezone
				await this.loadTitle()
			} catch (error) {
				logger.error('Failed to initialize settings', error, { view: 'Settings', action: 'initializeSettings' })
			}
		},

		async loadTitle() {
			try {
				await this.userStore.initializeCurrentUser()
				if (this.houseUserId) {
					await this.titlesStore.fetchTitles(this.houseUserId)
				}
			} catch (error) {
				logger.error('Failed to load title', error, { view: 'Settings', action: 'loadTitle' })
			}
		},

		openTitlePicker() {
			this.showTitlePicker = true
		},

		onTitleSelected() {
			this.showTitlePicker = false
		},

		getRoomCount(floorId) {
			return this.roomsStore.roomsByFloor[floorId]?.length || 0
		},

		isFloorExpanded(floorId) {
			return this.expandedFloorIds.includes(floorId)
		},

		toggleFloorExpanded(floorId) {
			this.expandedFloorIds = this.isFloorExpanded(floorId)
				? this.expandedFloorIds.filter(id => id !== floorId)
				: [...this.expandedFloorIds, floorId]
		},

		/**
		 * Create a room on the given floor from the inline input (UX-004:
		 * room creation moved here from the read-only Dashboard FloorList).
		 *
		 * @param {object} floor Floor receiving the new room.
		 */
		async createRoomForFloor(floor) {
			const name = (this.newRoomNames[floor.id] || '').trim()
			if (!name) return
			try {
				await this.roomsStore.createRoom(floor.id, name)
				showSuccess(t('chorestrike', 'Room created'))
				this.newRoomNames[floor.id] = ''
			} catch (error) {
				showError(t('chorestrike', 'Failed to create room'))
				logger.error('Failed to create room', error, { view: 'Settings', action: 'createRoomForFloor', floorId: floor.id })
			}
		},

		async confirmDeleteRoom() {
			const room = this.roomToDelete
			if (!room) return
			this.deletingRoom = true
			try {
				await this.roomsStore.deleteRoom(room.id)
				showSuccess(t('chorestrike', 'Room deleted'))
				this.roomToDelete = null
			} catch (error) {
				showError(t('chorestrike', 'Failed to delete room'))
				logger.error('Failed to delete room', error, { view: 'Settings', action: 'confirmDeleteRoom', roomId: room.id })
			} finally {
				this.deletingRoom = false
			}
		},

		async saveHouseName() {
			if (!this.canSaveHouseName) return
			try {
				await this.houseStore.updateHouseName(this.houseName.trim())
				showSuccess(t('chorestrike', 'House name saved'))
			} catch (error) {
				showError(t('chorestrike', 'Failed to save house name'))
				logger.error('Failed to save house name', error, { view: 'Settings', action: 'saveHouseName' })
			}
		},

		async saveHouseTimezone() {
			if (!this.canSaveHouseTimezone) return
			try {
				// Name is passed unchanged so the owner-gated PUT keeps it intact.
				await this.houseStore.updateHouseName(this.houseStore.houseName, this.houseTimezone)
				showSuccess(t('chorestrike', 'Timezone saved'))
			} catch (error) {
				showError(t('chorestrike', 'Failed to save timezone'))
				logger.error('Failed to save timezone', error, { view: 'Settings', action: 'saveHouseTimezone' })
				// Re-sync the select with the persisted value.
				this.houseTimezone = this.houseStore.timezone
			}
		},

		async createFloor() {
			if (!this.newFloorName.trim()) return
			try {
				await this.roomsStore.createFloor(this.newFloorName.trim())
				showSuccess(t('chorestrike', 'Floor created'))
				this.newFloorName = ''
				this.showAddFloorModal = false
			} catch (error) {
				showError(t('chorestrike', 'Failed to create floor'))
				logger.error('Failed to create floor', error, { view: 'Settings', action: 'createFloor' })
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
			const index = ordered.findIndex(f => f.id === floor.id)
			const target = index + direction
			if (index < 0 || target < 0 || target >= ordered.length) return

			const next = [...ordered]
			const moved = next[index]
			next[index] = next[target]
			next[target] = moved

			try {
				await this.roomsStore.reorderFloors(next.map(f => f.id))
				showSuccess(t('chorestrike', 'Floor order saved'))
			} catch (error) {
				showError(t('chorestrike', 'Failed to update floor'))
				logger.error('Failed to reorder floors', error, { view: 'Settings', action: 'moveFloor', floorId: floor.id })
			}
		},

		async updateFloor() {
			if (!this.editingFloor || !this.editingFloorName.trim()) return
			try {
				await this.roomsStore.updateFloorName(this.editingFloor.id, this.editingFloorName.trim())
				showSuccess(t('chorestrike', 'Floor renamed'))
				this.editingFloor = null
				this.editingFloorName = ''
				this.showEditFloorModal = false
			} catch (error) {
				showError(t('chorestrike', 'Failed to update floor'))
				logger.error('Failed to update floor', error, { view: 'Settings', action: 'updateFloor', floorId: this.editingFloor?.id })
			}
		},

		deleteFloor(floor) {
			this.floorToDelete = floor
		},

		async confirmDeleteFloor() {
			const floor = this.floorToDelete
			if (!floor) return
			this.deletingFloor = true
			try {
				await this.roomsStore.deleteFloor(floor.id)
				showSuccess(t('chorestrike', 'Floor deleted'))
				this.floorToDelete = null
			} catch (error) {
				showError(t('chorestrike', 'Failed to delete floor'))
				logger.error('Failed to delete floor', error, { view: 'Settings', action: 'confirmDeleteFloor', floorId: floor.id })
			} finally {
				this.deletingFloor = false
			}
		},

		confirmResetHouse() {
			this.resetConfirmation = ''
			this.showResetModal = true
		},

		async resetHouse() {
			if (this.resetConfirmation !== 'RESET') return
			try {
				await this.houseStore.resetHouse()
				showSuccess(t('chorestrike', 'House was reset'))
				this.resetConfirmation = ''
				this.showResetModal = false
				await this.initializeSettings()
			} catch (error) {
				showError(t('chorestrike', 'Failed to reset house'))
				logger.error('Failed to reset house', error, { view: 'Settings', action: 'resetHouse' })
			}
		},
	},
}
</script>

<style scoped>
.settings {
	padding: 1rem 2rem 2rem;
	max-width: 1000px;
	margin: 0 auto;
}

.settings-header {
	margin-bottom: 2rem;
}

.settings-header h1 {
	font-size: 2rem;
	font-weight: bold;
	margin: 0;
}

.settings-content {
	display: flex;
	flex-direction: column;
	gap: 2rem;
}

.settings-section h2 {
	font-size: 1.1rem;
	color: var(--color-text-maxcontrast);
	margin: 0 0 1rem 0;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.setting-card {
	background: var(--color-background-dark);
	border-radius: 12px;
	padding: 1.5rem;
}

.title-card {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 1rem;
	flex-wrap: wrap;
}

.title-display {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.no-title {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.setting-card.danger {
	border: 1px solid var(--color-error);
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.form-group label {
	font-size: 0.9rem;
	font-weight: 500;
	color: var(--color-main-text);
}

.setting-hint {
	margin: 0;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.input-group {
	display: flex;
	gap: 0.75rem;
}

.text-input {
	flex: 1;
	padding: 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.95rem;
}

.text-input:focus {
	outline: none;
	border-color: var(--color-primary);
}

.save-button {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.75rem 1rem;
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.9rem;
	font-weight: 500;
	transition: opacity 0.2s;
}

.save-button:hover:not(:disabled) {
	opacity: 0.9;
}

.save-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

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
	font-size: 0.9rem;
	color: var(--color-main-text);
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
}

.icon-button {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	background: transparent;
	border: none;
	border-radius: 6px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	transition: all 0.2s;
}

.icon-button:hover {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.icon-button.danger:hover {
	color: var(--color-error);
}

.danger-zone h2 {
	color: var(--color-error);
}

.danger-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 1rem;
}

.danger-info h3 {
	margin: 0 0 0.25rem 0;
	font-size: 1rem;
	color: var(--color-error);
}

.danger-info p {
	margin: 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.danger-button {
	padding: 0.75rem 1rem;
	background: var(--color-error);
	color: white;
	border: none;
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.9rem;
	font-weight: 500;
	transition: opacity 0.2s;
}

.danger-button:hover:not(:disabled) {
	opacity: 0.9;
}

.danger-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.modal-content {
	padding: 1rem;
}

.modal-content h3 {
	margin: 0 0 1.5rem 0;
	font-size: 1.3rem;
}

.modal-content.danger-modal {
	text-align: center;
}

.warning-icon {
	color: var(--color-error);
	margin-bottom: 1rem;
}

.confirmation-input {
	margin: 1.5rem 0;
	text-align: start;
}

.confirmation-input label {
	display: block;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	margin-bottom: 0.5rem;
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.75rem;
	margin-top: 1.5rem;
}

.primary-button {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.75rem 1rem;
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.9rem;
	font-weight: 500;
}

.primary-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.secondary-button {
	padding: 0.75rem 1rem;
	background: transparent;
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.9rem;
}

@media (max-width: 768px) {
	.danger-item {
		flex-direction: column;
		align-items: flex-start;
	}

	.danger-item .danger-button {
		width: 100%;
	}
}
</style>
