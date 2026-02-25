import { mount } from '@vue/test-utils'
import TaskList from '../../../src/components/Tasks/TaskList.vue'

// Mock Nextcloud l10n
jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, vars) => {
		if (vars) {
			return text.replace(/{\w+}/g, (match) => {
				const key = match.slice(1, -1)
				return vars[key] || match
			})
		}
		return text
	}),
	translatePlural: jest.fn((app, singular, plural, count) => count === 1 ? singular : plural),
}))

// Mock the Icon component to render simple text
jest.mock('../../../src/components/Common', () => ({
	Icon: {
		name: 'Icon',
		props: ['name', 'size'],
		template: '<span class="icon">{{ name }}</span>',
	},
}))

describe('TaskList', () => {
	const mockTask = (overrides = {}) => ({
		task: {
			id: 1,
			name: 'Test Task',
			description: 'Test Description',
			energyCost: 2,
			estimatedMinutes: 30,
			priority: 2,
			frequencyType: 'weekly',
			frequencyValue: 1,
			...overrides.task,
		},
		roomTask: {
			id: 1,
			roomId: 1,
			taskId: 1,
			isActive: true,
			nextDue: new Date(Date.now() + 86400000).toISOString(), // tomorrow
			assignedToUserId: null,
			...overrides.roomTask,
		},
	})

	describe('rendering states', () => {
		it('should show loading state when loading prop is true', () => {
			const wrapper = mount(TaskList, {
				props: { loading: true, roomTasks: [] },
			})

			expect(wrapper.find('.loading-state').exists()).toBe(true)
			expect(wrapper.find('.loading-spinner').exists()).toBe(true)
			expect(wrapper.text()).toContain('Loading tasks...')
		})

		it('should show empty state when no tasks and not loading', () => {
			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: [] },
			})

			expect(wrapper.find('.empty-state').exists()).toBe(true)
			expect(wrapper.find('.empty-icon').exists()).toBe(true)
			expect(wrapper.text()).toContain('No tasks yet')
			expect(wrapper.text()).toContain('Add your first task to get started')
		})

		it('should render task items when tasks provided', () => {
			const tasks = [mockTask()]
			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: tasks },
			})

			expect(wrapper.find('.task-items').exists()).toBe(true)
			expect(wrapper.findAll('.task-item')).toHaveLength(1)
		})
	})

	describe('task item rendering', () => {
		it('should display task name and priority', () => {
			const tasks = [mockTask({ task: { name: 'Clean Kitchen', priority: 3 } })]
			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: tasks },
			})

			expect(wrapper.find('.task-name').text()).toBe('Clean Kitchen')
			expect(wrapper.find('.priority-badge').exists()).toBe(true)
			expect(wrapper.find('.priority-badge').classes()).toContain('high')
		})

		it('should show correct priority labels', () => {
			const priorities = [
				{ priority: 1, label: 'Low', class: 'low' },
				{ priority: 2, label: 'Medium', class: 'medium' },
				{ priority: 3, label: 'High', class: 'high' },
			]

			priorities.forEach(({ priority, label, class: className }) => {
				const tasks = [mockTask({ task: { priority } })]
				const wrapper = mount(TaskList, {
					props: { loading: false, roomTasks: tasks },
				})

				expect(wrapper.find('.priority-badge').text()).toBe(label)
				expect(wrapper.find('.priority-badge').classes()).toContain(className)
			})
		})

		it('should show task metadata', () => {
			const tasks = [mockTask({
				task: { energyCost: 3, estimatedMinutes: 45 },
			})]
			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: tasks },
			})

			expect(wrapper.text()).toContain('3')
			expect(wrapper.text()).toContain('45m')
		})

		it('should show assigned indicator when task is assigned', () => {
			const tasks = [mockTask({ roomTask: { assignedToUserId: 5 } })]
			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: tasks },
			})

			expect(wrapper.text()).toContain('Assigned')
		})
	})

	describe('task status', () => {
		it('should show complete button for active tasks', () => {
			const tasks = [mockTask({ roomTask: { isActive: true } })]
			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: tasks },
			})

			expect(wrapper.find('.complete-button').exists()).toBe(true)
			expect(wrapper.find('.completed-check').exists()).toBe(false)
		})

		it('should show completed checkmark for inactive tasks', () => {
			const tasks = [mockTask({ roomTask: { isActive: false } })]
			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: tasks },
			})

			expect(wrapper.find('.complete-button').exists()).toBe(false)
			expect(wrapper.find('.completed-check').exists()).toBe(true)
			expect(wrapper.text()).toContain('Completed')
		})

		it('should apply overdue class to overdue tasks', () => {
			const yesterday = new Date(Date.now() - 86400000).toISOString()
			const tasks = [mockTask({ roomTask: { nextDue: yesterday, isActive: true } })]
			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: tasks },
			})

			expect(wrapper.find('.task-item').classes()).toContain('overdue')
			expect(wrapper.find('.task-due').classes()).toContain('overdue')
		})

		it('should apply completed class to inactive tasks', () => {
			const tasks = [mockTask({ roomTask: { isActive: false } })]
			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: tasks },
			})

			expect(wrapper.find('.task-item').classes()).toContain('completed')
		})
	})

	describe('user interactions', () => {
		it('should emit complete event when complete button clicked', async () => {
			const tasks = [mockTask({ roomTask: { id: 42, isActive: true } })]
			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: tasks },
			})

			await wrapper.find('.complete-button').trigger('click')

			expect(wrapper.emitted('complete')).toBeTruthy()
			expect(wrapper.emitted('complete')[0]).toEqual([42])
		})

		it('should emit edit event when edit button clicked', async () => {
			const roomTask = { id: 42, isActive: true }
			const tasks = [mockTask({ roomTask })]
			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: tasks },
			})

			await wrapper.find('.action-btn[title="Edit"]').trigger('click')

			expect(wrapper.emitted('edit')).toBeTruthy()
			expect(wrapper.emitted('edit')[0][0]).toMatchObject({ id: 42, isActive: true })
		})

		it('should emit delete event when delete button clicked', async () => {
			const tasks = [mockTask({ roomTask: { id: 42 } })]
			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: tasks },
			})

			await wrapper.find('.action-btn.delete').trigger('click')

			expect(wrapper.emitted('delete')).toBeTruthy()
			expect(wrapper.emitted('delete')[0]).toEqual([42])
		})
	})

	describe('task sorting', () => {
		it('should sort tasks by due date', () => {
			const today = new Date().toISOString()
			const tomorrow = new Date(Date.now() + 86400000).toISOString()

			const tasks = [
				mockTask({ task: { id: 2 }, roomTask: { id: 2, nextDue: tomorrow } }),
				mockTask({ task: { id: 1 }, roomTask: { id: 1, nextDue: today } }),
			]

			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: tasks },
			})

			const taskNames = wrapper.findAll('.task-name').map(el => el.text())
			expect(taskNames).toEqual(['Test Task', 'Test Task'])
		})

		it('should sort by priority when due dates are equal', () => {
			const sameDate = new Date().toISOString()

			const tasks = [
				mockTask({ task: { id: 1, name: 'Low Priority', priority: 1 }, roomTask: { id: 1, nextDue: sameDate } }),
				mockTask({ task: { id: 2, name: 'High Priority', priority: 3 }, roomTask: { id: 2, nextDue: sameDate } }),
			]

			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: tasks },
			})

			const taskNames = wrapper.findAll('.task-name').map(el => el.text())
			expect(taskNames[0]).toBe('High Priority')
		})
	})

	describe('prop validation', () => {
		it('should have correct prop types', () => {
			expect(TaskList.props.roomTasks.type).toBe(Array)
			expect(TaskList.props.roomTasks.default()).toEqual([])
			expect(TaskList.props.loading.type).toBe(Boolean)
			expect(TaskList.props.loading.default).toBe(false)
		})

		it('should handle null roomTasks gracefully', () => {
			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: null },
			})

			// Should show empty state since roomTasks is null
			expect(wrapper.find('.empty-state').exists()).toBe(true)
		})
	})
})
