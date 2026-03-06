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
			{ id: 1, roomId: 1, taskId: 10, isActive: true, nextDue: new Date().toISOString() },
			{ id: 2, roomId: 2, taskId: 20, isActive: true, nextDue: new Date(Date.now() + 86400000).toISOString() },
		],
		totalOverdueTasks: 0,
		totalDueTodayTasks: 1,
		fetchRoomTasks: jest.fn(),
		completeRoomTask: jest.fn(),
		deleteRoomTask: jest.fn(),
	}),
}))

jest.mock('../../../src/store/rooms.js', () => ({
	useRoomsStore: () => ({
		floors: [],
		rooms: [{ id: 1 }, { id: 2 }],
		fetchFloorsWithRooms: jest.fn(),
	}),
}))

jest.mock('../../../src/store/tasks.js', () => ({
	useTasksStore: () => ({
		fetchTasks: jest.fn(),
		createTask: jest.fn(),
		updateTask: jest.fn(),
		getTaskById: jest.fn((id) => {
			const tasks = {
				10: { id: 10, name: 'Clean Kitchen', energyCost: 3, priority: 3, frequencyType: 'weekly', frequencyValue: 1 },
				20: { id: 20, name: 'Vacuum', energyCost: 2, priority: 2, frequencyType: 'weekly', frequencyValue: 1 },
			}
			return tasks[id] || null
		}),
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
	props: ['roomTasks', 'loading', 'showRoom'],
	emits: ['complete', 'edit', 'delete'],
	template: '<div class="task-list">Tasks: {{ roomTasks.length }}</div>',
}))

jest.mock('../../../src/components/Tasks/TaskEditForm.vue', () => ({
	name: 'TaskEditForm',
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
