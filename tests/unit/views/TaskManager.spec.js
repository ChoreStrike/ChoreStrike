import { describe, it, expect, vi, beforeEach } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import TaskManager from '../../../src/views/TaskManager.vue'

// Mock vue-router
jest.mock('vue-router', () => ({
	useRouter: () => ({
		push: jest.fn(),
	}),
	useRoute: () => ({
		params: {},
		query: {},
	}),
}))

// Mock stores
jest.mock('../../../src/store/roomTasks.js', () => ({
	useRoomTasksStore: () => ({
		roomTasks: [
			{ id: 1, name: 'Clean Kitchen', isActive: true, priority: 'urgent', difficulty: 3, nextDue: new Date().toISOString() },
			{ id: 2, name: 'Vacuum', isActive: true, priority: 'normal', difficulty: 2, nextDue: new Date(Date.now() + 86400000).toISOString() },
		],
		totalOverdueTasks: 0,
		totalDueTodayTasks: 1,
		fetchRoomTasks: jest.fn(),
	}),
}))

jest.mock('../../../src/store/rooms.js', () => ({
	useRoomsStore: () => ({
		floors: [],
		rooms: [],
		fetchFloorsWithRooms: jest.fn(),
	}),
}))

// Mock child components
jest.mock('../../../src/components/Common/Icon.vue', () => ({
	name: 'Icon',
	props: ['name', 'size'],
	template: '<span class="icon">{{ name }}</span>',
}))

jest.mock('../../../src/components/Tasks/TaskList.vue', () => ({
	name: 'TaskList',
	props: ['tasks', 'loading', 'showRoom'],
	emits: ['complete', 'edit', 'delete'],
	template: '<div class="task-list">Tasks: {{ tasks.length }}</div>',
}))

jest.mock('../../../src/components/Tasks/TaskForm.vue', () => ({
	name: 'TaskForm',
	props: ['task'],
	emits: ['submit', 'cancel'],
	template: '<div class="task-form">Task Form</div>',
}))

jest.mock('../../../src/components/Common/Modal.vue', () => ({
	name: 'Modal',
	emits: ['close'],
	template: '<div class="modal-overlay"><slot /><button class="close-btn" @click="$emit(\'close\')">X</button></div>',
}))

describe('TaskManager.vue', () => {
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
	})

	it('renders task manager component', () => {
		const wrapper = mount(TaskManager, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.task-manager').exists()).toBe(true)
	})

	it('displays header with title', () => {
		const wrapper = mount(TaskManager, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.manager-header h1').text()).toContain('Task Manager')
	})

	it('has new task button', () => {
		const wrapper = mount(TaskManager, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.primary-button').text()).toContain('New Task')
	})

	it('renders filters section', () => {
		const wrapper = mount(TaskManager, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.filters-section').exists()).toBe(true)
	})

	it('has status filter dropdown', () => {
		const wrapper = mount(TaskManager, {
			global: {
				plugins: [pinia],
			},
		})

		const selects = wrapper.findAll('.filter-select')
		expect(selects.length).toBeGreaterThan(0)
	})

	it('has priority filter dropdown', () => {
		const wrapper = mount(TaskManager, {
			global: {
				plugins: [pinia],
			},
		})

		const selects = wrapper.findAll('.filter-select')
		expect(selects.length).toBeGreaterThanOrEqual(2)
	})

	it('has difficulty filter dropdown', () => {
		const wrapper = mount(TaskManager, {
			global: {
				plugins: [pinia],
			},
		})

		const selects = wrapper.findAll('.filter-select')
		expect(selects.length).toBeGreaterThanOrEqual(3)
	})

	it('has search input', () => {
		const wrapper = mount(TaskManager, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.search-input').exists()).toBe(true)
	})

	it('renders stats bar', () => {
		const wrapper = mount(TaskManager, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.stats-bar').exists()).toBe(true)
	})

	it('displays total tasks count', () => {
		const wrapper = mount(TaskManager, {
			global: {
				plugins: [pinia],
			},
		})

		const totalStat = wrapper.findAll('.stat').at(0)
		expect(totalStat.exists()).toBe(true)
	})

	it('renders TaskList component', () => {
		const wrapper = mount(TaskManager, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.task-list').exists()).toBe(true)
	})

	it('opens add task modal when new task button clicked', async () => {
		const wrapper = mount(TaskManager, {
			global: {
				plugins: [pinia],
			},
		})

		await wrapper.find('.manager-header .primary-button').trigger('click')
		expect(wrapper.vm.showAddTaskModal).toBe(true)
	})

	it('filters tasks by search query', async () => {
		const wrapper = mount(TaskManager, {
			global: {
				plugins: [pinia],
			},
		})

		await wrapper.find('.search-input').setValue('Kitchen')
		expect(wrapper.vm.searchQuery).toBe('Kitchen')
	})

	it('filters tasks by status', async () => {
		const wrapper = mount(TaskManager, {
			global: {
				plugins: [pinia],
			},
		})

		const statusSelect = wrapper.findAll('.filter-select').at(0)
		await statusSelect.setValue('active')
		expect(wrapper.vm.filterStatus).toBe('active')
	})

	it('renders localized difficulty labels', () => {
		const wrapper = mount(TaskManager, {
			global: {
				plugins: [pinia],
			},
		})

		const options = wrapper.find('[data-testid="difficulty-filter"]').findAll('option')
		expect(options.at(1).text()).toContain('Level 1 (Quick)')
		expect(options.at(5).text()).toContain('Level 5 (Major)')
	})
})
