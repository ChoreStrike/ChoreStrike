import { mount } from '@vue/test-utils'
import { createTestingPinia } from '@pinia/testing'
import QuickStats from '../../../src/components/Dashboard/QuickStats.vue'

// Mock vue-router
jest.mock('vue-router', () => ({
	useRouter: () => ({
		push: jest.fn(),
	}),
}))

describe('QuickStats', () => {
	const createWrapper = (roomsStore = {}, roomTasksStore = {}, props = {}) => {
		return mount(QuickStats, {
			props: {
				showActions: true,
				compact: false,
				...props,
			},
			global: {
				plugins: [createTestingPinia({
					initialState: {
						rooms: {
							totalRooms: 5,
							cleanRooms: 3,
							dirtyRooms: 2,
							averageCleanliness: 75,
							totalMonsters: 2,
							loading: false,
							...roomsStore,
						},
						roomTasks: {
							totalDueTodayTasks: 3,
							totalOverdueTasks: 1,
							loading: false,
							...roomTasksStore,
						},
					},
				})],
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
			const wrapper = createWrapper({ averageCleanliness: 82 })
			expect(wrapper.text()).toContain('82%')
			expect(wrapper.text()).toContain('Avg. Cleanliness')
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

		it('shows monsters card when monsters > 0', () => {
			const wrapper = createWrapper({ totalMonsters: 5 })
			expect(wrapper.text()).toContain('5')
			expect(wrapper.text()).toContain('Monsters')
		})

		it('hides monsters card when no monsters', () => {
			const wrapper = createWrapper({ totalMonsters: 0 })
			expect(wrapper.text()).not.toContain('Monsters')
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
			const wrapper = createWrapper({ loading: true })
			const refreshBtn = wrapper.find('.action-button:not(.primary)')
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
			expect(icons[0].text()).toContain('🏠')
			expect(icons[1].text()).toContain('✨')
			expect(icons[2].text()).toContain('📅')
		})
	})
})
