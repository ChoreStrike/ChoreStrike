import { describe, it, expect, beforeEach, afterEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import Dashboard from '../../../src/views/Dashboard.vue'
import { useRoomTasksStore } from '../../../src/store/roomTasks.js'

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
	setFloorExpanded: jest.fn(),
}

jest.mock('../../../src/store/rooms.js', () => ({
	useRoomsStore: () => mockRoomsState,
}))

const mockGameStore = reactive({ wallet: null, fetchGameState: jest.fn() })

jest.mock('../../../src/store/game.js', () => ({
	useGameStore: () => mockGameStore,
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
	emits: ['toggle-private-filter', 'show-rooms'],
	template: '<div class="quick-stats">Quick Stats</div>',
}))

jest.mock('../../../src/components/Dashboard/FloorList.vue', () => ({
	name: 'FloorList',
	template: '<div class="floor-list">Floor List</div>',
}))

// Mutable so individual tests can simulate a persisted "Start empty"
// dismissal (UX-011).
const mockWizardState = { dismissed: false }

jest.mock('../../../src/components/Setup/SetupWizard.vue', () => ({
	name: 'SetupWizard',
	emits: ['close', 'applied', 'skipped'],
	template: '<div class="setup-wizard-stub" />',
	isWizardDismissedForHouse: () => mockWizardState.dismissed,
}))

describe('Dashboard.vue', () => {
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		mockWizardState.dismissed = false
		mockGameStore.wallet = null
		mockGameStore.fetchGameState.mockReset()
		mockGameStore.fetchGameState.mockResolvedValue(undefined)
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

	it('shows only the caller wallet with an exact negative fractional Coin balance after a fresh load', async () => {
		mockGameStore.fetchGameState.mockImplementation(async () => {
			mockGameStore.wallet = { bookedMinor: -1125, reservedMinor: 130, availableMinor: -1255 }
		})
		const wrapper = mount(Dashboard, { global: { plugins: [pinia] } })
		await flushPromises()
		expect(mockGameStore.fetchGameState).toHaveBeenCalledTimes(1)
		expect(wrapper.find('[data-testid="own-wallet"]').text()).toContain('Your Coins')
		expect(wrapper.find('[data-testid="own-wallet-available"]').text()).toBe('-12.55')

		wrapper.unmount()
		mockGameStore.wallet = null
		const reloaded = mount(Dashboard, { global: { plugins: [pinia] } })
		await flushPromises()
		expect(mockGameStore.fetchGameState).toHaveBeenCalledTimes(2)
		expect(reloaded.find('[data-testid="own-wallet-available"]').text()).toBe('-12.55')
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

		// The setup wizard is owner-gated (CS-PLAN-061 §3.9): a member of an
		// empty house cannot run it (apply would 403).
		const { useUserStore } = require('../../../src/store/user.js')
		useUserStore().currentUser = { id: 1, role: 'owner' }

		const wrapper = mount(Dashboard, {
			global: {
				plugins: [pinia],
			},
		})
		await new Promise(resolve => setTimeout(resolve, 0))

		expect(wrapper.find('[data-testid="open-setup-wizard"]').exists()).toBe(true)
	})

	it('hides the manual setup entry point for non-owners of an empty house (CS-PLAN-061 §3.9)', async () => {
		mockRoomsState.floors = []

		const { useUserStore } = require('../../../src/store/user.js')
		useUserStore().currentUser = { id: 2, role: 'member' }

		const wrapper = mount(Dashboard, {
			global: {
				plugins: [pinia],
			},
		})
		await new Promise(resolve => setTimeout(resolve, 0))

		expect(wrapper.find('[data-testid="open-setup-wizard"]').exists()).toBe(false)
	})

	it('does not auto-open the wizard when "Start empty" was dismissed for this house (UX-011)', async () => {
		mockRoomsState.floors = []
		mockWizardState.dismissed = true

		const { useUserStore } = require('../../../src/store/user.js')
		useUserStore().currentUser = { id: 1, role: 'owner' }

		const wrapper = mount(Dashboard, {
			global: {
				plugins: [pinia],
			},
		})
		await new Promise(resolve => setTimeout(resolve, 0))

		expect(wrapper.find('.setup-wizard-stub').exists()).toBe(false)
		// The manual entry point stays available while the house is empty.
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

	it('renders the shared error state with retry when initialization failed entirely (CS-PLAN-044)', async () => {
		const wrapper = mount(Dashboard, {
			global: {
				plugins: [pinia],
			},
		})
		await wrapper.setData({ initError: true })
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="dashboard-error"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="open-setup-wizard"]').exists()).toBe(false)

		// Retry re-runs initialization; with the store mocks resolving, the
		// dashboard content returns.
		await wrapper.find('[data-testid="error-retry"]').trigger('click')
		await new Promise(resolve => setTimeout(resolve, 0))

		expect(wrapper.find('[data-testid="dashboard-error"]').exists()).toBe(false)
		expect(wrapper.find('.dashboard-header').exists()).toBe(true)
	})

	it('loads the task counts of the room cards with one house-wide read (CS-PLAN-084 WP-C)', async () => {
		const roomTasksStore = useRoomTasksStore()
		roomTasksStore.fetchHouseRoomTasks = jest.fn().mockResolvedValue([])

		mount(Dashboard, { global: { plugins: [pinia] } })
		await flushPromises()

		expect(roomTasksStore.fetchHouseRoomTasks).toHaveBeenCalledTimes(1)
		expect(roomTasksStore.fetchHouseRoomTasks).toHaveBeenCalledWith()
	})

	it('expands every floor and focuses the floor list when a room tile asks for the rooms', async () => {
		mockRoomsState.floors = [{ id: 1, name: 'Ground Floor' }, { id: 2, name: 'First Floor' }]
		mockRoomsState.setFloorExpanded.mockClear()
		const wrapper = mount(Dashboard, { global: { plugins: [pinia] }, attachTo: document.body })
		await flushPromises()

		wrapper.findComponent({ name: 'QuickStats' }).vm.$emit('show-rooms')
		await flushPromises()

		expect(mockRoomsState.setFloorExpanded.mock.calls).toEqual([[1], [2]])
		const floorList = wrapper.find('.floor-list')
		expect(floorList.attributes('tabindex')).toBe('-1')
		expect(document.activeElement).toBe(floorList.element)
		wrapper.unmount()
	})
})
