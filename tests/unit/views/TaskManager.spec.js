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
	props: ['roomTasks', 'loading', 'compare', 'sectionLabel'],
	emits: ['complete'],
	template: '<div class="task-list-stub">tasks</div>',
}))

// The one form is covered by TaskForm.spec.js; here only how the view opens it.
jest.mock('@/components/Tasks/TaskForm.vue', () => ({
	name: 'TaskForm',
	props: ['item', 'prefill'],
	emits: ['close', 'saved'],
	template: '<div class="task-form-stub">form</div>',
}))

jest.mock('@/components/Tasks/TaskGroupList.vue', () => ({
	name: 'TaskGroupList',
	props: ['sections', 'openKey', 'canAdd'],
	emits: ['toggle', 'add', 'complete'],
	template: '<div class="task-group-list-stub" />',
}))

let mockRoute = { query: {} }
const mockRouter = { replace: jest.fn().mockResolvedValue() }

jest.mock('vue-router', () => ({
	useRoute: () => mockRoute,
	useRouter: () => mockRouter,
}))

import { showError, showWarning } from '@nextcloud/dialogs'

// The room quick filter reads the catalog through the store's getters.
const mockRoomsStore = {
	floors: [{ id: 1, name: 'Ground Floor' }],
	rooms: [{ id: 12, name: 'Kitchen', floorId: 1 }],
	get roomsByFloor() {
		return Object.fromEntries(this.floors.map((floor) => [floor.id, this.rooms.filter((room) => room.floorId === floor.id)]))
	},
	getRoomById: (id) => mockRoomsStore.rooms.find((room) => room.id === id),
	getFloorById: (id) => mockRoomsStore.floors.find((floor) => floor.id === id),
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
	refreshDerivedProjections: jest.fn().mockResolvedValue(true),
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

	// The quick filters (the operator's GUI review of WP-B6): a floor or a
	// room through the one room picker, people through their ticks.
	const openRoomFilter = async (wrapper) => {
		if (!wrapper.find('[data-testid="room-picker-all"]').exists()) {
			await wrapper.get('[data-testid="room-picker-toggle"]').trigger('click')
		}
	}
	const filterByFloor = async (wrapper, floorId) => {
		await openRoomFilter(wrapper)
		await wrapper.get(`[data-testid="room-picker-floor-${floorId}"]`).trigger('click')
	}
	const filterByRoom = async (wrapper, floorId, roomId) => {
		await openRoomFilter(wrapper)
		if (!wrapper.find(`[data-testid="room-picker-room-${roomId}"]`).exists()) {
			await wrapper.get(`[data-testid="room-picker-floor-${floorId}"]`).trigger('click')
		}
		await wrapper.get(`[data-testid="room-picker-room-${roomId}"]`).trigger('click')
		await flushPromises()
	}
	const filterByPeople = async (wrapper, userIds) => {
		if (!wrapper.find('[data-testid="people-filter-none"]').exists()) {
			await wrapper.get('[data-testid="people-filter-toggle"]').trigger('click')
		}
		await wrapper.get('[data-testid="people-filter-none"]').trigger('click')
		for (const userId of userIds) {
			await wrapper.get(`[data-testid="people-filter-${userId}"]`).setValue(true)
		}
	}

	const openCreateModal = async (wrapper) => {
		await flushPromises()
		await wrapper.find('[data-testid="add-task-button"]').trigger('click')
		const form = wrapper.findComponent({ name: 'TaskForm' })
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
		expect(wrapper.get('[data-testid="people-filter-toggle"]').element.disabled).toBe(true)
		const reads = mockRoomTasksStore.fetchHouseRoomTasks.mock.calls.length
		await wrapper.get('[data-testid="member-names-error"] button').trigger('click')
		await flushPromises()
		expect(wrapper.find('[data-testid="member-names-error"]').exists()).toBe(false)
		expect(wrapper.get('[data-testid="people-filter-toggle"]').element.disabled).toBe(false)
		expect(mockRoomTasksStore.fetchHouseRoomTasks).toHaveBeenCalledTimes(reads)
	})

	it('defaults to own and unassigned tasks with directly operable scope and people filters', async () => {
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
		// A choice of people is the scope: no button stands for it.
		await filterByPeople(wrapper, ['teen'])
		expect(shown()).toEqual([3])
		expect(wrapper.vm.filters).toMatchObject({ scope: 'custom', people: ['teen'], unassigned: false })
		for (const scope of ['mine', 'unassigned', 'all']) {
			expect(wrapper.get(`[data-testid="tasks-filter-${scope}"]`).attributes('aria-pressed')).toBe('false')
		}
		expect(wrapper.get('[data-testid="people-filter-toggle"]').text()).toContain('People (1)')
		await wrapper.get('[data-testid="people-filter-unassigned"]').setValue(true)
		expect(shown()).toEqual([2, 3])
		// A scope button takes over again and clears the choice.
		await wrapper.get('[data-testid="tasks-filter-mine"]').trigger('click')
		expect(shown()).toEqual([1, 2])
		expect(wrapper.vm.filters).toMatchObject({ scope: 'mine', people: [], unassigned: true })
		expect(wrapper.get('[data-testid="people-filter-teen"]').element.checked).toBe(false)
		expect(wrapper.get('[data-testid="people-filter-mom"]').element.checked).toBe(true)
	})

	it('keeps the scope buttons and the people ticks in step, both ways', async () => {
		mockRoomTasksStore.roomTasks = [
			{ id: 1, taskId: 101, roomId: 12, assignees: ['mom'] },
			{ id: 2, taskId: 102, roomId: 12, assignees: [] },
			{ id: 3, taskId: 103, roomId: 12, assignees: ['teen'] },
		]
		const wrapper = mountView()
		await flushPromises()
		const shown = () => wrapper.findComponent({ name: 'TaskList' }).props('roomTasks').map((item) => item.roomTask.id)
		const pressed = (scope) => wrapper.get(`[data-testid="tasks-filter-${scope}"]`).attributes('aria-pressed')
		await wrapper.get('[data-testid="people-filter-toggle"]').trigger('click')

		// Ticking the last person is Everyone, …
		await wrapper.get('[data-testid="people-filter-teen"]').setValue(true)
		expect(pressed('all')).toBe('true')
		expect(shown()).toEqual([1, 2, 3])
		// … leaving only unassigned work is Unassigned, …
		await wrapper.get('[data-testid="people-filter-teen"]').setValue(false)
		await wrapper.get('[data-testid="people-filter-mom"]').setValue(false)
		expect(pressed('unassigned')).toBe('true')
		expect(shown()).toEqual([2])
		// … and All is Everyone again.
		await wrapper.get('[data-testid="people-filter-all"]').trigger('click')
		expect(pressed('all')).toBe('true')
		expect(shown()).toEqual([1, 2, 3])
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
		await filterByRoom(wrapper, 1, 12)
		expect(shown()).toEqual([1])
		expect(wrapper.vm.filters).toMatchObject({ floor: '1', room: '12' })
		expect(wrapper.get('.room-quick-filter .picker-label').text()).toBe('Ground Floor · Kitchen')
		await filterByFloor(wrapper, 2)
		expect(shown()).toEqual([2])
		expect(wrapper.vm.filters).toMatchObject({ floor: '2', room: '' })
		expect(wrapper.get('.room-quick-filter .picker-label').text()).toBe('Upstairs')
		expect(wrapper.find('[data-testid="room-picker-room-12"]').exists()).toBe(false)
		// All rooms clears both.
		await wrapper.get('[data-testid="room-picker-all"]').trigger('click')
		expect(shown()).toEqual([1, 2])
		expect(wrapper.vm.filters).toMatchObject({ floor: '', room: '' })
		mockRoomsStore.floors = [{ id: 1, name: 'Ground Floor' }]
		mockRoomsStore.rooms = [{ id: 12, name: 'Kitchen', floorId: 1 }]
	})

	it('keeps person, floor and room out of the detail panel', async () => {
		const wrapper = mountView()
		await flushPromises()
		for (const testid of ['person-filter', 'floor-filter', 'room-filter']) {
			expect(wrapper.find(`[data-testid="${testid}"]`).exists()).toBe(false)
		}
	})

	// FEAT-UX-038 (D-088-13): the New Task dialog takes over the active
	// floor/room filter and a custom choice of people; the scope presets
	// preselect nobody. A room is chosen with its floor in the one picker.
	it.each([
		['a room filter', { room: 12 }, { roomId: 12, floorId: 1, assigneeIds: [] }],
		['a floor-only filter', { floor: 1 }, { roomId: null, floorId: 1, assigneeIds: [] }],
		['a choice of people', { people: ['teen'] }, { roomId: null, floorId: null, assigneeIds: ['teen'] }],
		['a choice of several people', { people: ['mom', 'teen'] }, { roomId: null, floorId: null, assigneeIds: ['mom', 'teen'] }],
		['the unassigned scope', { scope: 'unassigned' }, { roomId: null, floorId: null, assigneeIds: [] }],
		['everyone', { scope: 'all' }, { roomId: null, floorId: null, assigneeIds: [] }],
		['no filter', {}, { roomId: null, floorId: null, assigneeIds: [] }],
	])('prefills the create form from %s', async (_label, { scope, floor, room, people }, expected) => {
		mockRoomsStore.floors = [{ id: 1, name: 'Ground Floor' }]
		mockRoomsStore.rooms = [{ id: 12, name: 'Kitchen', floorId: 1 }]
		mockUserStore.houseUsers = [{ nextcloudUserId: 'mom', displayName: 'Parent' }, { nextcloudUserId: 'teen', displayName: 'Teen' }, { nextcloudUserId: 'kid', displayName: 'Kid' }]
		const wrapper = mountView()
		await flushPromises()
		if (scope) {
			await wrapper.get(`[data-testid="tasks-filter-${scope}"]`).trigger('click')
		}
		if (floor) {
			await filterByFloor(wrapper, floor)
		}
		if (room) {
			await filterByRoom(wrapper, 1, room)
		}
		if (people) {
			await filterByPeople(wrapper, people)
		}
		const form = await openCreateModal(wrapper)
		expect(form.props('prefill')).toEqual(expected)
		// A new task: the form edits nothing that exists.
		expect(form.props('item')).toBeFalsy()
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
		expect(wrapper.findComponent({ name: 'TaskForm' }).exists()).toBe(false)
		release([])
		await flushPromises()
		expect(button.element.disabled).toBe(false)
		await button.trigger('click')
		expect(wrapper.findComponent({ name: 'TaskForm' }).exists()).toBe(true)
	})

	// CS-PLAN-091 WP-B6: the one form creates; its store reconciles the
	// caches, so the view neither posts nor reads again.
	it('leaves creating to the one form and closes it without a refetch', async () => {
		const wrapper = mountView()
		const form = await openCreateModal(wrapper)
		const houseFetchesBefore = roomTasksStore.fetchHouseRoomTasks.mock.calls.length
		const definitionFetchesBefore = tasksStore.fetchTasks.mock.calls.length

		await form.vm.$emit('saved', { roomTask: { id: 42 } })
		await form.vm.$emit('close')

		expect(wrapper.vm.addTask).toBeNull()
		expect(wrapper.findComponent({ name: 'TaskForm' }).exists()).toBe(false)
		expect(roomTasksStore.fetchRoomTasks).not.toHaveBeenCalled()
		expect(roomTasksStore.fetchHouseRoomTasks.mock.calls.length).toBe(houseFetchesBefore)
		expect(tasksStore.fetchTasks.mock.calls.length).toBe(definitionFetchesBefore)
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

	// D-091-86: a row edits and deletes in the one form; the view has no own dialogs.
	it('keeps no edit or delete dialog of its own', async () => {
		const wrapper = mountView()
		await flushPromises()

		expect(wrapper.vm.handleEditTask).toBeUndefined()
		expect(wrapper.vm.handleDeleteTask).toBeUndefined()
		expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'TaskForm' }).exists()).toBe(false)
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
		mockRoomTasksStore.completeRoomTask.mockRejectedValue(Object.assign(new Error('Boom'), { status: 500, code: 'COMPLETE_TASK_ERROR' }))
		mockRoomTasksStore.roomTasks = [{ id: 9, taskId: 101, isActive: true }]
		mockTasksStore.getTaskById.mockReturnValue({ id: 101, name: 'Chore', visibilityType: 'public' })

		const wrapper = await mountView()
		wrapper.vm.handleCompleteTask(9)
		await new Promise(resolve => setTimeout(resolve, 0))

		expect(showError).toHaveBeenCalledWith('Could not complete the task (COMPLETE_TASK_ERROR)')
	})

	it('completes with the split a row hands over: its picker\'s or a confirmed claim\'s', async () => {
		mockRoomTasksStore.completeRoomTask.mockResolvedValue({})
		mockRoomTasksStore.roomTasks = [{ id: 9, taskId: 101, isActive: true, assignees: ['dad'] }]
		const split = { participants: [{ userId: 'mom', sharePct: 100 }], allocationReviewed: true }

		const wrapper = await mountView()
		await wrapper.vm.handleCompleteTask(9, split)

		expect(mockRoomTasksStore.completeRoomTask).toHaveBeenCalledWith(9, split)
	})

	it('completes a held row of someone else at once: no dialog asks who took part (WP-B2)', async () => {
		mockRoomTasksStore.completeRoomTask.mockResolvedValue({})
		mockRoomTasksStore.roomTasks = [{ id: 9, taskId: 101, isActive: true, assignees: ['dad'] }]

		const wrapper = await mountView()
		await wrapper.vm.handleCompleteTask(9)

		expect(mockRoomTasksStore.completeRoomTask).toHaveBeenCalledWith(9)
		expect(wrapper.findComponent({ name: 'ParticipationDialog' }).exists()).toBe(false)
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

		it('shows 20 rows per page and mirrors the page in the query', async () => {
			mockRoomTasksStore.roomTasks = rows(25)
			const wrapper = mountView()
			await flushPromises()

			expect(shownIds(wrapper)).toEqual(Array.from({ length: 20 }, (_, index) => index + 1))
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

	describe('grouping (CS-PLAN-090 WP-C)', () => {
		const daysFromToday = (days) => new Date(Date.now() + days * 86400000).toISOString()
		const groupedRows = () => [
			{ id: 1, taskId: 101, roomId: 12, assignees: ['teen'], isActive: true, nextDue: daysFromToday(2) },
			{ id: 2, taskId: 102, roomId: 12, assignees: [], isActive: true, nextDue: daysFromToday(-2), cycle: { state: 'overdue', centreOn: daysFromToday(-2).slice(0, 10), windowEndOn: daysFromToday(-2).slice(0, 10) } },
			{ id: 3, taskId: 103, roomId: 13, assignees: ['mom'], isActive: true, nextDue: daysFromToday(0) },
		]
		const groupList = (wrapper) => wrapper.findComponent({ name: 'TaskGroupList' })
		const groupKeys = (wrapper) => groupList(wrapper).props('sections').flatMap((section) => section.groups.map((group) => group.key))

		beforeEach(() => {
			mockRoomTasksStore.roomTasks = groupedRows()
			mockRoomsStore.rooms = [{ id: 12, name: 'Kitchen', floorId: 1, cleanliness: 90 }, { id: 13, name: 'Hall', floorId: 1, cleanliness: 100 }]
		})

		afterEach(() => {
			mockRoomTasksStore.roomTasks = []
			mockRoomsStore.rooms = [{ id: 12, name: 'Kitchen', floorId: 1 }]
		})

		it('lists by due date by default, missed work first, under one heading per due class', async () => {
			mockRoute = { query: { scope: 'all' } }
			const wrapper = mountView()
			await flushPromises()

			const list = wrapper.findComponent({ name: 'TaskList' })
			expect(wrapper.get('[data-testid="tasks-group-due"]').attributes('aria-pressed')).toBe('true')
			expect(groupList(wrapper).exists()).toBe(false)
			expect(list.props('roomTasks').map((item) => item.roomTask.id)).toEqual([2, 3, 1])
			expect(list.props('roomTasks').map((item) => list.props('sectionLabel')(item)))
				.toEqual(['Overdue · 1', 'Due today · 1', 'Later · 1'])
			expect([...list.props('roomTasks')].reverse().sort(list.props('compare')).map((item) => item.roomTask.id)).toEqual([2, 3, 1])
		})

		it('switches to rooms, shows everyone\'s tasks and mirrors the grouping in the URL', async () => {
			const wrapper = mountView()
			await flushPromises()

			await wrapper.get('[data-testid="tasks-group-room"]').trigger('click')

			expect(mockRouter.replace).toHaveBeenLastCalledWith({ query: { group: 'room' } })
			expect(wrapper.get('[data-testid="tasks-filter-all"]').attributes('aria-pressed')).toBe('true')
			const [floor] = groupList(wrapper).props('sections')
			expect(floor).toMatchObject({ key: 1, label: 'Ground Floor', open: 3 })
			expect(floor.groups.map((group) => [group.label, group.open, group.overdue])).toEqual([['Kitchen', 2, 1], ['Hall', 1, 0]])
			expect(groupList(wrapper).props('openKey')).toBeNull()
			expect(groupList(wrapper).props('canAdd')).toBe(true)
		})

		it('drops the grouping from the URL on the way back and keeps the pager for the list by due date only', async () => {
			mockRoomTasksStore.roomTasks = Array.from({ length: 25 }, (_, index) => ({
				id: index + 1, taskId: 100 + index, roomId: 12, assignees: [], isActive: true, nextDue: daysFromToday(index + 1),
			}))
			mockRoute = { query: { group: 'room', room: '12' } }
			const wrapper = mountView()
			await flushPromises()

			expect(wrapper.find('[data-testid="task-pager"]').exists()).toBe(false)
			expect(groupList(wrapper).props('sections')[0].groups[0].items).toHaveLength(25)

			await wrapper.get('[data-testid="tasks-group-due"]').trigger('click')
			expect(mockRouter.replace).toHaveBeenLastCalledWith({ query: {} })
			expect(wrapper.find('[data-testid="task-pager"]').exists()).toBe(true)
			expect(groupList(wrapper).exists()).toBe(false)
		})

		it('opens the room a deep link names, with everyone\'s tasks (the old room address)', async () => {
			mockRoute = { query: { group: 'room', room: '13' } }
			const wrapper = mountView()
			await flushPromises()

			expect(wrapper.get('[data-testid="tasks-group-room"]').attributes('aria-pressed')).toBe('true')
			expect(groupList(wrapper).props('openKey')).toBe(13)
			expect(groupKeys(wrapper)).toEqual([12, 13])
		})

		it('mirrors the open room in the URL and closes it on a second tap', async () => {
			mockRoute = { query: { group: 'room' } }
			const wrapper = mountView()
			await flushPromises()

			await groupList(wrapper).vm.$emit('toggle', 12)
			expect(groupList(wrapper).props('openKey')).toBe(12)
			expect(mockRouter.replace).toHaveBeenLastCalledWith({ query: { group: 'room', room: '12' } })

			// The mocked route does not follow the router.
			mockRoute.query = { group: 'room', room: '12' }
			await groupList(wrapper).vm.$emit('toggle', 12)
			expect(groupList(wrapper).props('openKey')).toBeNull()
			expect(mockRouter.replace).toHaveBeenLastCalledWith({ query: { group: 'room' } })
		})

		it('narrows the rooms by the floor and room filters', async () => {
			mockRoomsStore.rooms = [...mockRoomsStore.rooms, { id: 30, name: 'Attic', floorId: 2, cleanliness: 100 }]
			mockRoomsStore.floors = [{ id: 1, name: 'Ground Floor' }, { id: 2, name: 'Top' }]
			mockRoute = { query: { group: 'room' } }
			const wrapper = mountView()
			await flushPromises()
			expect(groupKeys(wrapper)).toEqual([12, 13, 30])

			await filterByFloor(wrapper, 1)
			expect(groupKeys(wrapper)).toEqual([12, 13])
			await filterByRoom(wrapper, 1, 13)
			expect(groupKeys(wrapper)).toEqual([13])
			mockRoomsStore.floors = [{ id: 1, name: 'Ground Floor' }]
		})

		it('groups by person with the unassigned rows last, and keeps the open person out of the URL', async () => {
			mockRoute = { query: { group: 'person' } }
			const wrapper = mountView()
			await flushPromises()

			const [people] = groupList(wrapper).props('sections')
			expect(people.label).toBe('')
			expect(people.groups.map((group) => [group.key, group.label])).toEqual([['user:mom', 'Parent'], ['user:teen', 'Teen'], ['unassigned', 'Unassigned']])

			mockRouter.replace.mockClear()
			await groupList(wrapper).vm.$emit('toggle', 'user:teen')
			expect(groupList(wrapper).props('openKey')).toBe('user:teen')
			expect(mockRouter.replace).not.toHaveBeenCalled()
		})

		it('opens the one form with the room of a heading\'s *New*, and with the filters from the page header', async () => {
			mockRoute = { query: { group: 'room' } }
			const wrapper = mountView()
			await flushPromises()
			const room = mockRoomsStore.rooms[1]

			await groupList(wrapper).vm.$emit('add', room)
			expect(wrapper.findComponent({ name: 'TaskForm' }).props('prefill')).toEqual({ roomId: room.id, floorId: room.floorId, assigneeIds: [] })

			await wrapper.findComponent({ name: 'TaskForm' }).vm.$emit('close')
			expect(wrapper.findComponent({ name: 'TaskForm' }).exists()).toBe(false)

			await wrapper.get('[data-testid="add-task-button"]').trigger('click')
			expect(wrapper.findComponent({ name: 'TaskForm' }).props('prefill')).toEqual({ roomId: null, floorId: null, assigneeIds: [] })
		})

		it('completes a row of a group through the shared flow', async () => {
			mockRoute = { query: { group: 'room', room: '12' } }
			const wrapper = mountView()
			await flushPromises()
			const claim = { participants: [{ userId: 'mom', sharePct: 100 }], allocationReviewed: true }

			await groupList(wrapper).vm.$emit('complete', 2, claim)
			await flushPromises()

			expect(mockRoomTasksStore.completeRoomTask).toHaveBeenCalledWith(2, claim)
		})
	})
})
