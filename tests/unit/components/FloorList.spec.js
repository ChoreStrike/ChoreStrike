import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { translate } from '@nextcloud/l10n'
import FloorList from '../../../src/components/Dashboard/FloorList.vue'
import RoomCard from '../../../src/components/Dashboard/RoomCard.vue'

let mockRoomsStore

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, vars = {}) =>
		text.replace(/\{(\w+)\}/g, (m, key) => (key in vars ? String(vars[key]) : m))
	),
}))

jest.mock('../../../src/utils/logger.js', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

// Mock vue-router
const mockRouterPush = jest.fn()

jest.mock('vue-router', () => ({
	useRouter: () => ({
		push: mockRouterPush,
	}),
	RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

// Mock stores module
jest.mock('../../../src/store/rooms.js', () => ({
	useRoomsStore: () => mockRoomsStore,
}))

describe('FloorList.vue (read-only navigation, CS-PLAN-042)', () => {
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
			reorderFloors: jest.fn(),
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
			updateFloorName: jest.fn(),
		}
		translate.mockClear()
	})

	const mountList = (props = {}) => mount(FloorList, {
		props,
		global: {
			plugins: [pinia],
			components: { RoomCard },
		},
	})

	it('renders component', () => {
		const wrapper = mountList()
		expect(wrapper.find('.floor-list').exists()).toBe(true)
	})

	it('displays floor header with title', () => {
		const wrapper = mountList()
		expect(wrapper.find('.floor-header').exists()).toBe(true)
		expect(wrapper.find('.floor-title').text()).toContain('Floors')
	})

	it('has refresh button', () => {
		const wrapper = mountList()
		expect(wrapper.find('.refresh-button').exists()).toBe(true)
	})

	it('is read-only: no add/edit/delete/reorder controls (UX-004)', () => {
		mockRoomsStore.floors = [
			{ id: 1, name: 'Ground Floor', sortOrder: 0 },
			{ id: 2, name: 'First Floor', sortOrder: 1 },
		]
		mockRoomsStore.roomsByFloor = { 1: [], 2: [] }

		const wrapper = mountList()

		expect(wrapper.find('.add-button').exists()).toBe(false)
		expect(wrapper.find('.edit-button').exists()).toBe(false)
		expect(wrapper.find('.delete-button').exists()).toBe(false)
		expect(wrapper.find('[data-testid^="move-floor-"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="add-room-button-1"]').exists()).toBe(false)
		expect(wrapper.find('.add-room-card').exists()).toBe(false)
		expect(wrapper.find('.modal-overlay').exists()).toBe(false)
	})

	it('displays empty state when no floors', () => {
		const wrapper = mountList()
		expect(wrapper.find('.empty-state').exists()).toBe(true)
		// Structure editing lives in Settings (single surface, UX-004)
		expect(wrapper.find('.empty-state button').exists()).toBe(false)
	})

	it('has compact prop that affects RoomCard', () => {
		const wrapper = mountList({ compact: true })
		expect(wrapper.props('compact')).toBe(true)
	})

	it('translates critical dashboard strings via Nextcloud l10n', () => {
		mountList()

		expect(translate).toHaveBeenCalledWith('chorestrike', 'Floors & Rooms')
		expect(translate).toHaveBeenCalledWith('chorestrike', 'No floors yet')
	})

	it('expands and collapses floors via the toggle button', async () => {
		mockRoomsStore.floors = [{ id: 1, name: 'Ground Floor', sortOrder: 0 }]
		mockRoomsStore.roomsByFloor = { 1: [{ id: 1, name: 'Kitchen', cleanliness: 90 }] }

		const wrapper = mountList()

		await wrapper.find('.toggle-button').trigger('click')
		expect(mockRoomsStore.toggleFloorExpanded).toHaveBeenCalledWith(1)
	})

	it('navigates to the room on RoomCard room-click', async () => {
		mockRouterPush.mockClear()
		mockRoomsStore.floors = [{ id: 1, name: 'Ground Floor', sortOrder: 0 }]
		mockRoomsStore.roomsByFloor = { 1: [{ id: 7, name: 'Kitchen', cleanliness: 90 }] }
		mockRoomsStore.expandedFloors = [1]

		const wrapper = mountList()
		await wrapper.findComponent({ name: 'RoomCard' }).vm.$emit('room-click', { id: 7 })

		expect(mockRouterPush).toHaveBeenCalledWith('/room/7')
	})
})
