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
	useUserStore: () => ({
		isHouseOwner: true,
	}),
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
	createTaskWithRoomTask: jest.fn(),
	completeRoomTask: jest.fn().mockResolvedValue({}),
	deleteRoomTask: jest.fn().mockResolvedValue(),
	totalOverdueTasks: 0,
	totalDueTodayTasks: 0,
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

	it('chains definition, room task and assignees on create and refreshes the room list', async () => {
		roomTasksStore.createTaskWithRoomTask.mockResolvedValue({
			task: { id: 7 },
			roomTask: { id: 42, roomId: 12 },
			roomTaskError: null,
			assignError: null,
		})
		const wrapper = await mountView()
		const form = await openCreateModal(wrapper)

		await form.vm.$emit('submit', {
			name: 'Dishes',
			roomId: 12,
			assigneeUserIds: ['mom'],
			energyCost: 2,
		})
		await wrapper.vm.$nextTick()
		await wrapper.vm.$nextTick()

		expect(roomTasksStore.createTaskWithRoomTask).toHaveBeenCalledWith(
			{ name: 'Dishes', energyCost: 2 },
			12,
			['mom'],
		)
		expect(showSuccess).toHaveBeenCalled()
		expect(wrapper.vm.showAddTaskModal).toBe(false)
		expect(roomTasksStore.fetchRoomTasks).toHaveBeenCalledWith(12)
	})

	it('toasts an error and keeps the modal open when the definition creation fails', async () => {
		roomTasksStore.createTaskWithRoomTask.mockRejectedValue(new Error('boom'))
		const wrapper = await mountView()
		const form = await openCreateModal(wrapper)

		await form.vm.$emit('submit', { name: 'Dishes', roomId: 12, assigneeUserIds: [] })
		await wrapper.vm.$nextTick()

		expect(showError).toHaveBeenCalled()
		expect(wrapper.vm.showAddTaskModal).toBe(true)
	})

	it('toasts an error and keeps the modal open when the room task materialization fails', async () => {
		roomTasksStore.createTaskWithRoomTask.mockResolvedValue({
			task: { id: 7 },
			roomTask: null,
			roomTaskError: new Error('no room task'),
			assignError: null,
		})
		const wrapper = await mountView()
		const form = await openCreateModal(wrapper)
		const refreshCallsBefore = roomTasksStore.fetchRoomTasks.mock.calls.length

		await form.vm.$emit('submit', { name: 'Dishes', roomId: 12, assigneeUserIds: [] })
		await wrapper.vm.$nextTick()

		expect(showError).toHaveBeenCalled()
		expect(showSuccess).not.toHaveBeenCalled()
		expect(wrapper.vm.showAddTaskModal).toBe(true)
		// No refresh beyond the initial mount load
		expect(roomTasksStore.fetchRoomTasks.mock.calls.length).toBe(refreshCallsBefore)
	})

	it('closes the modal with a warning toast when only the assignment fails (partial success)', async () => {
		roomTasksStore.createTaskWithRoomTask.mockResolvedValue({
			task: { id: 7 },
			roomTask: { id: 42, roomId: 12 },
			roomTaskError: null,
			assignError: new Error('assign boom'),
		})
		const wrapper = await mountView()
		const form = await openCreateModal(wrapper)

		await form.vm.$emit('submit', { name: 'Dishes', roomId: 12, assigneeUserIds: ['mom'] })
		await wrapper.vm.$nextTick()

		expect(showWarning).toHaveBeenCalled()
		expect(wrapper.vm.showAddTaskModal).toBe(false)
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
