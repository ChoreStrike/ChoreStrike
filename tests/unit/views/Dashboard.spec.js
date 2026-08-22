import { describe, it, expect, beforeEach, afterEach } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import Dashboard from '../../../src/views/Dashboard.vue'

// Mock vue-router
const mockRouterPush = jest.fn()

jest.mock('vue-router', () => ({
	useRouter: () => ({
		push: mockRouterPush,
	}),
}))

// Mock stores
jest.mock('../../../src/store/house.js', () => ({
	useHouseStore: () => ({
		house: { id: 1, name: 'Test House', houseHealth: 85 },
		houseName: 'Test House',
		houseHealth: 85,
		loading: false,
		fetchHouse: jest.fn(),
	}),
}))

// Mutable so individual tests can simulate an empty (first-run) house.
const mockRoomsState = {
	floors: [{ id: 1, name: 'Ground Floor' }],
	rooms: [{ id: 1, name: 'Kitchen', floorId: 1, cleanliness: 80 }],
	loading: false,
	fetchFloorsWithRooms: jest.fn(),
}

jest.mock('../../../src/store/rooms.js', () => ({
	useRoomsStore: () => mockRoomsState,
}))

jest.mock('../../../src/store/game.js', () => ({
	useGameStore: () => ({
		fetchGameState: jest.fn().mockResolvedValue(undefined),
	}),
}))

// Mock child components
jest.mock('../../../src/components/Dashboard/HouseHealthBar.vue', () => ({
	name: 'HouseHealthBar',
	props: ['health', 'size'],
	template: '<div class="house-health-bar">Health: {{ health }}</div>',
}))

jest.mock('../../../src/components/Dashboard/HouseholdGoalBar.vue', () => ({
	name: 'HouseholdGoalBar',
	template: '<div class="household-goal-bar">Weekly Goal</div>',
}))

jest.mock('../../../src/components/Dashboard/QuickStats.vue', () => ({
	name: 'QuickStats',
	emits: ['toggle-private-filter'],
	template: '<div class="quick-stats">Quick Stats</div>',
}))

jest.mock('../../../src/components/Dashboard/FloorList.vue', () => ({
	name: 'FloorList',
	template: '<div class="floor-list">Floor List</div>',
}))

jest.mock('../../../src/components/Setup/SetupWizard.vue', () => ({
	name: 'SetupWizard',
	emits: ['close', 'applied', 'skipped'],
	template: '<div class="setup-wizard-stub" />',
}))

describe('Dashboard.vue', () => {
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
	})

	afterEach(() => {
		mockRoomsState.floors = [{ id: 1, name: 'Ground Floor' }]
	})

	it('renders dashboard component', () => {
		const wrapper = mount(Dashboard, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.dashboard').exists()).toBe(true)
	})

	it('displays house name in header', () => {
		const wrapper = mount(Dashboard, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('h1').text()).toBe('Test House')
	})

	it('displays tagline', () => {
		const wrapper = mount(Dashboard, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.tagline').exists()).toBe(true)
	})

	it('renders HouseHealthBar component', () => {
		const wrapper = mount(Dashboard, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.house-health-bar').exists()).toBe(true)
	})

	it('renders QuickStats component', () => {
		const wrapper = mount(Dashboard, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.quick-stats').exists()).toBe(true)
	})

	it('routes to the private task scope when QuickStats emits toggle-private-filter (CS-PLAN-041)', async () => {
		const wrapper = mount(Dashboard, {
			global: {
				plugins: [pinia],
			},
		})

		wrapper.findComponent({ name: 'QuickStats' }).vm.$emit('toggle-private-filter', true)
		await wrapper.vm.$nextTick()

		expect(mockRouterPush).toHaveBeenCalledWith({ name: 'tasks', query: { visibility: 'private' } })
	})

	it('renders FloorList component', () => {
		const wrapper = mount(Dashboard, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.floor-list').exists()).toBe(true)
	})

	it('renders HouseholdGoalBar component', () => {
		const wrapper = mount(Dashboard, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.household-goal-bar').exists()).toBe(true)
	})

	it('hides the manual setup entry point once the house has floors', () => {
		const wrapper = mount(Dashboard, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('[data-testid="open-setup-wizard"]').exists()).toBe(false)
	})

	it('shows the manual setup entry point while the house has no floors', async () => {
		mockRoomsState.floors = []

		const wrapper = mount(Dashboard, {
			global: {
				plugins: [pinia],
			},
		})
		await new Promise(resolve => setTimeout(resolve, 0))

		expect(wrapper.find('[data-testid="open-setup-wizard"]').exists()).toBe(true)
	})

	it('calls initializeDashboard on mount', async () => {
		const fetchHouse = jest.fn()
		const fetchFloorsWithRooms = jest.fn()

		jest.doMock('../../../src/store/house.js', () => ({
			useHouseStore: () => ({
				house: { id: 1, name: 'Test House', houseHealth: 85 },
				houseName: 'Test House',
				houseHealth: 85,
				loading: false,
				fetchHouse,
			}),
		}))

		jest.doMock('../../../src/store/rooms.js', () => ({
			useRoomsStore: () => ({
				floors: [],
				rooms: [],
				loading: false,
				fetchFloorsWithRooms,
			}),
		}))

		mount(Dashboard, {
			global: {
				plugins: [pinia],
			},
		})

		// Wait for mounted hook
		await new Promise(resolve => setTimeout(resolve, 0))
	})
})
