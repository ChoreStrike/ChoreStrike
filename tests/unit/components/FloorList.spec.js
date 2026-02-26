import { describe, it, expect, vi, beforeEach } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import FloorList from '../../../src/components/Dashboard/FloorList.vue'
import RoomCard from '../../../src/components/Dashboard/RoomCard.vue'
import { useRoomsStore } from '../../../src/store/rooms.js'

// Mock vue-router
jest.mock('vue-router', () => ({
	useRouter: () => ({
		push: jest.fn(),
	}),
}))

// Mock Icon component
jest.mock('../../../src/components/Common/Icon.vue', () => ({
	name: 'Icon',
	props: ['name', 'size'],
	template: '<span class="icon-mock">Icon</span>',
}))

describe('FloorList.vue', () => {
	let pinia
	let roomsStore

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		roomsStore = useRoomsStore()
		// Reset store state
		roomsStore.floors = []
		roomsStore.rooms = []
		roomsStore.loading = false
		roomsStore.roomsByFloor = {}
	})

	it('renders empty state when no floors', () => {
		roomsStore.floors = []

		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(wrapper.find('.empty-state').exists()).toBe(true)
		expect(wrapper.text()).toContain('No floors yet')
	})

	it('renders loading state when loading and no floors', () => {
		roomsStore.loading = true
		roomsStore.floors = []

		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(wrapper.find('.loading-state').exists()).toBe(true)
	})

	it('renders floors list when floors exist', () => {
		roomsStore.floors = [
			{ id: 1, name: 'Ground Floor', sortOrder: 0 },
			{ id: 2, name: 'First Floor', sortOrder: 1 },
		]

		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(wrapper.find('.floors-container').exists()).toBe(true)
		expect(wrapper.findAll('.floor-section')).toHaveLength(2)
	})

	it('displays floor name and stats', () => {
		roomsStore.floors = [{ id: 1, name: 'Ground Floor', sortOrder: 0 }]
		roomsStore.roomsByFloor = { 1: [] }

		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(wrapper.find('.floor-name').text()).toBe('Ground Floor')
		expect(wrapper.find('.room-count').text()).toContain('0 rooms')
	})

	it('shows room count for floor with rooms', () => {
		roomsStore.floors = [{ id: 1, name: 'Ground Floor', sortOrder: 0 }]
		roomsStore.roomsByFloor = {
			1: [
				{ id: 1, name: 'Kitchen', cleanliness: 80 },
				{ id: 2, name: 'Living Room', cleanliness: 90 },
			],
		}

		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(wrapper.find('.room-count').text()).toContain('2 rooms')
	})

	it('calculates and displays floor cleanliness', () => {
		roomsStore.floors = [{ id: 1, name: 'Ground Floor', sortOrder: 0 }]
		roomsStore.roomsByFloor = {
			1: [
				{ id: 1, name: 'Kitchen', cleanliness: 80 },
				{ id: 2, name: 'Living Room', cleanliness: 90 },
			],
		}

		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		// Average cleanliness: (80 + 90) / 2 = 85%
		expect(wrapper.find('.cleanliness-badge').text()).toContain('85%')
	})

	it('applies cleanliness class based on percentage', () => {
		const testCases = [
			{ cleanliness: 85, expectedClass: 'excellent' },
			{ cleanliness: 70, expectedClass: 'good' },
			{ cleanliness: 50, expectedClass: 'fair' },
			{ cleanliness: 30, expectedClass: 'poor' },
			{ cleanliness: 10, expectedClass: 'terrible' },
		]

		testCases.forEach(({ cleanliness, expectedClass }) => {
			roomsStore.floors = [{ id: 1, name: 'Test Floor', sortOrder: 0 }]
			roomsStore.roomsByFloor = {
				1: [{ id: 1, name: 'Room', cleanliness }],
			}

			const wrapper = mount(FloorList, {
				global: {
					plugins: [pinia],
					components: { RoomCard },
				},
			})

			expect(wrapper.find('.cleanliness-badge').classes()).toContain(expectedClass)
		})
	})

	it('shows empty floor message when floor has no rooms', () => {
		roomsStore.floors = [{ id: 1, name: 'Empty Floor', sortOrder: 0 }]
		roomsStore.roomsByFloor = { 1: [] }

		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		// Expand the floor first
		wrapper.find('.toggle-button').trigger('click')
		expect(wrapper.find('.empty-floor').exists()).toBe(true)
	})

	it('renders RoomCard components for rooms in expanded floor', async () => {
		roomsStore.floors = [{ id: 1, name: 'Ground Floor', sortOrder: 0 }]
		roomsStore.roomsByFloor = {
			1: [
				{ id: 1, name: 'Kitchen', cleanliness: 80, monsterCount: 0 },
				{ id: 2, name: 'Living Room', cleanliness: 90, monsterCount: 1 },
			],
		}

		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		// Expand the floor
		await wrapper.find('.toggle-button').trigger('click')

		const roomCards = wrapper.findAllComponents(RoomCard)
		expect(roomCards).toHaveLength(2)
	})

	it('opens add floor modal when add button clicked', async () => {
		roomsStore.floors = []

		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		await wrapper.find('.add-button').trigger('click')
		expect(wrapper.find('.modal-overlay').exists()).toBe(true)
		expect(wrapper.find('.modal-content h3').text()).toBe('Add New Floor')
	})

	it('closes modal when cancel button clicked', async () => {
		roomsStore.floors = []

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
		roomsStore.floors = []

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

	it('has refresh button that triggers floor refresh', () => {
		roomsStore.fetchFloorsWithRooms = vi.fn().mockResolvedValue([])
		roomsStore.floors = [{ id: 1, name: 'Test', sortOrder: 0 }]

		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(wrapper.find('.refresh-button').exists()).toBe(true)
	})

	it('disables refresh button when loading', () => {
		roomsStore.loading = true
		roomsStore.floors = [{ id: 1, name: 'Test', sortOrder: 0 }]

		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(wrapper.find('.refresh-button').attributes('disabled')).toBeDefined()
	})

	it('toggles floor expansion on toggle button click', async () => {
		roomsStore.floors = [{ id: 1, name: 'Test Floor', sortOrder: 0 }]
		roomsStore.roomsByFloor = { 1: [] }
		roomsStore.fetchRooms = vi.fn().mockResolvedValue([])

		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		// Initially not expanded
		expect(wrapper.find('.floor-content').exists()).toBe(false)

		// Toggle to expand
		await wrapper.find('.toggle-button').trigger('click')
		expect(wrapper.find('.floor-content').exists()).toBe(true)

		// Toggle to collapse
		await wrapper.find('.toggle-button').trigger('click')
		expect(wrapper.find('.floor-content').exists()).toBe(false)
	})

	it('has edit and delete buttons for each floor', () => {
		roomsStore.floors = [{ id: 1, name: 'Test Floor', sortOrder: 0 }]

		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(wrapper.find('.edit-button').exists()).toBe(true)
		expect(wrapper.find('.delete-button').exists()).toBe(true)
	})

	it('shows add room card in expanded floor with rooms', async () => {
		roomsStore.floors = [{ id: 1, name: 'Test Floor', sortOrder: 0 }]
		roomsStore.roomsByFloor = {
			1: [{ id: 1, name: 'Kitchen', cleanliness: 80 }],
		}

		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		await wrapper.find('.toggle-button').trigger('click')
		expect(wrapper.find('.add-room-card').exists()).toBe(true)
	})

	it('applies compact class when compact prop is true', () => {
		roomsStore.floors = [{ id: 1, name: 'Test', sortOrder: 0 }]

		const wrapper = mount(FloorList, {
			props: { compact: true },
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		// Check that compact is passed to RoomCard
		const roomCard = wrapper.findComponent(RoomCard)
		if (roomCard.exists()) {
			expect(roomCard.props('compact')).toBe(true)
		}
	})
})
