import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import TodayView from '@/views/TodayView.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
}))

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

const todayFixture = () => ([
	{
		roomTask: { id: 11, taskId: 1, roomId: 1, isActive: true, nextDue: '2026-08-21T08:00:00+00:00', assignedToUserId: 'mom' },
		task: { id: 1, name: 'Do the dishes', energyCost: 3 },
		room: { id: 1, name: 'Kitchen' },
	},
	{
		roomTask: { id: 12, taskId: 2, roomId: 1, isActive: true, nextDue: '2026-08-21T09:00:00+00:00', assignedToUserId: null },
		task: { id: 2, name: 'Wipe kitchen counters', energyCost: 2 },
		room: { id: 1, name: 'Kitchen' },
	},
	{
		roomTask: { id: 13, taskId: 3, roomId: 2, isActive: true, nextDue: '2026-08-21T10:00:00+00:00', assignedToUserId: 'teen' },
		task: { id: 3, name: 'Tidy up toys', energyCost: 1 },
		room: { id: 2, name: 'Living Room' },
	},
])

const overdueFixture = () => ([
	{ id: 30, taskId: 4, roomId: 2, isActive: true, nextDue: '2026-08-18T08:00:00+00:00', assignedToUserId: 'dad' },
])

let mockRoomTasksStore
let mockTasksStore
let mockRoomsStore
let mockUserStore

jest.mock('@/store/roomTasks.js', () => ({
	useRoomTasksStore: () => mockRoomTasksStore,
}))

jest.mock('@/store/tasks.js', () => ({
	useTasksStore: () => mockTasksStore,
}))

jest.mock('@/store/rooms.js', () => ({
	useRoomsStore: () => mockRoomsStore,
}))

jest.mock('@/store/user.js', () => ({
	useUserStore: () => mockUserStore,
}))

