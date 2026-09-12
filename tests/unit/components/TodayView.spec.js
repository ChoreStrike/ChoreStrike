import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
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

// Deterministic "today in the house timezone (UTC)" fixtures: the view
// filters daily rows by house-local day key, so the fixtures must pin
// rows to the CURRENT UTC day independent of the wall-clock date.
const utcTodayIso = (hour) => {
	const now = new Date()
	return new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate(), hour)).toISOString()
}

// CS-PLAN-066 (D1): today and overdue rows are the enriched canonical
// execution-row shape `{roomTask, task, room, assignees}` from the
// dedicated endpoints.
const todayFixture = () => ([
	{
		roomTask: { id: 11, taskId: 1, roomId: 1, isActive: true, nextDue: utcTodayIso(8) },
		task: { id: 1, name: 'Do the dishes', energyCost: 3, instructions: 'Rinse first, then load the dishwasher.' },
		room: { id: 1, name: 'Kitchen' },
		assignees: [],
	},
	{
		roomTask: { id: 12, taskId: 2, roomId: 1, isActive: true, nextDue: utcTodayIso(9) },
		task: { id: 2, name: 'Wipe kitchen counters', energyCost: 2 },
		room: { id: 1, name: 'Kitchen' },
		assignees: ['mom'],
	},
	{
		roomTask: { id: 13, taskId: 3, roomId: 2, isActive: true, nextDue: utcTodayIso(10) },
		task: { id: 3, name: 'Tidy up toys', energyCost: 1 },
		room: { id: 2, name: 'Living Room' },
		assignees: ['teen'],
	},
])

const overdueFixture = () => ([
	{
		roomTask: { id: 30, taskId: 4, roomId: 2, isActive: true, nextDue: '2026-08-18T08:00:00+00:00' },
		task: { id: 4, name: 'Take out trash', energyCost: 2 },
		room: { id: 2, name: 'Living Room' },
		assignees: ['dad'],
	},
])

let mockRoomTasksStore
let mockHouseStore
let mockChecklistStore
let mockUserStore

jest.mock('@/store/roomTasks.js', () => ({
	useRoomTasksStore: () => mockRoomTasksStore,
}))

jest.mock('@/store/house.js', () => ({
	useHouseStore: () => mockHouseStore,
}))

jest.mock('@/store/checklist.js', () => ({
	useChecklistStore: () => mockChecklistStore,
}))

jest.mock('@/store/user.js', () => ({
	useUserStore: () => mockUserStore,
}))

