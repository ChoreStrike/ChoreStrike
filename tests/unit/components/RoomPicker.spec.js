/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { beforeEach, describe, expect, it, jest } from '@jest/globals'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import RoomPicker from '../../../src/components/Tasks/RoomPicker.vue'
import { useRoomsStore } from '../../../src/store/rooms.js'
import { logger } from '../../../src/utils/logger.js'

jest.mock('@nextcloud/l10n', () => ({ translate: jest.fn((app, text) => text) }))
jest.mock('@/utils/logger', () => ({ logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() } }))

const floors = [
	{ id: 1, name: 'Ground Floor' },
	{ id: 2, name: 'Upper Floor' },
	// A floor without rooms is never offered.
	{ id: 3, name: 'Attic' },
]
const rooms = [
	{ id: 11, name: 'Kitchen', floorId: 1 },
	{ id: 12, name: 'Hallway', floorId: 1 },
	{ id: 21, name: 'Bedroom', floorId: 2 },
]

/**
 * The one room choice (CS-PLAN-091 WP-B6, the operator's GUI review): one
 * button that opens the floors in place; a floor opens its rooms, a room is
 * the choice. As a filter a floor is a choice of its own.
 */
describe('RoomPicker', () => {
	let pinia
	let roomsStore

	beforeEach(() => {
		jest.clearAllMocks()
		pinia = createPinia()
		setActivePinia(pinia)
		roomsStore = useRoomsStore()
		roomsStore.floors = floors
		roomsStore.rooms = rooms
		jest.spyOn(roomsStore, 'fetchFloorsWithRooms').mockResolvedValue([])
	})

	const mountPicker = (props = {}) => mount(RoomPicker, { props, global: { plugins: [pinia] }, attachTo: document.body })
	const toggle = (wrapper) => wrapper.get('[data-testid="room-picker-toggle"]')
	// The icon mock renders its name: the label is the text beside it.
	const label = (wrapper) => wrapper.get('.picker-label').text()
	const floor = (wrapper, id) => wrapper.get(`[data-testid="room-picker-floor-${id}"]`)
	const room = (wrapper, id) => wrapper.find(`[data-testid="room-picker-room-${id}"]`)
	const offeredRooms = (wrapper) => wrapper.findAll('[data-testid^="room-picker-room-"]').map((button) => button.text())
	const open = async (props = {}) => {
		const wrapper = mountPicker(props)
		await toggle(wrapper).trigger('click')
		return wrapper
	}

	describe('in the form', () => {
		it('is one closed button that asks for a room', () => {
			const wrapper = mountPicker()

			expect(label(wrapper)).toBe('Select a room')
			expect(toggle(wrapper).attributes('aria-expanded')).toBe('false')
			expect(wrapper.find('[data-testid^="room-picker-floor-"]').exists()).toBe(false)
			expect(roomsStore.fetchFloorsWithRooms).not.toHaveBeenCalled()
		})

		it('names the chosen room with its floor', () => {
			expect(label(mountPicker({ modelValue: 21 }))).toBe('Upper Floor · Bedroom')
		})

		it('opens the floors that have rooms, none of them open and no way to choose all', async () => {
			const wrapper = await open()

			expect(toggle(wrapper).attributes('aria-expanded')).toBe('true')
			expect(wrapper.element.querySelector(`[id="${toggle(wrapper).attributes('aria-controls')}"]`)).not.toBeNull()
			expect(wrapper.findAll('[data-testid^="room-picker-floor-"]').map((button) => button.text().replace('ChevronRight', '').trim())).toEqual(['Ground Floor', 'Upper Floor'])
			expect(offeredRooms(wrapper)).toEqual([])
			expect(wrapper.find('[data-testid="room-picker-all"]').exists()).toBe(false)
			// A floor is no choice here: it carries no pressed state.
			expect(floor(wrapper, 1).attributes('aria-pressed')).toBeUndefined()
		})

		it('opens on the floor of the chosen room, over the preferred floor', async () => {
			const wrapper = await open({ modelValue: 21, initialFloorId: 1 })

			expect(floor(wrapper, 2).attributes('aria-expanded')).toBe('true')
			expect(offeredRooms(wrapper)).toEqual(['Bedroom'])
			expect(room(wrapper, 21).attributes('aria-pressed')).toBe('true')
		})

		// FEAT-UX-038: the list's floor filter prefills without choosing a room.
		it('opens on the preferred floor while no room is chosen', async () => {
			const wrapper = await open({ initialFloorId: 2 })

			expect(offeredRooms(wrapper)).toEqual(['Bedroom'])
			expect(room(wrapper, 21).attributes('aria-pressed')).toBe('false')
			expect(wrapper.emitted('update:modelValue')).toBeUndefined()
		})

		it('opens the only floor of a house at once', async () => {
			roomsStore.rooms = rooms.filter((entry) => entry.floorId === 1)
			const wrapper = await open()

			expect(offeredRooms(wrapper)).toEqual(['Kitchen', 'Hallway'])
		})

		it('lets a floor only open and close its rooms, one floor at a time', async () => {
			const wrapper = await open()

			await floor(wrapper, 1).trigger('click')
			expect(floor(wrapper, 1).attributes('aria-expanded')).toBe('true')
			expect(offeredRooms(wrapper)).toEqual(['Kitchen', 'Hallway'])

			await floor(wrapper, 2).trigger('click')
			expect(floor(wrapper, 1).attributes('aria-expanded')).toBe('false')
			expect(offeredRooms(wrapper)).toEqual(['Bedroom'])

			await floor(wrapper, 2).trigger('click')
			expect(offeredRooms(wrapper)).toEqual([])
			expect(wrapper.emitted('update:modelValue')).toBeUndefined()
			expect(wrapper.emitted('select')).toBeUndefined()
		})

		it('takes a room, closes and returns the focus to its button', async () => {
			const wrapper = await open()
			await floor(wrapper, 1).trigger('click')

			await room(wrapper, 12).trigger('click')
			await flushPromises()

			expect(wrapper.emitted('update:modelValue')).toEqual([[12]])
			expect(wrapper.emitted('select')).toEqual([[{ floorId: 1, roomId: 12 }]])
			expect(toggle(wrapper).attributes('aria-expanded')).toBe('false')
			expect(wrapper.find('[data-testid^="room-picker-floor-"]').exists()).toBe(false)
			expect(document.activeElement).toBe(toggle(wrapper).element)
			wrapper.unmount()
		})

		it('closes on a second press of its button', async () => {
			const wrapper = await open()

			await toggle(wrapper).trigger('click')

			expect(wrapper.find('[data-testid^="room-picker-floor-"]').exists()).toBe(false)
		})

		it('reads the catalog only when the store holds none', async () => {
			roomsStore.floors = []
			roomsStore.rooms = []

			const wrapper = await open()
			await flushPromises()

			expect(roomsStore.fetchFloorsWithRooms).toHaveBeenCalledTimes(1)
			expect(wrapper.text()).toContain('No rooms yet')
		})

		it('logs an unavailable catalog and chooses nothing', async () => {
			roomsStore.floors = []
			roomsStore.rooms = []
			const failure = new Error('offline')
			roomsStore.fetchFloorsWithRooms.mockRejectedValueOnce(failure)

			const wrapper = await open()
			await flushPromises()

			expect(logger.error).toHaveBeenCalledWith('Failed to load floors and rooms', failure, expect.objectContaining({ component: 'RoomPicker' }))
			expect(wrapper.text()).toContain('No rooms yet')
			expect(wrapper.emitted('update:modelValue')).toBeUndefined()
		})
	})

	describe('as the quick filter of the task list', () => {
		const openFilter = (props = {}) => open({ filter: true, ...props })

		it('leaves the read of the structure to the task list', async () => {
			roomsStore.floors = []
			roomsStore.rooms = []

			await openFilter()
			await flushPromises()

			expect(roomsStore.fetchFloorsWithRooms).not.toHaveBeenCalled()
		})

		it('stands for all rooms until something is chosen', async () => {
			const wrapper = await openFilter()

			expect(label(wrapper)).toBe('All rooms')
			expect(wrapper.get('[data-testid="room-picker-all"]').attributes('aria-pressed')).toBe('true')
			// A floor opens its rooms and is a choice: one state each, never a pressed toggle that is also expanded.
			expect(floor(wrapper, 1).attributes('aria-pressed')).toBeUndefined()
			expect(floor(wrapper, 1).attributes('aria-current')).toBeUndefined()
		})

		it('takes a floor as a choice of its own and keeps its rooms open', async () => {
			const wrapper = await openFilter()

			await floor(wrapper, 2).trigger('click')

			expect(wrapper.emitted('select')).toEqual([[{ floorId: 2, roomId: null }]])
			expect(wrapper.emitted('update:modelValue')).toEqual([[null]])
			expect(toggle(wrapper).attributes('aria-expanded')).toBe('true')
			expect(offeredRooms(wrapper)).toEqual(['Bedroom'])
		})

		it('names and marks the chosen floor, and a second press only closes its rooms', async () => {
			const wrapper = await openFilter({ floorId: 2 })

			expect(label(wrapper)).toBe('Upper Floor')
			expect(floor(wrapper, 2).attributes('aria-current')).toBe('true')
			expect(floor(wrapper, 2).attributes('aria-controls')).toBe(wrapper.get('.picker-rooms').attributes('id'))
			expect(wrapper.get('[data-testid="room-picker-all"]').attributes('aria-pressed')).toBe('false')
			expect(offeredRooms(wrapper)).toEqual(['Bedroom'])

			await floor(wrapper, 2).trigger('click')

			expect(offeredRooms(wrapper)).toEqual([])
			expect(wrapper.emitted('select')).toBeUndefined()
		})

		it('takes a room of the chosen floor and closes', async () => {
			const wrapper = await openFilter({ floorId: 1 })

			await room(wrapper, 11).trigger('click')
			await flushPromises()

			expect(wrapper.emitted('select')).toEqual([[{ floorId: 1, roomId: 11 }]])
			expect(toggle(wrapper).attributes('aria-expanded')).toBe('false')
		})

		it('marks the chosen room, not its floor, and goes back to the whole floor on its press', async () => {
			const wrapper = await openFilter({ modelValue: 11, floorId: 1 })

			expect(label(wrapper)).toBe('Ground Floor · Kitchen')
			expect(room(wrapper, 11).attributes('aria-pressed')).toBe('true')
			expect(floor(wrapper, 1).attributes('aria-current')).toBeUndefined()

			await floor(wrapper, 1).trigger('click')

			expect(wrapper.emitted('select')).toEqual([[{ floorId: 1, roomId: null }]])
			// The rooms stay open to narrow again.
			expect(offeredRooms(wrapper)).toEqual(['Kitchen', 'Hallway'])
		})

		it('clears the choice with All rooms and closes', async () => {
			const wrapper = await openFilter({ modelValue: 11, floorId: 1 })

			await wrapper.get('[data-testid="room-picker-all"]').trigger('click')
			await flushPromises()

			expect(wrapper.emitted('select')).toEqual([[{ floorId: null, roomId: null }]])
			expect(wrapper.emitted('update:modelValue')).toEqual([[null]])
			expect(toggle(wrapper).attributes('aria-expanded')).toBe('false')
		})
	})
})
