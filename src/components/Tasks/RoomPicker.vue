<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="room-picker" data-testid="room-picker">
		<template v-if="isLocked">
			<p class="locked-room" data-testid="room-picker-locked">
				<Icon name="MapPin" :size="16" class="icon" />
				{{ lockedRoomLabel }}
			</p>
		</template>

		<template v-else>
			<div class="form-row">
				<div class="form-group half">
					<label for="room-picker-floor">{{ t('chorestrike', 'Floor') }}</label>
					<select id="room-picker-floor"
						v-model.number="selectedFloorId"
						class="form-input"
						data-testid="room-picker-floor">
						<option v-for="floor in floorsWithRooms"
							:key="floor.id"
							:value="floor.id">
							{{ floor.name }}
						</option>
					</select>
				</div>

				<div class="form-group half">
					<label for="room-picker-room">{{ t('chorestrike', 'Room') }} *</label>
					<select id="room-picker-room"
						v-model.number="selectedRoomId"
						class="form-input"
						data-testid="room-picker-room"
						required>
						<option :value="null" disabled>
							{{ t('chorestrike', 'Select a room') }}
						</option>
						<option v-for="room in roomsInFloor"
							:key="room.id"
							:value="room.id">
							{{ room.name }}
						</option>
					</select>
					<p v-if="roomsInFloor.length === 0" class="field-error">
						{{ t('chorestrike', 'No rooms in this floor yet') }}
					</p>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
import { computed, onMounted, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useRoomsStore } from '../../store/rooms.js'
import { logger } from '../../utils/logger.js'
import Icon from '../Common/Icon.vue'

export default {
	name: 'RoomPicker',

	components: {
		Icon,
	},

	props: {
		modelValue: {
			type: Number,
			default: null,
		},
		// When set, the room is fixed (e.g. creation from a room's context)
		// and the selects are replaced by a read-only label.
		presetRoomId: {
			type: Number,
			default: null,
		},
	},

	emits: ['update:modelValue'],

	setup(props, { emit }) {
		const roomsStore = useRoomsStore()
		const selectedFloorId = ref(null)
		const selectedRoomId = ref(props.modelValue ?? props.presetRoomId)
		const isLocked = computed(() => props.presetRoomId !== null)

		const floorsWithRooms = computed(() => {
			return roomsStore.floors.filter(floor =>
				(roomsStore.roomsByFloor[floor.id] || []).length > 0,
			)
		})

		const roomsInFloor = computed(() => {
			if (selectedFloorId.value === null) {
				return []
			}
			return roomsStore.roomsByFloor[selectedFloorId.value] || []
		})

		const lockedRoomLabel = computed(() => {
			const room = roomsStore.getRoomById(props.presetRoomId)
			if (!room) {
				return t('chorestrike', 'This room')
			}
			const floor = roomsStore.getFloorById(room.floorId)
			return floor ? `${room.name} · ${floor.name}` : room.name
		})

		// Keep the emitted room in sync with the selection
		watch(selectedRoomId, (roomId) => {
			emit('update:modelValue', roomId)
		})

		// Selecting a floor resets an incompatible room selection
		watch(selectedFloorId, (floorId) => {
			if (selectedRoomId.value === null) {
				return
			}
			const room = roomsStore.getRoomById(selectedRoomId.value)
			if (!room || room.floorId !== floorId) {
				selectedRoomId.value = null
			}
		})

		// Derive the floor from a restored/changed model value
		watch(() => props.modelValue, (roomId) => {
			if (roomId === selectedRoomId.value) {
				return
			}
			selectedRoomId.value = roomId
		})

		watch(selectedRoomId, (roomId) => {
			if (roomId === null) {
				return
			}
			const room = roomsStore.getRoomById(roomId)
			if (room && room.floorId !== selectedFloorId.value) {
				selectedFloorId.value = room.floorId
			}
		}, { immediate: true })

		onMounted(async () => {
			if (roomsStore.floors.length === 0 || roomsStore.rooms.length === 0) {
				try {
					await roomsStore.fetchFloorsWithRooms()
				} catch (error) {
					logger.error('Failed to load floors and rooms', error, { component: 'RoomPicker', action: 'mounted' })
				}
			}
			if (selectedFloorId.value === null && floorsWithRooms.value.length > 0) {
				selectedFloorId.value = floorsWithRooms.value[0].id
			}
		})

		return {
			isLocked,
			floorsWithRooms,
			roomsInFloor,
			selectedFloorId,
			selectedRoomId,
			lockedRoomLabel,
			t,
		}
	},
}
</script>

<style scoped>
.room-picker .form-row {
	display: flex;
	gap: 1rem;
}

.form-group {
	flex: 1;
	margin-bottom: 0.5rem;
}

.form-group label {
	display: block;
	margin-bottom: 0.4rem;
	font-size: 0.9rem;
	font-weight: 600;
}

.form-input {
	width: 100%;
	padding: 0.6rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.95rem;
}

.locked-room {
	display: flex;
	align-items: center;
	gap: 0.4rem;
	margin: 0;
	padding: 0.6rem 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-background-dark);
	color: var(--color-main-text);
	font-size: 0.95rem;
}

.field-error {
	color: var(--color-error-text);
	font-size: 0.85rem;
	margin: 0.4rem 0 0 0;
}
</style>
