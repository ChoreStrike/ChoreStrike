import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import TaskManager from '@/views/TaskManager.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, vars) => (vars
		? text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? String(vars[key]) : match))
		: text)),
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
	props: ['roomTasks', 'loading', 'showRoom', 'compact'],
	template: '<div class="task-list-stub">tasks</div>',
}))

jest.mock('@/components/Tasks/TaskEditForm.vue', () => ({
	name: 'TaskEditForm',
	props: ['task', 'loading', 'presetRoomId', 'initialRoomId', 'initialFloorId', 'initialAssigneeIds'],
	emits: ['submit', 'cancel'],
	template: '<div class="task-edit-form-stub">form</div>',
}))

jest.mock('@/components/Common/Modal.vue', () => ({
	name: 'Modal',
	emits: ['close'],
	template: '<div class="modal-stub"><slot /></div>',
}))

let mockRoute = { query: {} }
const mockRouter = { replace: jest.fn().mockResolvedValue() }

jest.mock('vue-router', () => ({
	useRoute: () => mockRoute,
	useRouter: () => mockRouter,
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
	finishedRoomTasks: [],
	fetchInactiveHouseRoomTasks: jest.fn().mockResolvedValue([]),
	fetchRoomTasks: jest.fn().mockResolvedValue([]),
	fetchHouseRoomTasks: jest.fn().mockResolvedValue([]),
	fetchDueTodayTasks: jest.fn().mockResolvedValue([]),
	fetchOverdueTasks: jest.fn().mockResolvedValue([]),
	createTaskAggregate: jest.fn(),
	completeRoomTask: jest.fn().mockResolvedValue({}),
	deleteRoomTask: jest.fn().mockResolvedValue(),
	totalOverdueTasks: 0,
	totalDueTodayTasks: 0,
}

const mockUserStore = {
	currentUser: { nextcloudUserId: 'mom' },
	houseUsers: [{ nextcloudUserId: 'mom', displayName: 'Parent' }, { nextcloudUserId: 'teen', displayName: 'Teen' }],
	fetchHouseUsers: jest.fn().mockResolvedValue([]),
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
		mockRoomTasksStore.finishedRoomTasks = []
		mockUserStore.currentUser = { nextcloudUserId: 'mom' }
		mockUserStore.houseUsers = [{ nextcloudUserId: 'mom', displayName: 'Parent' }, { nextcloudUserId: 'teen', displayName: 'Teen' }]
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
		await flushPromises()
		await wrapper.find('[data-testid="add-task-button"]').trigger('click')
		const form = wrapper.findComponent({ name: 'TaskEditForm' })
		expect(form.exists()).toBe(true)
		return form
	}

	it('renders the view', () => {
		const wrapper = mountView()
		expect(wrapper.find('[data-testid="task-manager-view"]').exists()).toBe(true)
	})

	it('keeps tasks usable when member names fail and retries only the member read', async () => {
		mockUserStore.houseUsers = []
		mockUserStore.fetchHouseUsers.mockRejectedValueOnce(new Error('offline'))
		const wrapper = mountView()
		await flushPromises()
		expect(wrapper.get('[data-testid="member-names-error"]').text()).toContain('Failed to load members')
		expect(wrapper.find('[data-testid="task-manager-error"]').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'TaskList' }).exists()).toBe(true)
		expect(wrapper.get('[data-testid="person-filter"]').element.disabled).toBe(true)
		const reads = mockRoomTasksStore.fetchHouseRoomTasks.mock.calls.length
		await wrapper.get('[data-testid="member-names-error"] button').trigger('click')
		await flushPromises()
		expect(wrapper.find('[data-testid="member-names-error"]').exists()).toBe(false)
		expect(wrapper.get('[data-testid="person-filter"]').element.disabled).toBe(false)
		expect(mockRoomTasksStore.fetchHouseRoomTasks).toHaveBeenCalledTimes(reads)
	})

	it('defaults to own and unassigned tasks with directly operable scope and person filters', async () => {
		mockRoomTasksStore.roomTasks = [
			{ id: 1, taskId: 101, roomId: 12, assignees: ['mom'] },
			{ id: 2, taskId: 102, roomId: 12, assignees: [] },
			{ id: 3, taskId: 103, roomId: 12, assignees: ['teen'] },
		]
		const wrapper = mountView()
		await flushPromises()
		const shown = () => wrapper.findComponent({ name: 'TaskList' }).props('roomTasks').map(item => item.roomTask.id)
		expect(shown()).toEqual([1, 2])
		expect(wrapper.get('[data-testid="tasks-filter-mine"]').attributes('aria-pressed')).toBe('true')
		await wrapper.get('[data-testid="tasks-filter-unassigned"]').trigger('click')
		expect(shown()).toEqual([2])
		await wrapper.get('[data-testid="tasks-filter-all"]').trigger('click')
		expect(shown()).toEqual([1, 2, 3])
		await wrapper.get('[data-testid="person-filter"]').setValue('teen')
		expect(shown()).toEqual([3])
		await wrapper.get('[data-testid="tasks-filter-mine"]').trigger('click')
		expect(shown()).toEqual([1, 2])
		expect(wrapper.get('[data-testid="person-filter"]').element.value).toBe('')
	})

	it('keeps assigned rows visible while the actor identity is unresolved', async () => {
		mockUserStore.currentUser = null
		mockRoomTasksStore.roomTasks = [
			{ id: 1, taskId: 101, roomId: 12, assignees: ['mom'] },
			{ id: 2, taskId: 102, roomId: 12, assignees: [] },
			{ id: 3, taskId: 103, roomId: 12, assignees: ['teen'] },
		]
		const wrapper = mountView()
		await flushPromises()
		const shown = () => wrapper.findComponent({ name: 'TaskList' }).props('roomTasks').map(item => item.roomTask.id)
		expect(shown()).toEqual([1, 2, 3])
	})

	it('combines floor and room filters and clears a room when switching floors', async () => {
		mockRoomsStore.floors = [{ id: 1, name: 'Ground Floor' }, { id: 2, name: 'Upstairs' }]
		mockRoomsStore.rooms = [{ id: 12, floorId: 1, name: 'Kitchen' }, { id: 13, floorId: 2, name: 'Bedroom' }]
		mockRoomTasksStore.roomTasks = [{ id: 1, taskId: 101, roomId: 12 }, { id: 2, taskId: 102, roomId: 13 }]
		const wrapper = mountView()
		await flushPromises()
		const shown = () => wrapper.findComponent({ name: 'TaskList' }).props('roomTasks').map(item => item.roomTask.id)
		await wrapper.get('[data-testid="room-filter"]').setValue('12')
		expect(shown()).toEqual([1])
		await wrapper.get('[data-testid="floor-filter"]').setValue('2')
		expect(shown()).toEqual([2])
		expect(wrapper.get('[data-testid="room-filter"]').element.value).toBe('')
		expect(wrapper.get('[data-testid="room-filter"]').text()).not.toContain('Kitchen')
	})

	// FEAT-UX-038 (D-088-13): the New Task dialog takes over the active
	// floor/room/person filters; the scopes preselect nobody.
	it.each([
		['a room filter', { room: '12' }, { roomId: 12, floorId: null, assigneeIds: [] }],
		['a floor-only filter', { floor: '1' }, { roomId: null, floorId: 1, assigneeIds: [] }],
		['a person filter', { scope: 'all', person: 'teen' }, { roomId: null, floorId: null, assigneeIds: ['teen'] }],
		['the unassigned scope', { scope: 'unassigned' }, { roomId: null, floorId: null, assigneeIds: [] }],
		['no filter', {}, { roomId: null, floorId: null, assigneeIds: [] }],
	])('prefills the create form from %s', async (_label, { scope, floor, room, person }, expected) => {
		mockRoomsStore.floors = [{ id: 1, name: 'Ground Floor' }]
		mockRoomsStore.rooms = [{ id: 12, name: 'Kitchen', floorId: 1 }]
		const wrapper = mountView()
		await flushPromises()
		if (scope) {
			await wrapper.get(`[data-testid="tasks-filter-${scope}"]`).trigger('click')
		}
		if (floor) {
			await wrapper.get('[data-testid="floor-filter"]').setValue(floor)
		}
		if (room) {
			await wrapper.get('[data-testid="room-filter"]').setValue(room)
		}
		if (person) {
			await wrapper.get('[data-testid="person-filter"]').setValue(person)
		}
		const form = await openCreateModal(wrapper)
		expect(form.props('initialRoomId')).toBe(expected.roomId)
		expect(form.props('initialFloorId')).toBe(expected.floorId)
		expect(form.props('initialAssigneeIds')).toEqual(expected.assigneeIds)
		expect(form.props('presetRoomId')).toBeUndefined()
	})

	it.each(['definitions', 'instances'])('does not allow creation before the initial %s read finishes', async (resource) => {
		let release
		const read = resource === 'definitions' ? mockTasksStore.fetchTasks : mockRoomTasksStore.fetchHouseRoomTasks
		read.mockImplementationOnce(() => new Promise(resolve => { release = resolve }))
		const wrapper = mountView()
		await flushPromises()
		const button = wrapper.find('[data-testid="add-task-button"]')
		expect(button.element.disabled).toBe(true)
		await button.trigger('click')
		expect(wrapper.findComponent({ name: 'TaskEditForm' }).exists()).toBe(false)
		expect(mockRoomTasksStore.createTaskAggregate).not.toHaveBeenCalled()
		release([])
		await flushPromises()
		expect(button.element.disabled).toBe(false)
		await button.trigger('click')
		expect(wrapper.findComponent({ name: 'TaskEditForm' }).exists()).toBe(true)
	})

	it('sends ONE aggregate request on create with no redundant re-fetch (CS-PLAN-063 §3.5, CS-PLAN-067 §3.4)', async () => {
		roomTasksStore.createTaskAggregate.mockResolvedValue({
			task: { id: 7, name: 'Dishes' },
			roomTask: { id: 42, roomId: 12 },
			assignees: [{ userId: 'mom' }],
		})
		const wrapper = await mountView()
		const form = await openCreateModal(wrapper)
		const houseFetchesBefore = roomTasksStore.fetchHouseRoomTasks.mock.calls.length
		const definitionFetchesBefore = tasksStore.fetchTasks.mock.calls.length

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
		// CS-PLAN-067 §3.4: the aggregate response already reconciled the
		// instance and definition stores — no redundant refetch follows.
		expect(roomTasksStore.fetchRoomTasks).not.toHaveBeenCalled()
		expect(roomTasksStore.fetchHouseRoomTasks.mock.calls.length).toBe(houseFetchesBefore)
		expect(tasksStore.fetchTasks.mock.calls.length).toBe(definitionFetchesBefore)
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

	it('presets the status filter from ?status= and ignores unknown values (CS-PLAN-084 WP-C)', async () => {
		mockRoute = { query: { status: 'overdue', scope: 'all' } }
		const overdue = await mountView()
		expect(overdue.vm.filters.status).toBe('overdue')
		expect(overdue.vm.filters.scope).toBe('all')

		mockRoute = { query: { status: 'completed-everything' } }
		const unknown = await mountView()
		expect(unknown.vm.filters.status).toBe('all')
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

		expect(wrapper.vm.filters.visibility).toBe('private')
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

	// CS-PLAN-066 §3.5: explicit loading/empty/error/retry states.
	it('shows the localized error + retry state when initialization fails', async () => {
		mockRoomsStore.fetchFloorsWithRooms.mockRejectedValue(new Error('offline'))

		const wrapper = mountView()
		await new Promise(resolve => setTimeout(resolve, 0))

		expect(wrapper.find('[data-testid="task-manager-error"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="add-task-button"]').element.disabled).toBe(true)

		mockRoomsStore.fetchFloorsWithRooms.mockResolvedValue([])
		await wrapper.find('[data-testid="task-manager-error"] button').trigger('click')
		await new Promise(resolve => setTimeout(resolve, 0))

		expect(wrapper.find('[data-testid="task-manager-error"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="add-task-button"]').element.disabled).toBe(false)
	})

	it('surfaces a visible error toast when a completion fails (never logger-only)', async () => {
		mockRoomTasksStore.completeRoomTask.mockRejectedValue(new Error('boom'))
		mockRoomTasksStore.roomTasks = [{ id: 9, taskId: 101, isActive: true }]
		mockTasksStore.getTaskById.mockReturnValue({ id: 101, name: 'Chore', visibilityType: 'public' })

		const wrapper = await mountView()
		wrapper.vm.handleCompleteTask(9)
		await new Promise(resolve => setTimeout(resolve, 0))

		expect(showError).toHaveBeenCalledWith('Could not complete the task')
	})

	it('appends the API code to the completion failure toast (CS-PLAN-084 WP-F)', async () => {
		mockRoomTasksStore.completeRoomTask.mockRejectedValue(Object.assign(new Error('Conflict'), { status: 409, code: 'CONFLICT' }))
		mockRoomTasksStore.roomTasks = [{ id: 9, taskId: 101, isActive: true }]
		mockTasksStore.getTaskById.mockReturnValue({ id: 101, name: 'Chore', visibilityType: 'public' })

		const wrapper = await mountView()
		wrapper.vm.handleCompleteTask(9)
		await new Promise(resolve => setTimeout(resolve, 0))

		expect(showError).toHaveBeenCalledWith('Could not complete the task (CONFLICT)')
	})

	it('completes a confirmed claim with its reviewed allocation, never reopening the participation dialog', async () => {
		mockRoomTasksStore.completeRoomTask.mockResolvedValue({})
		mockRoomTasksStore.roomTasks = [{ id: 9, taskId: 101, isActive: true,
			proposal: { participants: [{ userId: 'dad', sharePct: 100 }] } }]
		const claim = { participants: [{ userId: 'mom', sharePct: 100 }], allocationReviewed: true }

		const wrapper = await mountView()
		await wrapper.vm.handleCompleteTask(9, claim)

		expect(wrapper.vm.participation).toBeNull()
		expect(mockRoomTasksStore.completeRoomTask).toHaveBeenCalledWith(9, claim)
	})

	describe('paged list (CS-PLAN-084 WP-D)', () => {
		const rows = (count) => Array.from({ length: count }, (_, index) => ({
			id: index + 1,
			taskId: 100 + index,
			roomId: 12,
			assignees: [],
			isActive: true,
			// Distinct due days keep the shared list order deterministic.
			nextDue: new Date(Date.UTC(2030, 0, index + 1)).toISOString(),
		}))
		const shownIds = (wrapper) => wrapper.findComponent({ name: 'TaskList' }).props('roomTasks').map(item => item.roomTask.id)

		it('shows 20 compact rows per page and mirrors the page in the query', async () => {
			mockRoomTasksStore.roomTasks = rows(25)
			const wrapper = mountView()
			await flushPromises()

			expect(shownIds(wrapper)).toEqual(Array.from({ length: 20 }, (_, index) => index + 1))
			expect(wrapper.findComponent({ name: 'TaskList' }).props('compact')).toBe(true)
			expect(wrapper.get('[data-testid="task-pager-status"]').text()).toBe('Page 1 of 2')
			expect(wrapper.get('[data-testid="task-pager-previous"]').element.disabled).toBe(true)

			await wrapper.get('[data-testid="task-pager-next"]').trigger('click')
			expect(shownIds(wrapper)).toEqual([21, 22, 23, 24, 25])
			expect(mockRouter.replace).toHaveBeenLastCalledWith({ query: { page: '2' } })
			expect(wrapper.get('[data-testid="task-pager-next"]').element.disabled).toBe(true)
		})

		it('hides the pager when everything fits on one page', async () => {
			mockRoomTasksStore.roomTasks = rows(20)
			const wrapper = mountView()
			await flushPromises()
			expect(shownIds(wrapper)).toHaveLength(20)
			expect(wrapper.find('[data-testid="task-pager"]').exists()).toBe(false)
		})

		it('opens the page from ?page= and clamps a page beyond the end', async () => {
			mockRoomTasksStore.roomTasks = rows(25)
			mockRoute = { query: { page: '2', scope: 'all' } }
			const second = mountView()
			await flushPromises()
			expect(shownIds(second)).toEqual([21, 22, 23, 24, 25])

			mockRoute = { query: { page: '9' } }
			const beyond = mountView()
			await flushPromises()
			expect(beyond.get('[data-testid="task-pager-status"]').text()).toBe('Page 2 of 2')

			mockRoute = { query: { page: 'abc' } }
			const invalid = mountView()
			await flushPromises()
			expect(shownIds(invalid)[0]).toBe(1)
		})

		it('returns to page 1 and drops ?page= when a filter changes', async () => {
			mockRoomTasksStore.roomTasks = rows(25)
			mockRoute = { query: { page: '2', scope: 'all' } }
			const wrapper = mountView()
			await flushPromises()

			await wrapper.get('[data-testid="tasks-filter-unassigned"]').trigger('click')
			expect(shownIds(wrapper)[0]).toBe(1)
			expect(mockRouter.replace).toHaveBeenLastCalledWith({ query: { scope: 'all' } })
		})
	})

	describe('Completed filter (CS-PLAN-084 D-084-07)', () => {
		it('keeps the default view on the active read and loads finished tasks only on demand', async () => {
			mockRoomTasksStore.roomTasks = [{ id: 1, taskId: 101, roomId: 12, assignees: [], isActive: true }]
			mockRoomTasksStore.finishedRoomTasks = [{ id: 7, taskId: 107, roomId: 12, assignees: [], isActive: false }]
			const wrapper = mountView()
			await flushPromises()
			const shown = () => wrapper.findComponent({ name: 'TaskList' }).props('roomTasks').map(item => item.roomTask.id)

			expect(mockRoomTasksStore.fetchInactiveHouseRoomTasks).not.toHaveBeenCalled()
			expect(shown()).toEqual([1])

			await wrapper.get('[data-testid="status-filter"]').setValue('completed')
			await flushPromises()
			expect(mockRoomTasksStore.fetchInactiveHouseRoomTasks).toHaveBeenCalledTimes(1)
			expect(shown()).toEqual([7])

			// Another filter change inside "Completed" reuses the loaded rows.
			await wrapper.get('[data-testid="priority-filter"]').setValue('normal')
			expect(mockRoomTasksStore.fetchInactiveHouseRoomTasks).toHaveBeenCalledTimes(1)
		})

		it('toasts with the API reference when the finished-task read fails', async () => {
			mockRoomTasksStore.fetchInactiveHouseRoomTasks.mockRejectedValueOnce(Object.assign(new Error('down'), { status: 503 }))
			const wrapper = mountView()
			await flushPromises()

			await wrapper.get('[data-testid="status-filter"]').setValue('completed')
			await flushPromises()
			expect(showError).toHaveBeenCalledWith('Could not load finished tasks (HTTP 503)')
			expect(wrapper.findComponent({ name: 'TaskList' }).props('loading')).toBe(false)
		})
	})
})