describe('TodayView', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
		mockRoomTasksStore = {
			fetchFilteredDailyTasks: jest.fn().mockResolvedValue(todayFixture()),
			fetchOverdueTasks: jest.fn().mockResolvedValue(overdueFixture()),
			fetchAssignees: jest.fn().mockImplementation(async (roomTaskId) => {
				if (roomTaskId === 13) return ['teen']
				if (roomTaskId === 30) return ['dad']
				return []
			}),
			completeRoomTask: jest.fn().mockResolvedValue({
				roomTask: { id: 11, taskId: 1, roomId: 1, isActive: false },
				rewards: { coins: 10 },
			}),
		}
		mockTasksStore = {
			tasks: [{ id: 4, name: 'Take out trash', energyCost: 2 }],
			fetchTasks: jest.fn().mockResolvedValue([]),
			getTaskById: jest.fn(id => mockTasksStore.tasks.find(task => task.id === id) || null),
		}
		mockRoomsStore = {
			rooms: [{ id: 2, name: 'Living Room' }],
			floors: [],
			fetchFloorsWithRooms: jest.fn().mockResolvedValue([]),
			getRoomById: jest.fn(id => mockRoomsStore.rooms.find(room => room.id === id) || null),
		}
		mockUserStore = {
			currentUser: { id: 1, nextcloudUserId: 'mom', role: 'owner' },
		}
	})

	const flushAsync = () => new Promise(resolve => setTimeout(resolve, 0))

	const mountView = async () => {
		const wrapper = mount(TodayView, {
			global: {
				plugins: [createPinia()],
				stubs: {
					RouterLink: { template: '<a><slot /></a>' },
				},
			},
		})
		// Let the mounted load() promise chain settle
		await flushAsync()
		return wrapper
	}

	it('renders overdue and today sections from the loaded payload', async () => {
		const wrapper = await mountView()
		await wrapper.find('[data-testid="today-filter-all"]').trigger('click')

		expect(wrapper.find('[data-testid="today-section-overdue"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="today-section-today"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Take out trash')
		expect(wrapper.text()).toContain('Living Room')
		expect(wrapper.text()).toContain('Do the dishes')
		// A due-today task must not be duplicated into the overdue section
		const overdueSection = wrapper.find('[data-testid="today-section-overdue"]')
		expect(overdueSection.text()).not.toContain('Do the dishes')
	})

	it('shows assigned-to-me and unassigned tasks under the mine filter (default)', async () => {
		const wrapper = await mountView()
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('Do the dishes')
		expect(wrapper.text()).toContain('Wipe kitchen counters')
		expect(wrapper.text()).not.toContain('Tidy up toys')
		expect(wrapper.text()).not.toContain('Take out trash')
	})

	it('shows everyone under the all filter', async () => {
		const wrapper = await mountView()
		await wrapper.vm.$nextTick()

		await wrapper.find('[data-testid="today-filter-all"]').trigger('click')

		expect(wrapper.text()).toContain('Tidy up toys')
		expect(wrapper.text()).toContain('Take out trash')
	})

	it('completing a task calls the store and marks the row completed', async () => {
		const wrapper = await mountView()
		await wrapper.vm.$nextTick()

		await wrapper.find('[data-testid="today-complete-button-11"]').trigger('click')
		await wrapper.vm.$nextTick()

		expect(mockRoomTasksStore.completeRoomTask).toHaveBeenCalledWith(11)
		expect(wrapper.find('[data-testid="today-completed-check-11"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="today-complete-button-11"]').exists()).toBe(false)
	})

	it('completing a recurring task removes it from the list once its next due moves away', async () => {
		const tomorrow = new Date()
		tomorrow.setDate(tomorrow.getDate() + 1)
		mockRoomTasksStore.completeRoomTask.mockResolvedValue({
			roomTask: { id: 11, taskId: 1, roomId: 1, isActive: true, nextDue: tomorrow.toISOString(), assignedToUserId: 'mom' },
			rewards: {},
		})
		const wrapper = await mountView()

		await wrapper.find('[data-testid="today-complete-button-11"]').trigger('click')
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).not.toContain('Do the dishes')
	})

	it('completing a recurring overdue task moves it to Today when its next occurrence is today', async () => {
		const today = new Date()
		mockRoomTasksStore.completeRoomTask.mockResolvedValue({
			roomTask: { id: 30, taskId: 4, roomId: 2, isActive: true, nextDue: today.toISOString(), assignedToUserId: 'dad' },
			rewards: {},
		})
		const wrapper = await mountView()
		await wrapper.find('[data-testid="today-filter-all"]').trigger('click')

		await wrapper.find('[data-testid="today-complete-button-30"]').trigger('click')
		await flushAsync()

		// Overdue section emptied out…
		expect(wrapper.find('[data-testid="today-section-overdue"]').exists()).toBe(false)
		// …and the row re-rendered as an active Today row (not dropped).
		expect(wrapper.text()).toContain('Take out trash')
		expect(wrapper.find('[data-testid="today-complete-button-30"]').exists()).toBe(true)
	})

	it('renders a friendly empty state when nothing is due', async () => {
		mockRoomTasksStore.fetchFilteredDailyTasks.mockResolvedValue([])
		mockRoomTasksStore.fetchOverdueTasks.mockResolvedValue([])

		const wrapper = await mountView()
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="today-empty-state"]').exists()).toBe(true)
	})

	it('shows an error state with retry when loading fails', async () => {
		mockRoomTasksStore.fetchFilteredDailyTasks.mockRejectedValue(new Error('boom'))

		const wrapper = await mountView()
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="today-error"]').exists()).toBe(true)
	})

	it('completing a failed task shows an error toast and keeps the row active', async () => {
		mockRoomTasksStore.completeRoomTask.mockRejectedValue(new Error('nope'))
		const { showError } = jest.requireMock('@nextcloud/dialogs')
		const wrapper = await mountView()
		await wrapper.vm.$nextTick()

		await wrapper.find('[data-testid="today-complete-button-11"]').trigger('click')
		await wrapper.vm.$nextTick()

		expect(showError).toHaveBeenCalled()
		expect(wrapper.find('[data-testid="today-complete-button-11"]').exists()).toBe(true)
	})
})
