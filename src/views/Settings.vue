<template>
	<div class="settings">
		<div class="settings-header">
			<h1>{{ t('chorewars', 'House Settings') }}</h1>
			<p>{{ t('chorewars', 'Configure your shared home base.') }}</p>
		</div>

		<div v-if="loading" class="loading-state">
			<div class="loading-spinner" />
			<p>{{ t('chorewars', 'Loading settings...') }}</p>
		</div>

		<div v-else class="settings-grid">
			<section class="settings-card">
				<h2>{{ t('chorewars', 'General') }}</h2>
				<div class="form-group">
					<label for="house-name">{{ t('chorewars', 'House Name') }}</label>
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
						{{ t('chorewars', 'Save Name') }}
					</button>
				</div>
			</section>

			<section class="settings-card">
				<h2>{{ t('chorewars', 'Overview') }}</h2>
				<ul class="stat-list">
					<li><span>{{ t('chorewars', 'House Health') }}</span><strong>{{ houseHealth }}%</strong></li>
					<li><span>{{ t('chorewars', 'Floors') }}</span><strong>{{ floorsCount }}</strong></li>
					<li><span>{{ t('chorewars', 'Rooms') }}</span><strong>{{ roomsCount }}</strong></li>
					<li><span>{{ t('chorewars', 'Average Cleanliness') }}</span><strong>{{ averageCleanliness }}%</strong></li>
				</ul>
			</section>

			<section class="settings-card danger-zone">
				<h2>{{ t('chorewars', 'Maintenance') }}</h2>
				<p>{{ t('chorewars', 'Reset the house to an initial clean state.') }}</p>
				<button class="danger-button" @click="resetHouse">
					{{ t('chorewars', 'Reset House') }}
				</button>
			</section>

			<section class="settings-card">
				<h2>{{ t('chorewars', 'Floors') }}</h2>
				<div class="inline-form">
					<input
						v-model.trim="newFloorName"
						type="text"
						class="form-input"
						:placeholder="t('chorewars', 'New floor name')">
					<button class="primary-button" :disabled="!newFloorName" @click="createFloor">
						{{ t('chorewars', 'Add') }}
					</button>
				</div>
				<ul class="entity-list">
					<li v-for="floor in floors" :key="floor.id">
						<span>{{ floor.name }}</span>
						<button class="ghost-danger" @click="deleteFloor(floor)">
							{{ t('chorewars', 'Delete') }}
						</button>
					</li>
				</ul>
			</section>

			<section class="settings-card">
				<h2>{{ t('chorewars', 'Rooms') }}</h2>
				<div class="inline-form room-form-grid">
					<select v-model.number="newRoomFloorId" class="form-input">
						<option :value="null" disabled>
							{{ t('chorewars', 'Select floor') }}
						</option>
						<option v-for="floor in floors" :key="floor.id" :value="floor.id">
							{{ floor.name }}
						</option>
					</select>
					<input
						v-model.trim="newRoomName"
						type="text"
						class="form-input"
						:placeholder="t('chorewars', 'New room name')">
					<button class="primary-button" :disabled="!canCreateRoom" @click="createRoom">
						{{ t('chorewars', 'Add') }}
					</button>
				</div>
				<ul class="entity-list">
					<li v-for="room in rooms" :key="room.id">
						<span>{{ room.name }}</span>
						<button class="ghost-danger" @click="deleteRoom(room)">
							{{ t('chorewars', 'Delete') }}
						</button>
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
				successMessage.value = t('chorewars', 'Floor created.')
				if (newRoomFloorId.value === null && roomsStore.floors.length > 0) {
					newRoomFloorId.value = roomsStore.floors[0].id
				}
			} catch (createError) {
				error.value = createError.message
			}
		}

		const deleteFloor = async (floor) => {
			if (!confirm(t('chorewars', 'Delete floor "{name}" and its rooms?', { name: floor.name }))) {
				return
			}
			error.value = ''
			successMessage.value = ''
			try {
				await roomsStore.deleteFloor(floor.id)
				successMessage.value = t('chorewars', 'Floor deleted.')
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
				successMessage.value = t('chorewars', 'Room created.')
			} catch (createError) {
				error.value = createError.message
			}
		}

		const deleteRoom = async (room) => {
			if (!confirm(t('chorewars', 'Delete room "{name}"?', { name: room.name }))) {
				return
			}
			error.value = ''
			successMessage.value = ''
			try {
				await roomsStore.deleteRoom(room.id)
				successMessage.value = t('chorewars', 'Room deleted.')
			} catch (deleteError) {
				error.value = deleteError.message
			}
		}

		const saveHouseName = async () => {
			error.value = ''
			successMessage.value = ''
			try {
				await houseStore.updateHouseName(houseNameInput.value)
				successMessage.value = t('chorewars', 'House name updated successfully.')
			} catch (saveError) {
				error.value = saveError.message
			}
		}

		const resetHouse = async () => {
			if (!confirm(t('chorewars', 'Reset house to initial state?'))) {
				return
			}
			error.value = ''
			successMessage.value = ''
			try {
				await houseStore.resetHouse()
				await roomsStore.fetchFloorsWithRooms()
				houseNameInput.value = houseStore.houseName
				successMessage.value = t('chorewars', 'House was reset successfully.')
			} catch (resetError) {
				error.value = resetError.message
			}
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
			houseHealth,
			floorsCount,
			floors,
			rooms,
			roomsCount,
			averageCleanliness,
			canCreateRoom,
			canSaveName,
			saveHouseName,
			resetHouse,
			createFloor,
			deleteFloor,
			createRoom,
			deleteRoom,
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

@media (max-width: 768px) {
	.settings {
		padding: 1rem;
	}

	.room-form-grid {
		grid-template-columns: 1fr;
	}
}

@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}
</style>
