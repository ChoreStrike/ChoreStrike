import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import QuickStats from '../../../src/components/Dashboard/QuickStats.vue'
import { useRoomsStore } from '../../../src/store/rooms.js'
import { useRoomTasksStore } from '../../../src/store/roomTasks.js'

// Mock vue-router
jest.mock('vue-router', () => ({
	useRouter: () => ({
		push: jest.fn(),
	}),
}))

describe('QuickStats', () => {
	let roomsStore
	let roomTasksStore
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		roomsStore = useRoomsStore()
		roomTasksStore = useRoomTasksStore()
	})

	const createWrapper = (roomsData = {}, roomTasksData = {}, props = {}, roomsOverride = null) => {
		// Set up rooms store with mock rooms array
		let mockRooms = []
		const totalRooms = roomsData.totalRooms !== undefined ? roomsData.totalRooms : 5
		const cleanRooms = roomsData.cleanRooms !== undefined ? roomsData.cleanRooms : 3
		const dirtyRooms = roomsData.dirtyRooms !== undefined ? roomsData.dirtyRooms : 2
		
		// Use override if provided, otherwise create mock rooms
		if (roomsOverride) {
			mockRooms = roomsOverride
		} else {
			// Ensure totalRooms is at least as big as cleanRooms
			const actualTotalRooms = Math.max(totalRooms, cleanRooms, dirtyRooms)

			// Create mock rooms based on the parameters
			for (let i = 0; i < actualTotalRooms; i++) {
				const isClean = i < cleanRooms
				mockRooms.push({
					id: i + 1,
					name: `Room ${i + 1}`,
					cleanliness: isClean ? 80 : 40,
				})
			}
		}
		
		roomsStore.rooms = mockRooms
		
		// Set up room tasks store with mock roomTasks array
		const mockRoomTasks = []
		const totalDueTodayTasks = roomTasksData.totalDueTodayTasks !== undefined ? roomTasksData.totalDueTodayTasks : 3
		const totalOverdueTasks = roomTasksData.totalOverdueTasks !== undefined ? roomTasksData.totalOverdueTasks : 1
		
		// Create mock room tasks
		const today = new Date()
		today.setHours(0, 0, 0, 0)
		const tomorrow = new Date(today)
		tomorrow.setDate(tomorrow.getDate() + 1)
		const yesterday = new Date(today)
		yesterday.setDate(yesterday.getDate() - 1)
		
		// Add due today tasks
		for (let i = 0; i < totalDueTodayTasks; i++) {
			mockRoomTasks.push({
				id: i + 1,
				name: `Task ${i + 1}`,
				isActive: true,
				nextDue: tomorrow.toISOString(),
			})
		}
		
		// Add overdue tasks
		for (let i = 0; i < totalOverdueTasks; i++) {
			mockRoomTasks.push({
				id: totalDueTodayTasks + i + 1,
				name: `Overdue Task ${i + 1}`,
				isActive: true,
				nextDue: yesterday.toISOString(),
			})
		}
		
		roomTasksStore.roomTasks = mockRoomTasks
		
		return mount(QuickStats, {
			props: {
				showActions: true,
				compact: false,
				...props,
			},
			global: {
				plugins: [pinia],
			},
		})
	}

	describe('rendering', () => {
		it('renders component correctly', () => {
			const wrapper = createWrapper()
			expect(wrapper.exists()).toBe(true)
		})

		it('has data-testid attribute', () => {
			const wrapper = createWrapper()
			expect(wrapper.attributes('data-testid')).toBe('quick-stats')
		})

		it('displays total rooms count', () => {
			const wrapper = createWrapper({ totalRooms: 8 })
			expect(wrapper.text()).toContain('8')
			expect(wrapper.text()).toContain('Total Rooms')
		})

		it('displays clean rooms count', () => {
			const wrapper = createWrapper({ cleanRooms: 6 })
			expect(wrapper.text()).toContain('6')
			expect(wrapper.text()).toContain('Clean Rooms')
		})

		it('displays due today tasks', () => {
			const wrapper = createWrapper({}, { totalDueTodayTasks: 4 })
			expect(wrapper.text()).toContain('4')
			expect(wrapper.text()).toContain('Due Today')
		})

		it('displays average cleanliness with %', () => {
			// Create rooms with specific cleanliness to get 82% average
			const rooms = [
				{ id: 1, name: 'Room 1', cleanliness: 80, monsterCount: 0 },
				{ id: 2, name: 'Room 2', cleanliness: 85, monsterCount: 0 },
				{ id: 3, name: 'Room 3', cleanliness: 82, monsterCount: 0 },
				{ id: 4, name: 'Room 4', cleanliness: 81, monsterCount: 0 },
				{ id: 5, name: 'Room 5', cleanliness: 82, monsterCount: 0 },
			]
			
			const wrapper = createWrapper({}, {}, {}, rooms)
			
			expect(wrapper.text()).toContain('82%')
			expect(wrapper.text()).toContain('Avg Cleanliness')
		})
	})

	describe('conditional rendering', () => {
		it('shows overdue card when overdue tasks > 0', () => {
			const wrapper = createWrapper({}, { totalOverdueTasks: 3 })
			const overdueCard = wrapper.find('.stat-card.urgent')
			expect(overdueCard.exists()).toBe(true)
			expect(wrapper.text()).toContain('3')
			expect(wrapper.text()).toContain('Overdue')
		})

		it('hides overdue card when no overdue tasks', () => {
			const wrapper = createWrapper({}, { totalOverdueTasks: 0 })
			const overdueCard = wrapper.find('.stat-card.urgent')
			expect(overdueCard.exists()).toBe(false)
		})
	})

	describe('action buttons', () => {
		it('shows action buttons by default', () => {
			const wrapper = createWrapper()
			expect(wrapper.find('.stats-actions').exists()).toBe(true)
			expect(wrapper.text()).toContain('Refresh')
			expect(wrapper.text()).toContain('View All Tasks')
		})

		it('hides action buttons when showActions is false', () => {
			const wrapper = createWrapper({}, {}, { showActions: false })
			expect(wrapper.find('.stats-actions').exists()).toBe(false)
		})

		it('disables refresh button when loading', () => {
			roomsStore.loading = true
			const wrapper = createWrapper()
			const refreshBtn = wrapper.find('.action-button')
			expect(refreshBtn.attributes('disabled')).toBeDefined()
		})
	})

	describe('compact mode', () => {
		it('applies compact class when compact prop is true', () => {
			const wrapper = createWrapper({}, {}, { compact: true })
			expect(wrapper.classes()).toContain('compact')
		})
	})

	describe('icons', () => {
		it('displays correct icons for each stat', () => {
			const wrapper = createWrapper()
			const icons = wrapper.findAll('.stat-icon')
			
			expect(icons.length).toBeGreaterThanOrEqual(4)
			// Check that icons are rendered (they're Icon components, not text)
			expect(icons[0].exists()).toBe(true)
			expect(icons[1].exists()).toBe(true)
			expect(icons[2].exists()).toBe(true)
		})
	})
})
