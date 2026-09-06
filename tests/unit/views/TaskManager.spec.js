import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import TaskManager from '@/views/TaskManager.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

jest.mock('@nextcloud/dialogs', () => ({
	showSuccess: jest.fn(),
	showError: jest.fn(),
	showWarning: jest.fn(),
}))

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

jest.mock('@/store/rooms.js', () => ({
	useRoomsStore: () => mockRoomsStore,
}))

jest.mock('@/store/tasks.js', () => ({
	useTasksStore: () => mockTasksStore,
}))

jest.mock('@/store/roomTasks.js', () => ({
	useRoomTasksStore: () => mockRoomTasksStore,
}))

jest.mock('@/store/user.js', () => ({
	useUserStore: () => mockUserStore,
}))

jest.mock('@/components/Tasks/TaskList.vue', () => ({
	name: 'TaskList',
	props: ['roomTasks', 'loading', 'showRoom'],
	template: '<div class="task-list-stub">tasks</div>',
}))

jest.mock('@/components/Tasks/TaskEditForm.vue', () => ({
	name: 'TaskEditForm',
	props: ['task', 'loading', 'presetRoomId'],
	emits: ['submit', 'cancel'],
	template: '<div class="task-edit-form-stub">form</div>',
}))

jest.mock('@/components/Common/Modal.vue', () => ({
	name: 'Modal',
	emits: ['close'],
	template: '<div class="modal-stub"><slot /></div>',
}))

let mockRoute = { query: {} }

jest.mock('vue-router', () => ({
	useRoute: () => mockRoute,
}))

import { showSuccess, showError, showWarning } from '@nextcloud/dialogs'

const mockRoomsStore = {
	floors: [{ id: 1, name: 'Ground Floor' }],
	rooms: [{ id: 12, name: 'Kitchen', floorId: 1 }],
	fetchFloorsWithRooms: jest.fn().mockResolvedValue([]),
}

const mockTasksStore = {
	tasks: [],
	fetchTasks: jest.fn().mockResolvedValue([]),
	getTaskById: jest.fn(() => null),
}

const mockRoomTasksStore = {
	roomTasks: [],
	fetchRoomTasks: jest.fn().mockResolvedValue([]),
	createTaskAggregate: jest.fn(),
	completeRoomTask: jest.fn().mockResolvedValue({}),
	deleteRoomTask: jest.fn().mockResolvedValue(),
	totalOverdueTasks: 0,
	totalDueTodayTasks: 0,
}

const mockUserStore = {
	isHouseOwner: true,
	role: 'owner',
	canManageTasks: true,
}

