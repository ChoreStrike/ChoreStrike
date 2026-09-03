import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import TaskList from '../../../src/components/Tasks/TaskList.vue'
import { useUserStore } from '../../../src/store/user.js'
import { useRoomTasksStore } from '../../../src/store/roomTasks.js'

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

// Mock the checklist store
jest.mock('../../../src/store/checklist.js', () => ({
	useChecklistStore: jest.fn(() => ({
		fetchTaskItems: jest.fn(),
		fetchRoomTaskProgress: jest.fn(),
		toggleItem: jest.fn(),
		addItem: jest.fn(),
		updateItem: jest.fn(),
		deleteItem: jest.fn(),
		reorderItems: jest.fn(),
		getTaskItems: jest.fn(() => []),
		getRoomTaskProgress: jest.fn(() => []),
	})),
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
			assignees: [],
			...overrides.roomTask,
		},
	})

	beforeEach(() => {
		// Create and set active Pinia instance for each test
		const pinia = createPinia()
		setActivePinia(pinia)
	})

	describe('rendering states', () => {
		it('should show loading state when loading prop is true', () => {
			const wrapper = mount(TaskList, {
				props: { loading: true, roomTasks: [] },
			})

			expect(wrapper.find('.skeleton-loading').exists()).toBe(true)
			expect(wrapper.findAll('.skeleton-task-item')).toHaveLength(5)
			expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(false)
		})

		it('should show empty state when no tasks and not loading', () => {
			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: [] },
			})

			expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(true)
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
				{ priority: 2, label: 'Normal', class: 'medium' },
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
			const tasks = [mockTask({ roomTask: { assignees: ['user-5'] } })]
			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: tasks },
			})

			expect(wrapper.text()).toContain('Assigned')
		})
	})

	describe('stable testids (CS-PLAN-015)', () => {
		it('renders data-testid="task-item" on every task row', () => {
			const tasks = [
				mockTask({ task: { id: 1 }, roomTask: { id: 1 } }),
				mockTask({ task: { id: 2 }, roomTask: { id: 2 } }),
			]
			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: tasks },
			})

			expect(wrapper.findAll('[data-testid="task-item"]')).toHaveLength(2)
		})

		it('renders the visibility-crown-icon testid on admin-only tasks', () => {
			const tasks = [mockTask({ task: { visibilityType: 'admin-only' } })]
			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: tasks },
			})

			expect(wrapper.find('[data-testid="visibility-crown-icon"]').exists()).toBe(true)
		})

		it('renders the visibility-lock-icon testid on private tasks (CS-PLAN-051)', () => {
			const tasks = [mockTask({ task: { visibilityType: 'private' } })]
			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: tasks },
			})

			expect(wrapper.find('[data-testid="visibility-lock-icon"]').exists()).toBe(true)
		})

		it('does not render the crown or lock testid on public tasks', () => {
			const tasks = [mockTask({ task: { visibilityType: 'public' } })]
			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: tasks },
			})

			expect(wrapper.find('[data-testid="visibility-crown-icon"]').exists()).toBe(false)
			expect(wrapper.find('[data-testid="visibility-lock-icon"]').exists()).toBe(false)
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
			expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(true)
		})
	})

	describe('bulk assignment + checklist editor gating (CS-PLAN-042)', () => {
		const seedMembers = () => {
			const userStore = useUserStore()
			userStore.houseUsers = [
				{ nextcloudUserId: 'u1', displayName: 'Alice' },
				{ nextcloudUserId: 'u2', displayName: 'Bob' },
			]
			return userStore
		}

		it('hides the bulk bar until a task is selected', () => {
			seedMembers()
			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: [mockTask()] },
			})

			expect(wrapper.find('[data-testid="bulk-assign-bar"]').exists()).toBe(false)
		})

		it('shows the bulk bar when a task row is selected', async () => {
			seedMembers()
			const tasks = [
				mockTask({ roomTask: { id: 1 } }),
				mockTask({ task: { id: 2 }, roomTask: { id: 2 } }),
			]
			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: tasks },
			})

			await wrapper.find('[data-testid="select-task-1"]').setValue(true)

			const bar = wrapper.find('[data-testid="bulk-assign-bar"]')
			expect(bar.exists()).toBe(true)
			expect(bar.text()).toContain('1 selected')

			await wrapper.find('[data-testid="select-task-2"]').setValue(true)
			expect(wrapper.find('[data-testid="bulk-assign-bar"]').text()).toContain('2 selected')
		})

		it('issues one bulk call for the selection and clears it on success', async () => {
			seedMembers()
			const tasks = [mockTask({ roomTask: { id: 1 } }), mockTask({ task: { id: 2 }, roomTask: { id: 2 } })]
			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: tasks },
			})
			const roomTasksStore = useRoomTasksStore()
			roomTasksStore.bulkAssignTasks = jest.fn().mockResolvedValue([{ id: 1 }, { id: 2 }])

			await wrapper.find('[data-testid="select-task-1"]').setValue(true)
			await wrapper.find('[data-testid="select-task-2"]').setValue(true)
			await wrapper.find('[data-testid="bulk-assign-target"]').setValue('u1')
			await wrapper.find('[data-testid="bulk-assign-button"]').trigger('click')
			await flushPromises()

			expect(roomTasksStore.bulkAssignTasks).toHaveBeenCalledTimes(1)
			expect(roomTasksStore.bulkAssignTasks).toHaveBeenCalledWith([1, 2], 'u1')
			expect(wrapper.find('[data-testid="bulk-assign-bar"]').exists()).toBe(false)
		})

		it('enables the checklist editor for owners', async () => {
			const userStore = seedMembers()
			userStore.currentUser = { id: 1, role: 'owner', nextcloudUserId: 'u1' }

			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: [mockTask()] },
			})
			// Expand the task row to render TaskChecklist
			await wrapper.findAll('.action-btn')[1].trigger('click')

			expect(wrapper.findComponent({ name: 'TaskChecklist' }).props('canEdit')).toBe(true)
		})

		it('keeps the checklist editor locked for members', async () => {
			const userStore = seedMembers()
			userStore.currentUser = { id: 2, role: 'member', nextcloudUserId: 'u2' }

			const wrapper = mount(TaskList, {
				props: { loading: false, roomTasks: [mockTask()] },
			})
			await wrapper.findAll('.action-btn')[1].trigger('click')

			expect(wrapper.findComponent({ name: 'TaskChecklist' }).props('canEdit')).toBe(false)
		})
	})
})
