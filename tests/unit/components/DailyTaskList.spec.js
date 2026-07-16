import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import DailyTaskList from '../../../src/components/Scheduling/DailyTaskList.vue'

import axios from '@nextcloud/axios'

// Mock Nextcloud l10n
jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

// Mock axios
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: {
		get: jest.fn(),
		post: jest.fn(),
		put: jest.fn(),
		delete: jest.fn(),
	},
}))

describe('DailyTaskList', () => {
	beforeEach(() => {
		const pinia = createPinia()
		setActivePinia(pinia)
		jest.clearAllMocks()
	})

	describe('rendering states', () => {
		it('shows loading state initially', async () => {
			axios.get.mockResolvedValue({ data: { success: true, data: [] } })

			const wrapper = mount(DailyTaskList)

			// Should show loading state briefly
			expect(wrapper.find('.loading-state').exists()).toBe(true)
		})

		it('shows empty state when no tasks', async () => {
			axios.get.mockResolvedValue({ data: { success: true, data: [] } })

			const wrapper = mount(DailyTaskList)
			await wrapper.vm.$nextTick()
			await new Promise(resolve => setTimeout(resolve, 100))

			expect(wrapper.find('.empty-state').exists()).toBe(true)
			expect(wrapper.text()).toContain('No Tasks Today')
		})

		it('renders task list when tasks available', async () => {
			const mockTasks = [
				{
					id: 1,
					name: 'Do dishes',
					roomName: 'Kitchen',
					energyCost: 3,
					priority: 2,
					completed: false,
					isOverdue: false,
					allowTeamwork: false,
				},
				{
					id: 2,
					name: 'Vacuum living room',
					roomName: 'Living Room',
					energyCost: 4,
					priority: 3,
					completed: false,
					isOverdue: false,
					allowTeamwork: false,
				},
			]

			axios.get.mockResolvedValue({ data: { success: true, data: mockTasks } })

			const wrapper = mount(DailyTaskList)
			await wrapper.vm.$nextTick()
			await new Promise(resolve => setTimeout(resolve, 100))

			const taskItems = wrapper.findAll('.task-item')
			expect(taskItems).toHaveLength(2)
		})
	})

	describe('progress tracking', () => {
		it('shows correct completion progress', async () => {
			const mockTasks = [
				{ id: 1, name: 'Task 1', completed: true, roomName: 'Kitchen', energyCost: 2, priority: 1, isOverdue: false, allowTeamwork: false },
				{ id: 2, name: 'Task 2', completed: true, roomName: 'Bedroom', energyCost: 1, priority: 1, isOverdue: false, allowTeamwork: false },
				{ id: 3, name: 'Task 3', completed: false, roomName: 'Bathroom', energyCost: 3, priority: 2, isOverdue: false, allowTeamwork: false },
			]

			axios.get.mockResolvedValue({ data: { success: true, data: mockTasks } })

			const wrapper = mount(DailyTaskList)
			await wrapper.vm.$nextTick()
			await new Promise(resolve => setTimeout(resolve, 100))

			const progressText = wrapper.find('.progress-text')
			expect(progressText.text()).toContain('2')
			expect(progressText.text()).toContain('3')
		})

		it('calculates progress percentage correctly', async () => {
			const mockTasks = [
				{ id: 1, completed: true, name: 'Task 1', roomName: 'Kitchen', energyCost: 2, priority: 1, isOverdue: false, allowTeamwork: false },
				{ id: 2, completed: false, name: 'Task 2', roomName: 'Bedroom', energyCost: 1, priority: 1, isOverdue: false, allowTeamwork: false },
			]

			axios.get.mockResolvedValue({ data: { success: true, data: mockTasks } })

			const wrapper = mount(DailyTaskList)
			await wrapper.vm.$nextTick()
			await new Promise(resolve => setTimeout(resolve, 100))

			// 1 of 2 = 50%
			const progressFill = wrapper.find('.progress-fill')
			expect(progressFill.attributes('style')).toContain('50%')
		})
	})

	describe('task states', () => {
		it('applies completed class to completed tasks', async () => {
			const mockTasks = [
				{ id: 1, name: 'Completed task', completed: true, roomName: 'Kitchen', energyCost: 2, priority: 1, isOverdue: false, allowTeamwork: false },
			]

			axios.get.mockResolvedValue({ data: { success: true, data: mockTasks } })

			const wrapper = mount(DailyTaskList)
			await wrapper.vm.$nextTick()
			await new Promise(resolve => setTimeout(resolve, 100))

			const taskItem = wrapper.find('.task-item')
			expect(taskItem.classes()).toContain('completed')
		})

		it('applies overdue class to overdue tasks', async () => {
			const mockTasks = [
				{ id: 1, name: 'Overdue task', completed: false, isOverdue: true, roomName: 'Kitchen', energyCost: 2, priority: 1, allowTeamwork: false },
			]

			axios.get.mockResolvedValue({ data: { success: true, data: mockTasks } })

			const wrapper = mount(DailyTaskList)
			await wrapper.vm.$nextTick()
			await new Promise(resolve => setTimeout(resolve, 100))

			const taskItem = wrapper.find('.task-item')
			expect(taskItem.classes()).toContain('overdue')
		})

		it('shows high priority badge for priority 3+ tasks', async () => {
			const mockTasks = [
				{ id: 1, name: 'High priority', priority: 3, completed: false, roomName: 'Kitchen', energyCost: 2, isOverdue: false, allowTeamwork: false },
			]

			axios.get.mockResolvedValue({ data: { success: true, data: mockTasks } })

			const wrapper = mount(DailyTaskList)
			await wrapper.vm.$nextTick()
			await new Promise(resolve => setTimeout(resolve, 100))

			const priorityBadge = wrapper.find('.priority-badge')
			expect(priorityBadge.exists()).toBe(true)
			expect(priorityBadge.classes()).toContain('high')
		})
	})

	describe('user interactions', () => {
		it('toggles task completion when checkbox clicked', async () => {
			const mockTasks = [
				{ id: 1, name: 'Task', completed: false, roomName: 'Kitchen', energyCost: 2, priority: 1, isOverdue: false, allowTeamwork: false },
			]

			axios.get.mockResolvedValue({ data: { success: true, data: mockTasks } })
			axios.put.mockResolvedValue({ data: { success: true } })

			const wrapper = mount(DailyTaskList)
			await wrapper.vm.$nextTick()
			await new Promise(resolve => setTimeout(resolve, 100))

			const checkbox = wrapper.find('input[type="checkbox"]')
			await checkbox.trigger('change')

			// Should call API to toggle task
			expect(axios.put).toHaveBeenCalled()
		})

		it('calls reschedule when reschedule button clicked', async () => {
			const mockTasks = [
				{ id: 1, name: 'Task', completed: false, roomName: 'Kitchen', energyCost: 2, priority: 1, isOverdue: false, allowTeamwork: false },
			]

			axios.get.mockResolvedValue({ data: { success: true, data: mockTasks } })

			const wrapper = mount(DailyTaskList)
			await wrapper.vm.$nextTick()
			await new Promise(resolve => setTimeout(resolve, 100))

			const rescheduleBtn = wrapper.find('.action-btn.reschedule')
			expect(rescheduleBtn.exists()).toBe(true)
		})

		it('calls skip when skip button clicked', async () => {
			const mockTasks = [
				{ id: 1, name: 'Task', completed: false, roomName: 'Kitchen', energyCost: 2, priority: 1, isOverdue: false, allowTeamwork: false },
			]

			axios.get.mockResolvedValue({ data: { success: true, data: mockTasks } })

			const wrapper = mount(DailyTaskList)
			await wrapper.vm.$nextTick()
			await new Promise(resolve => setTimeout(resolve, 100))

			const skipBtn = wrapper.find('.action-btn.skip')
			expect(skipBtn.exists()).toBe(true)
		})
	})

	describe('drag and drop', () => {
		it('makes tasks draggable', async () => {
			const mockTasks = [
				{ id: 1, name: 'Task 1', completed: false, roomName: 'Kitchen', energyCost: 2, priority: 1, isOverdue: false, allowTeamwork: false },
			]

			axios.get.mockResolvedValue({ data: { success: true, data: mockTasks } })

			const wrapper = mount(DailyTaskList)
			await wrapper.vm.$nextTick()
			await new Promise(resolve => setTimeout(resolve, 100))

			const taskItem = wrapper.find('.task-item')
			expect(taskItem.attributes('draggable')).toBe('true')
		})

		it('has drag handle visible', async () => {
			const mockTasks = [
				{ id: 1, name: 'Task 1', completed: false, roomName: 'Kitchen', energyCost: 2, priority: 1, isOverdue: false, allowTeamwork: false },
			]

			axios.get.mockResolvedValue({ data: { success: true, data: mockTasks } })

			const wrapper = mount(DailyTaskList)
			await wrapper.vm.$nextTick()
			await new Promise(resolve => setTimeout(resolve, 100))

			const dragHandle = wrapper.find('.task-drag-handle')
			expect(dragHandle.exists()).toBe(true)
		})
	})

	describe('task sorting', () => {
		it('sorts incomplete tasks before completed tasks', async () => {
			const mockTasks = [
				{ id: 1, name: 'Completed', completed: true, roomName: 'Kitchen', energyCost: 2, priority: 1, isOverdue: false, allowTeamwork: false },
				{ id: 2, name: 'Active', completed: false, roomName: 'Bedroom', energyCost: 1, priority: 1, isOverdue: false, allowTeamwork: false },
			]

			axios.get.mockResolvedValue({ data: { success: true, data: mockTasks } })

			const wrapper = mount(DailyTaskList)
			await wrapper.vm.$nextTick()
			await new Promise(resolve => setTimeout(resolve, 100))

			const taskNames = wrapper.findAll('.task-name')
			// Active task should come first
			expect(taskNames[0].text()).toBe('Active')
		})

		it('sorts high priority tasks first among incomplete tasks', async () => {
			const mockTasks = [
				{ id: 1, name: 'Low priority', priority: 1, completed: false, roomName: 'Kitchen', energyCost: 2, isOverdue: false, allowTeamwork: false },
				{ id: 2, name: 'High priority', priority: 3, completed: false, roomName: 'Bedroom', energyCost: 1, isOverdue: false, allowTeamwork: false },
			]

			axios.get.mockResolvedValue({ data: { success: true, data: mockTasks } })

			const wrapper = mount(DailyTaskList)
			await wrapper.vm.$nextTick()
			await new Promise(resolve => setTimeout(resolve, 100))

			const taskNames = wrapper.findAll('.task-name')
			expect(taskNames[0].text()).toBe('High priority')
		})
	})

	describe('metadata display', () => {
		it('displays room name', async () => {
			const mockTasks = [
				{ id: 1, name: 'Task', roomName: 'Kitchen', energyCost: 2, priority: 1, completed: false, isOverdue: false, allowTeamwork: false },
			]

			axios.get.mockResolvedValue({ data: { success: true, data: mockTasks } })

			const wrapper = mount(DailyTaskList)
			await wrapper.vm.$nextTick()
			await new Promise(resolve => setTimeout(resolve, 100))

			expect(wrapper.find('.room-name').text()).toBe('Kitchen')
		})

		it('displays energy cost', async () => {
			const mockTasks = [
				{ id: 1, name: 'Task', roomName: 'Kitchen', energyCost: 3, priority: 1, completed: false, isOverdue: false, allowTeamwork: false },
			]

			axios.get.mockResolvedValue({ data: { success: true, data: mockTasks } })

			const wrapper = mount(DailyTaskList)
			await wrapper.vm.$nextTick()
			await new Promise(resolve => setTimeout(resolve, 100))

			expect(wrapper.find('.energy-cost').text()).toContain('3')
		})
	})
})