describe('TaskManager', () => {
	let roomTasksStore
	let tasksStore

	beforeEach(() => {
		setActivePinia(createPinia())
		roomTasksStore = mockRoomTasksStore
		tasksStore = mockTasksStore
		mockRoute = { query: {} }
		jest.clearAllMocks()
	})

	const mountView = () => mount(TaskManager, {
		global: {
			plugins: [createPinia()],
			stubs: {
				RouterLink: { template: '<a><slot /></a>' },
			},
		},
	})

	const openCreateModal = async (wrapper) => {
		await wrapper.find('[data-testid="add-task-button"]').trigger('click')
		const form = wrapper.findComponent({ name: 'TaskEditForm' })
		expect(form.exists()).toBe(true)
		return form
	}

	it('renders the view', () => {
		const wrapper = mountView()
		expect(wrapper.find('[data-testid="task-manager-view"]').exists()).toBe(true)
	})

	it('sends ONE aggregate request on create and refreshes the room list (CS-PLAN-063 §3.5)', async () => {
		roomTasksStore.createTaskAggregate.mockResolvedValue({
			task: { id: 7, name: 'Dishes' },
			roomTask: { id: 42, roomId: 12 },
			assignees: [{ userId: 'mom' }],
		})
		const wrapper = await mountView()
		const form = await openCreateModal(wrapper)

		await form.vm.$emit('submit', {
			name: 'Dishes',
			roomId: 12,
			assigneeUserIds: ['mom'],
			energyCost: 2,
			nextDue: '2030-05-10',
		})
		await wrapper.vm.$nextTick()
		await wrapper.vm.$nextTick()

		// Exactly one request — no definition POST, no assignee PUT.
		expect(roomTasksStore.createTaskAggregate).toHaveBeenCalledTimes(1)
		expect(roomTasksStore.createTaskAggregate).toHaveBeenCalledWith({
			roomId: 12,
			task: { name: 'Dishes', energyCost: 2 },
			assigneeUserIds: ['mom'],
			nextDue: '2030-05-10',
		})
		expect(showSuccess).toHaveBeenCalled()
		expect(wrapper.vm.showAddTaskModal).toBe(false)
		expect(roomTasksStore.fetchRoomTasks).toHaveBeenCalledWith(12)
	})

	it('toasts an error and keeps the modal open when the aggregate request fails', async () => {
		roomTasksStore.createTaskAggregate.mockRejectedValue(new Error('boom'))
		const wrapper = await mountView()
		const form = await openCreateModal(wrapper)
		const refreshCallsBefore = roomTasksStore.fetchRoomTasks.mock.calls.length

		await form.vm.$emit('submit', { name: 'Dishes', roomId: 12, assigneeUserIds: [] })
		await wrapper.vm.$nextTick()

		expect(showError).toHaveBeenCalled()
		expect(showSuccess).not.toHaveBeenCalled()
		// The modal stays open with the input preserved (no orphan definition).
		expect(wrapper.vm.showAddTaskModal).toBe(true)
		expect(roomTasksStore.fetchRoomTasks.mock.calls.length).toBe(refreshCallsBefore)
	})

	it('hides the add-task entry point for children (CS-PLAN-061 D5 surface)', async () => {
		mockUserStore.canManageTasks = false
		const wrapper = await mountView()

		expect(wrapper.find('[data-testid="add-task-button"]').exists()).toBe(false)
		mockUserStore.canManageTasks = true
	})

	it('pre-filters to private tasks when routed with ?visibility=private (CS-PLAN-041)', async () => {
		mockRoute = { query: { visibility: 'private' } }
		mockRoomTasksStore.roomTasks = [
			{ id: 1, taskId: 101, isActive: true },
			{ id: 2, taskId: 102, isActive: true },
		]
		mockTasksStore.getTaskById.mockImplementation(id => ({
			101: { id: 101, name: 'Public chore', visibilityType: 'public' },
			102: { id: 102, name: 'Private chore', visibilityType: 'private' },
		}[id]))

		const wrapper = await mountView()

		expect(wrapper.vm.filterVisibility).toBe('private')
		const shown = wrapper.findComponent({ name: 'TaskList' })
		expect(shown.props('roomTasks').map(t => t.task.name)).toEqual(['Private chore'])
	})

	it('requires confirm-dialog consent and toasts before deleting a task', async () => {
		mockRoomTasksStore.roomTasks = [{ id: 5, taskId: 101, isActive: true }]
		mockTasksStore.getTaskById.mockReturnValue({ id: 101, name: 'Chore', visibilityType: 'public' })

		const wrapper = await mountView()
		wrapper.vm.handleDeleteTask(5)
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(true)
		expect(mockRoomTasksStore.deleteRoomTask).not.toHaveBeenCalled()

		await wrapper.find('[data-testid="confirm-dialog-confirm"]').trigger('click')
		await wrapper.vm.$nextTick()

		expect(mockRoomTasksStore.deleteRoomTask).toHaveBeenCalledWith(5)
		expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(false)
	})

	it('toasts an error and keeps the dialog open when the delete fails', async () => {
		mockRoomTasksStore.deleteRoomTask.mockRejectedValue(new Error('boom'))
		mockRoomTasksStore.roomTasks = [{ id: 6, taskId: 101, isActive: true }]
		mockTasksStore.getTaskById.mockReturnValue({ id: 101, name: 'Chore', visibilityType: 'public' })

		const wrapper = await mountView()
		wrapper.vm.handleDeleteTask(6)
		await wrapper.vm.$nextTick()

		await wrapper.find('[data-testid="confirm-dialog-confirm"]').trigger('click')
		await wrapper.vm.$nextTick()

		expect(showError).toHaveBeenCalled()
		expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(true)
	})
})
