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

			<!-- House Settings -->
			<section class="settings-section">
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
				</div>
			</section>

			<!-- Floors & Rooms Management -->
			<section class="settings-section">
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

						<div v-if="roomsStore.floors.length === 0" class="empty-state" data-testid="no-floors-message">
							<p>{{ t('chorestrike', 'No floors created yet') }}</p>
							<button class="primary-button" data-testid="create-first-floor" @click="showAddFloorModal = true">
								{{ t('chorestrike', 'Create First Floor') }}
							</button>
						</div>

						<div v-else class="floors-list" data-testid="floors-list">
							<div
								v-for="floor in roomsStore.floors"
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
							</div>
						</div>
					</div>
				</div>
			</section>

			<!-- Danger Zone -->
			<section class="settings-section danger-zone">
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
		<Modal v-if="showAddFloorModal" @close="showAddFloorModal = false">
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
		<Modal v-if="showEditFloorModal" @close="showEditFloorModal = false">
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
		<Modal v-if="showResetModal" @close="showResetModal = false">
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
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { useHouseStore } from '../store/house.js'
import { useRoomsStore } from '../store/rooms.js'
import { useUserStore } from '../store/user.js'
import { useTitlesStore } from '../store/titles.js'
import { logger } from '../utils/logger.js'
import Icon from '../components/Common/Icon.vue'
import Modal from '../components/Common/Modal.vue'
import TitleBadge from '../components/Profile/TitleBadge.vue'
import TitlePicker from '../components/Profile/TitlePicker.vue'

export default {
	name: 'Settings',

	components: {
		Icon,
		Modal,
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
			showAddFloorModal: false,
			showEditFloorModal: false,
			showResetModal: false,
			showTitlePicker: false,
			newFloorName: '',
			editingFloor: null,
			editingFloorName: '',
			resetConfirmation: '',
		}
	},

	computed: {
		canSaveHouseName() {
			return this.houseName.trim() && this.houseName !== this.houseStore.houseName
		},

		currentTitle() {
			return this.titlesStore.currentTitle
		},

		houseUserId() {
			return this.userStore.userId
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

		async saveHouseName() {
			if (!this.canSaveHouseName) return
			try {
				await this.houseStore.updateHouseName(this.houseName.trim())
			} catch (error) {
				logger.error('Failed to save house name', error, { view: 'Settings', action: 'saveHouseName' })
			}
		},

		async createFloor() {
			if (!this.newFloorName.trim()) return
			try {
				await this.roomsStore.createFloor(this.newFloorName.trim())
				this.newFloorName = ''
				this.showAddFloorModal = false
			} catch (error) {
				logger.error('Failed to create floor', error, { view: 'Settings', action: 'createFloor' })
			}
		},

		editFloor(floor) {
			this.editingFloor = floor
			this.editingFloorName = floor.name
			this.showEditFloorModal = true
		},

		async updateFloor() {
			if (!this.editingFloor || !this.editingFloorName.trim()) return
			try {
				await this.roomsStore.updateFloorName(this.editingFloor.id, this.editingFloorName.trim())
				this.editingFloor = null
				this.editingFloorName = ''
				this.showEditFloorModal = false
			} catch (error) {
				logger.error('Failed to update floor', error, { view: 'Settings', action: 'updateFloor', floorId: this.editingFloor?.id })
			}
		},

		async deleteFloor(floor) {
			const roomCount = this.getRoomCount(floor.id)
			const confirmMessage = roomCount > 0
				? t('chorestrike', 'This floor has {count} rooms. Deleting it will also delete all rooms. Are you sure?', { count: roomCount })
				: t('chorestrike', 'Are you sure you want to delete "{name}"?', { name: floor.name })

			if (!confirm(confirmMessage)) return

			try {
				await this.roomsStore.deleteFloor(floor.id)
			} catch (error) {
				logger.error('Failed to delete floor', error, { view: 'Settings', action: 'deleteFloor', floorId: floor.id })
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
				this.resetConfirmation = ''
				this.showResetModal = false
				await this.initializeSettings()
			} catch (error) {
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
	text-align: left;
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