describe('TodayView', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
		// Reactive mock: the view's row selectors read the store slices, so
		// reconcile-driven mutations must be tracked by Vue's reactivity.
		mockRoomTasksStore = reactive({
			// Selector sources (CS-PLAN-066 §3.3): the view derives its rows
			// from the owning store slices.
			dailyTasks: todayFixture(),
			overdueTasks: overdueFixture(),
			refreshFailed: false,
			countsRefreshing: false,
			fetchFilteredDailyTasks: jest.fn().mockResolvedValue(todayFixture()),
			fetchOverdueTasks: jest.fn().mockResolvedValue(overdueFixture()),
			fetchDueTodayTasks: jest.fn().mockResolvedValue([]),
			refreshDerivedProjections: jest.fn().mockResolvedValue(true),
			completeRoomTask: jest.fn().mockImplementation(async (roomTaskId) => {
				// Mimic the store reconcile (D10): the owning slice is
				// replaced with the response-owned room task.
				const markCompleted = (slice, id, patch) => {
					const index = slice.findIndex(item => item.roomTask.id === id)
					if (index >= 0) {
						slice[index] = { ...slice[index], roomTask: { ...slice[index].roomTask, ...patch } }
					}
				}
				if (roomTaskId === 11) {
					markCompleted(mockRoomTasksStore.dailyTasks, 11, { isActive: false })
					return { roomTask: { id: 11, taskId: 1, roomId: 1, isActive: false }, rewards: { coins: 10 } }
				}
				if (roomTaskId === 30) {
					const row = mockRoomTasksStore.overdueTasks.find(item => item.roomTask.id === 30)
					mockRoomTasksStore.overdueTasks = mockRoomTasksStore.overdueTasks.filter(item => item.roomTask.id !== 30)
					const moved = { ...row, roomTask: { ...row.roomTask, nextDue: new Date().toISOString() } }
					mockRoomTasksStore.dailyTasks = [...mockRoomTasksStore.dailyTasks, moved]
					return { roomTask: moved.roomTask, rewards: {} }
				}
				return { roomTask: { id: roomTaskId, isActive: false }, rewards: {} }
			}),
		})
		mockHouseStore = { timezone: 'UTC' }
		mockChecklistStore = {
			items: {},
			progress: {},
			getTaskItems: jest.fn(taskId => mockChecklistStore.items[taskId] || []),
			getRoomTaskProgress: jest.fn(roomTaskId => mockChecklistStore.progress[roomTaskId] || []),
			fetchTaskItems: jest.fn().mockResolvedValue([]),
			fetchRoomTaskProgress: jest.fn().mockResolvedValue([]),
			toggleItem: jest.fn().mockResolvedValue({}),
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

	it('renders the short plain instructions of a row (CS-PLAN-066 §3.5)', async () => {
		const wrapper = await mountView()

		expect(wrapper.text()).toContain('Rinse first, then load the dishwasher.')
		// Plain escaped text — no v-html surface.
		expect(wrapper.html()).not.toContain('v-html')
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
		mockRoomTasksStore.completeRoomTask.mockImplementation(async (roomTaskId) => {
			const index = mockRoomTasksStore.dailyTasks.findIndex(item => item.roomTask.id === roomTaskId)
			mockRoomTasksStore.dailyTasks[index] = {
				...mockRoomTasksStore.dailyTasks[index],
				roomTask: { ...mockRoomTasksStore.dailyTasks[index].roomTask, nextDue: tomorrow.toISOString() },
			}
			return { roomTask: mockRoomTasksStore.dailyTasks[index].roomTask, rewards: {} }
		})
		const wrapper = await mountView()

		await wrapper.find('[data-testid="today-complete-button-11"]').trigger('click')
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).not.toContain('Do the dishes')
	})

	it('completing a recurring overdue task moves it to Today when its next occurrence is today', async () => {
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

	it('uses the house timezone when deciding whether the next occurrence is today', async () => {
		const wrapper = await mountView()
		jest.useFakeTimers()
		try {
			jest.setSystemTime(new Date('2026-08-22T06:30:00Z'))
			mockHouseStore.timezone = 'America/Los_Angeles'

			expect(wrapper.vm.isDueToday({ nextDue: '2026-08-21T20:00:00Z' })).toBe(true)
		} finally {
			jest.useRealTimers()
		}
	})

	it('renders a friendly empty state when nothing is due', async () => {
		mockRoomTasksStore.dailyTasks = []
		mockRoomTasksStore.overdueTasks = []

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

	it('surfaces a partial-refresh failure with a read-only retry (D3)', async () => {
		mockRoomTasksStore.refreshFailed = true
		const wrapper = await mountView()
		await wrapper.vm.$nextTick()

		const banner = wrapper.find('[data-testid="today-refresh-failed"]')
		expect(banner.exists()).toBe(true)

		await banner.find('button').trigger('click')
		expect(mockRoomTasksStore.refreshDerivedProjections).toHaveBeenCalled()
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

	it('expands the execute-only checklist and toggles an item (CS-PLAN-066 §3.5)', async () => {
		mockChecklistStore.items[1] = [{ id: 77, label: 'Rinse plates', sortOrder: 1 }]
		mockChecklistStore.progress[11] = []
		const wrapper = await mountView()
		await wrapper.vm.$nextTick()

		await wrapper.find('[data-testid="today-checklist-toggle-11"]').trigger('click')
		await flushAsync()

		expect(mockChecklistStore.fetchTaskItems).toHaveBeenCalledWith(1)
		expect(mockChecklistStore.fetchRoomTaskProgress).toHaveBeenCalledWith(11)
		expect(wrapper.find('[data-testid="task-checklist"]').exists()).toBe(true)
		// Execution-only: the add/edit affordances are absent.
		expect(wrapper.find('[data-testid="checklist-add-item-button"]').exists()).toBe(false)

		await wrapper.find('[data-testid="checklist-item-77"] input').setValue(true)
		expect(mockChecklistStore.toggleItem).toHaveBeenCalledWith(11, 77)
	})

	it('hides the checklist toggle for a checklist-less row after the one-shot discovery (manual a11y pass 2026-09-12)', async () => {
		const wrapper = await mountView()
		await wrapper.vm.$nextTick()

		// Row 12's definition (task 2) has no checklist items.
		const toggle = wrapper.find('[data-testid="today-checklist-toggle-12"]')
		expect(toggle.exists()).toBe(true)

		await toggle.trigger('click')
		await flushAsync()

		// Collapsed again (no empty sub-task box) AND the dead control is
		// gone for that definition — a checklist-less row must not keep
		// offering a clickable toggle that flashes and disappears.
		expect(wrapper.find('[data-testid="task-checklist"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="today-checklist-toggle-12"]').exists()).toBe(false)
		// Sibling rows keep their toggle until their own discovery.
		expect(wrapper.find('[data-testid="today-checklist-toggle-11"]').exists()).toBe(true)
	})
})
