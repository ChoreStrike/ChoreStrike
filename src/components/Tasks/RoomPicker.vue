<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="room-picker" data-testid="room-picker">
		<button ref="toggle"
			type="button"
			class="picker-toggle"
			:aria-expanded="open ? 'true' : 'false'"
			:aria-controls="panelId"
			data-testid="room-picker-toggle"
			@click="open = !open">
			<Icon name="MapPin" :size="16" class="picker-icon" />
			<span class="picker-label">{{ label }}</span>
			<ExpandChevron :open="open" />
		</button>
		<div v-if="open" :id="panelId" class="picker-panel">
			<button v-if="filter"
				type="button"
				class="picker-entry"
				:aria-pressed="modelValue === null && floorId === null ? 'true' : 'false'"
				data-testid="room-picker-all"
				@click="choose(null, null)">
				{{ t('chorestrike', 'All rooms') }}
			</button>
			<ul class="picker-list">
				<li v-for="floor in floorsWithRooms" :key="floor.id">
					<button type="button"
						class="picker-entry picker-floor"
						:aria-expanded="openFloorId === floor.id ? 'true' : 'false'"
						:aria-controls="openFloorId === floor.id ? `${panelId}-floor-${floor.id}` : undefined"
						:aria-current="filter && chosenFloorId === floor.id && modelValue === null ? 'true' : undefined"
						:data-testid="`room-picker-floor-${floor.id}`"
						@click="pickFloor(floor.id)">
						<ExpandChevron :open="openFloorId === floor.id" />
						{{ floor.name }}
					</button>
					<ul v-if="openFloorId === floor.id" :id="`${panelId}-floor-${floor.id}`" class="picker-list picker-rooms">
						<li v-for="entry in roomsStore.roomsByFloor[floor.id]" :key="entry.id">
							<button type="button"
								class="picker-entry"
								:aria-pressed="entry.id === modelValue ? 'true' : 'false'"
								:data-testid="`room-picker-room-${entry.id}`"
								@click="choose(floor.id, entry.id)">
								{{ entry.name }}
							</button>
						</li>
					</ul>
				</li>
			</ul>
			<p v-if="floorsWithRooms.length === 0" class="picker-empty">
				{{ t('chorestrike', 'No rooms yet') }}
			</p>
		</div>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed, nextTick, onMounted, ref, useId, watch } from 'vue'
import ExpandChevron from '../Common/ExpandChevron.vue'
import Icon from '../Common/Icon.vue'
import { useRoomsStore } from '../../store/rooms.js'
import { logger } from '../../utils/logger.js'

/**
 * The one room choice (CS-PLAN-091 WP-B6, the operator's GUI review): one
 * button that opens the floors in place; a floor opens its rooms, a room
 * is the choice. As the quick filter of the task list (`filter`) a floor
 * is a choice of its own and *All rooms* clears it.
 */
const props = defineProps({
	/** The chosen room, or null. */
	modelValue: { type: Number, default: null },
	/** Filter only: the chosen floor while no room is chosen. */
	floorId: { type: Number, default: null },
	/** The floor that opens first while nothing is chosen. */
	initialFloorId: { type: Number, default: null },
	/** A filter: floors can be chosen and nothing has to be. */
	filter: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'select'])

const roomsStore = useRoomsStore()
const panelId = `room-picker-panel-${useId()}`
const toggle = ref(null)
const open = ref(false)
const openFloorId = ref(null)

const floorsWithRooms = computed(() => roomsStore.floors.filter((floor) => (roomsStore.roomsByFloor[floor.id] || []).length > 0))
const room = computed(() => (props.modelValue === null ? null : roomsStore.getRoomById(props.modelValue) ?? null))
/** The floor of the choice: the room's, else the chosen floor of a filter. */
const chosenFloorId = computed(() => room.value?.floorId ?? (props.filter ? props.floorId : null))

const label = computed(() => {
	const floor = roomsStore.getFloorById(chosenFloorId.value)
	if (room.value) {
		return floor ? `${floor.name} · ${room.value.name}` : room.value.name
	}
	if (floor) {
		return floor.name
	}
	return props.filter ? t('chorestrike', 'All rooms') : t('chorestrike', 'Select a room')
})

// The panel opens on the floor of the choice, else on the preferred or the only floor.
watch(open, (now) => {
	if (now) {
		const only = floorsWithRooms.value.length === 1 ? floorsWithRooms.value[0].id : null
		openFloorId.value = chosenFloorId.value ?? props.initialFloorId ?? only
	}
})

/**
 * A floor opens its rooms; in a filter it is also the choice.
 * @param {number} id The floor.
 */
function pickFloor(id) {
	const chosen = props.filter && chosenFloorId.value === id && props.modelValue === null
	openFloorId.value = openFloorId.value === id && (!props.filter || chosen) ? null : id
	if (props.filter && !chosen) {
		emit('update:modelValue', null)
		emit('select', { floorId: id, roomId: null })
	}
}

/**
 * Take a room (or, in a filter, all rooms) and close.
 * @param {?number} floor The floor of the room.
 * @param {?number} roomId The room, or null for all rooms.
 */
async function choose(floor, roomId) {
	emit('update:modelValue', roomId)
	emit('select', { floorId: floor, roomId })
	open.value = false
	await nextTick()
	toggle.value?.focus()
}

onMounted(async () => {
	// The task list reads the structure itself; the form may open before anything did.
	if (!props.filter && (roomsStore.floors.length === 0 || roomsStore.rooms.length === 0)) {
		try {
			await roomsStore.fetchFloorsWithRooms()
		} catch (error) {
			logger.error('Failed to load floors and rooms', error, { component: 'RoomPicker', action: 'mounted' })
		}
	}
})
</script>

<style scoped>
.room-picker {
	display: flex;
	flex-direction: column;
	gap: 0.35rem;
}

.picker-toggle,
.picker-entry {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	width: 100%;
	min-height: 44px;
	padding: 0.5rem 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.95rem;
	text-align: start;
	cursor: pointer;
}

.picker-toggle {
	border-color: var(--color-text-maxcontrast);
}

.picker-label {
	flex: 1;
	min-width: 0;
	overflow-wrap: anywhere;
}

.picker-icon {
	color: var(--color-text-maxcontrast);
}

.picker-panel {
	display: flex;
	flex-direction: column;
	gap: 0.35rem;
	padding: 0.5rem;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-background-dark);
}

.picker-list {
	display: flex;
	flex-direction: column;
	gap: 0.35rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.picker-rooms {
	margin-block-start: 0.35rem;
	padding-inline-start: 1.5rem;
}

.picker-floor {
	font-weight: 600;
}

.picker-entry[aria-pressed='true'],
.picker-entry[aria-current='true'] {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light);
	font-weight: 700;
}

.picker-toggle:focus-visible,
.picker-entry:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.picker-empty {
	margin: 0;
	color: var(--color-error-text);
	font-size: 0.85rem;
}
</style>
