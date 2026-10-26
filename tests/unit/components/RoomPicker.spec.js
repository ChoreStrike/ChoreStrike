/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * RoomPicker component suite (CR-053-22, CS-PLAN-068 §3.6): the room is a
 * REQUIRED creation input — floor/room sync, locked preset mode and the
 * v-model round-trip are pinned here.
 */

import { describe, it, expect, beforeEach, jest } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { logger } from '@/utils/logger.js'
jest.mock('@/utils/logger.js', () => ({ logger: { error: jest.fn() } }))
import { createPinia, setActivePinia } from 'pinia'

import RoomPicker from '../../../src/components/Tasks/RoomPicker.vue'

const floors = [
	{ id: 1, name: 'Ground Floor' },
	{ id: 2, name: 'Upper Floor' },
]
const rooms = [
	{ id: 11, name: 'Kitchen', floorId: 1 },
	{ id: 12, name: 'Hallway', floorId: 1 },
	{ id: 21, name: 'Bedroom', floorId: 2 },
]

const mockRoomsStore = {
	floors,
	rooms,
	roomsByFloor: {
		1: [rooms[0], rooms[1]],
		2: [rooms[2]],
	},
	getRoomById: (id) => rooms.find(room => room.id === id) || null,
	getFloorById: (id) => floors.find(floor => floor.id === id) || null,
	fetchFloorsWithRooms: jest.fn(),
}

jest.mock('../../../src/store/rooms.js', () => ({
	useRoomsStore: jest.fn(() => mockRoomsStore),
}))

describe('RoomPicker', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		mockRoomsStore.fetchFloorsWithRooms.mockClear()
		mockRoomsStore.floors = floors
		mockRoomsStore.rooms = rooms
		mockRoomsStore.roomsByFloor = { 1: [rooms[0], rooms[1]], 2: [rooms[2]] }
	})

	it('renders floor and room selects with only floors that have rooms', () => {
		const wrapper = mount(RoomPicker, { props: { modelValue: null } })
		const floorOptions = wrapper.find('[data-testid="room-picker-floor"]').findAll('option')
		expect(floorOptions.map(option => option.text())).toEqual(['Ground Floor', 'Upper Floor'])
		// The room select starts with the disabled placeholder.
		expect(wrapper.find('[data-testid="room-picker-room"]').findAll('option')[0].attributes('disabled')).toBeDefined()
	})

	it('defaults to the first floor with rooms and lists its rooms', async () => {
		const wrapper = mount(RoomPicker, { props: { modelValue: null } })
		await wrapper.vm.$nextTick()
		const floorSelect = wrapper.find('[data-testid="room-picker-floor"]')
		expect(Number(floorSelect.element.value)).toBe(1)
		const roomTexts = wrapper.find('[data-testid="room-picker-room"]').findAll('option').map(option => option.text())
		expect(roomTexts).toContain('Kitchen')
		expect(roomTexts).toContain('Hallway')
		expect(roomTexts).not.toContain('Bedroom')
	})

	it('emits the selected room through v-model', async () => {
		const wrapper = mount(RoomPicker, { props: { modelValue: null } })
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="room-picker-room"]').setValue('11')
		expect(wrapper.emitted('update:modelValue')[0]).toEqual([11])
	})

	it('derives the floor when the model value is set externally', async () => {
		const wrapper = mount(RoomPicker, { props: { modelValue: 21 } })
		await wrapper.vm.$nextTick()
		expect(Number(wrapper.find('[data-testid="room-picker-floor"]').element.value)).toBe(2)
	})

	it('resets an incompatible room when switching floors', async () => {
		const wrapper = mount(RoomPicker, { props: { modelValue: null } })
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="room-picker-room"]').setValue('11')
		await wrapper.find('[data-testid="room-picker-floor"]').setValue('2')
		await wrapper.vm.$nextTick()
		// The Kitchen (floor 1) cannot live on floor 2 — the room resets.
		expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([null])
	})

	// FEAT-UX-038: the Tasks tab filters prefill without locking the picker.
	it('starts on the initial floor without a room when only a floor is given', async () => {
		const wrapper = mount(RoomPicker, { props: { modelValue: null, initialFloorId: 2 } })
		await wrapper.vm.$nextTick()
		expect(Number(wrapper.find('[data-testid="room-picker-floor"]').element.value)).toBe(2)
		// The disabled "Select a room" placeholder stays selected.
		expect(wrapper.find('[data-testid="room-picker-room"]').element.selectedIndex).toBe(0)
		expect(wrapper.emitted('update:modelValue')).toBeUndefined()
	})

	it('keeps an initial room selectable and its own floor over the initial floor', async () => {
		const wrapper = mount(RoomPicker, { props: { modelValue: 21, initialFloorId: 1 } })
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-testid="room-picker-locked"]').exists()).toBe(false)
		expect(Number(wrapper.find('[data-testid="room-picker-floor"]').element.value)).toBe(2)
		expect(Number(wrapper.find('[data-testid="room-picker-room"]').element.value)).toBe(21)
	})

	it('falls back to the first floor for an initial floor without rooms', async () => {
		const wrapper = mount(RoomPicker, { props: { modelValue: null, initialFloorId: 99 } })
		await wrapper.vm.$nextTick()
		expect(Number(wrapper.find('[data-testid="room-picker-floor"]').element.value)).toBe(1)
	})

	it('shows the read-only locked label in preset mode', () => {
		const wrapper = mount(RoomPicker, { props: { modelValue: null, presetRoomId: 11 } })
		expect(wrapper.find('[data-testid="room-picker-locked"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="room-picker-locked"]').text()).toContain('Kitchen')
		expect(wrapper.find('[data-testid="room-picker-floor"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="room-picker-room"]').exists()).toBe(false)
	})

	it('fetches floors and rooms only when the stores are empty', async () => {
		mockRoomsStore.floors = []
		mockRoomsStore.rooms = []
		mockRoomsStore.fetchFloorsWithRooms.mockResolvedValue(undefined)
		mount(RoomPicker, { props: { modelValue: null } })
		await Promise.resolve()
		expect(mockRoomsStore.fetchFloorsWithRooms).toHaveBeenCalledTimes(1)
	})

	it('reports an unavailable room catalog without selecting a fictional room', async () => {
		mockRoomsStore.floors = []
		mockRoomsStore.rooms = []
		mockRoomsStore.roomsByFloor = {}
		const failure = new Error('offline')
		mockRoomsStore.fetchFloorsWithRooms.mockRejectedValueOnce(failure)
		const wrapper = mount(RoomPicker)
		await flushPromises()
		expect(wrapper.text()).toContain('No rooms in this floor yet')
		expect(wrapper.emitted('update:modelValue')).toBeUndefined()
		expect(logger.error).toHaveBeenCalledWith('Failed to load floors and rooms', failure, expect.any(Object))
		wrapper.unmount()
	})
})
