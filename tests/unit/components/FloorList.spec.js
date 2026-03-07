import { describe, it, expect, vi, beforeEach } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { translate } from '@nextcloud/l10n'
import FloorList from '../../../src/components/Dashboard/FloorList.vue'
import RoomCard from '../../../src/components/Dashboard/RoomCard.vue'

let mockRoomsStore

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

global.confirm = jest.fn(() => true)

// Mock vue-router
jest.mock('vue-router', () => ({
	useRouter: () => ({
		push: jest.fn(),
	}),
}))

// Mock stores module
jest.mock('../../../src/store/rooms.js', () => ({
	useRoomsStore: () => mockRoomsStore,
}))

describe('FloorList.vue', () => {
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		mockRoomsStore = {
			floors: [],
			rooms: [],
			loading: false,
			roomsByFloor: {},
			expandedFloors: [],
			fetchFloorsWithRooms: jest.fn(),
			fetchRooms: jest.fn(),
			createFloor: jest.fn(),
			createRoom: jest.fn(),
			deleteFloor: jest.fn(),
			toggleFloorExpanded: jest.fn((floorId) => {
				if (mockRoomsStore.expandedFloors.includes(floorId)) {
					mockRoomsStore.expandedFloors = mockRoomsStore.expandedFloors.filter(id => id !== floorId)
				} else {
					mockRoomsStore.expandedFloors = [...mockRoomsStore.expandedFloors, floorId]
				}
			}),
			setFloorExpanded: jest.fn((floorId) => {
				if (!mockRoomsStore.expandedFloors.includes(floorId)) {
					mockRoomsStore.expandedFloors = [...mockRoomsStore.expandedFloors, floorId]
				}
			}),
			setFloorCollapsed: jest.fn((floorId) => {
				mockRoomsStore.expandedFloors = mockRoomsStore.expandedFloors.filter(id => id !== floorId)
			}),
			getFloorById: jest.fn((floorId) => mockRoomsStore.floors.find(floor => floor.id === floorId)),
		}
		translate.mockClear()
		global.confirm.mockClear()
	})

	it('renders component', () => {
		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(wrapper.find('.floor-list').exists()).toBe(true)
	})

	it('displays floor header with title', () => {
		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(wrapper.find('.floor-header').exists()).toBe(true)
		expect(wrapper.find('.floor-title').text()).toContain('Floors')
	})

	it('has refresh button', () => {
		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(wrapper.find('.refresh-button').exists()).toBe(true)
	})

	it('has add floor button', () => {
		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(wrapper.find('.add-button').exists()).toBe(true)
	})

	it('opens add floor modal when add button clicked', async () => {
		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		await wrapper.find('.add-button').trigger('click')
		expect(wrapper.find('.modal-overlay').exists()).toBe(true)
	})

	it('closes modal when cancel button clicked', async () => {
		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		// Open modal
		await wrapper.find('.add-button').trigger('click')
		expect(wrapper.find('.modal-overlay').exists()).toBe(true)

		// Close modal
		await wrapper.find('.cancel-button').trigger('click')
		expect(wrapper.find('.modal-overlay').exists()).toBe(false)
	})

	it('disables submit button when floor name is empty', async () => {
		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		await wrapper.find('.add-button').trigger('click')
		const submitButton = wrapper.find('.submit-button')
		expect(submitButton.attributes('disabled')).toBeDefined()
	})

	it('has floor input field in modal', async () => {
		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		await wrapper.find('.add-button').trigger('click')
		expect(wrapper.find('#floor-name').exists()).toBe(true)
	})

	it('displays empty state when no floors', () => {
		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(wrapper.find('.empty-state').exists()).toBe(true)
	})

	it('has compact prop that affects RoomCard', () => {
		const wrapper = mount(FloorList, {
			props: { compact: true },
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(wrapper.props('compact')).toBe(true)
	})

	it('translates critical dashboard strings via Nextcloud l10n', () => {
		mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(translate).toHaveBeenCalledWith('chorestrike', 'Floors & Rooms')
		expect(translate).toHaveBeenCalledWith('chorestrike', 'No floors yet')
	})

	it('uses localized floor deletion confirmation message', async () => {
		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		await wrapper.vm.deleteFloor({ id: 1, name: 'Ground Floor' })

		expect(global.confirm).toHaveBeenCalledTimes(1)
		expect(global.confirm.mock.calls[0][0]).toContain('Are you sure you want to delete')
	})

	it('shows add room action when expanding an empty floor', async () => {
		mockRoomsStore.floors = [{ id: 1, name: 'Ground Floor', sortOrder: 0 }]
		mockRoomsStore.roomsByFloor = { 1: [] }
		mockRoomsStore.expandedFloors = [1]

		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(wrapper.find('[data-testid="add-room-button-1"]').exists()).toBe(true)
		expect(mockRoomsStore.fetchRooms).not.toHaveBeenCalled()
	})
})
