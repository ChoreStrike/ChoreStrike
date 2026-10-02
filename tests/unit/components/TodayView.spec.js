import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import TodayView from '@/views/TodayView.vue'

jest.mock('@nextcloud/l10n', () => ({
	// Interpolating mock so placeholder-driven copy (the progress line,
	// the overload amounts) can be asserted like the real UI renders it.
	translate: jest.fn((app, text, params) => {
		if (!params) return text
		return Object.entries(params)
			.reduce((acc, [key, value]) => acc.replaceAll(`{${key}}`, String(value)), text)
	}),
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
		// WP2/WP6: the overdue read carries the occurrence block of the
		// shown miss — the catch-up card renders its planned date/window.
		occurrence: {
			occurrenceId: '12:1',
			expectedVersion: 3,
			selectionToken: 'token-30',
			classification: 'late',
			kind: 'scheduled',
			plannedDate: '2026-08-18',
			workDate: '2026-08-18',
			windowStart: '2026-08-18T06:00:00+00:00',
			windowEnd: '2026-08-18T20:00:00+00:00',
			suspended: false,
			fulfilled: false,
			covered: false,
		},
	},
])

let mockRoomTasksStore
let mockHouseStore
let mockChecklistStore
let mockUserStore
let mockGameStore
let mockSchedulingStore

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

jest.mock('@/store/game.js', () => ({
	useGameStore: () => mockGameStore,
}))

jest.mock('@/store/scheduling.js', () => ({
	useSchedulingStore: () => mockSchedulingStore,
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
			rangeTasks: { scheduled: [], completed: [], from: null, to: null },
			refreshFailed: false,
			countsRefreshing: false,
			fetchFilteredDailyTasks: jest.fn().mockResolvedValue(todayFixture()),
			fetchOverdueTasks: jest.fn().mockResolvedValue(overdueFixture()),
			fetchDueTodayTasks: jest.fn().mockResolvedValue([]),
			fetchRangeTasks: jest.fn().mockResolvedValue({ scheduled: [], completed: [] }),
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
		mockGameStore = {
			gameState: {},
			rhythm: { confirmed: 4, current: 4, best: 9, pending: true },
			weeklyGoal: { dueCount: 10, completedCount: 6, percent: 60, targetPercent: 80, achieved: false, weekStart: null, weekEnd: null },
			fetchGameState: jest.fn().mockResolvedValue({}),
		}
		mockSchedulingStore = {
			energyBudget: { monday: 5, tuesday: 5, wednesday: 5, thursday: 5, friday: 5, saturday: 5, sunday: 5 },
			weekdayAvailability: { monday: true, tuesday: true, wednesday: true, thursday: true, friday: true, saturday: true, sunday: true },
			memberAvailability: [],
			getCapacityForDay() {
				return mockSchedulingStore.memberAvailability.length > 0 ? 10 : 5
			},
			fetchUserPreferences: jest.fn().mockResolvedValue({}),
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

	it('renders catch-up and today sections from the loaded payload', async () => {
		const wrapper = await mountView()
		await wrapper.find('[data-testid="today-filter-all"]').trigger('click')

		expect(wrapper.find('[data-testid="today-section-catchup"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="today-section-today"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Take out trash')
		expect(wrapper.text()).toContain('Living Room')
		expect(wrapper.text()).toContain('Do the dishes')
		// A due-today task must not be duplicated into the catch-up section
		const catchupSection = wrapper.find('[data-testid="today-section-catchup"]')
		expect(catchupSection.text()).not.toContain('Do the dishes')
	})

	it('renders ONE catch-up card per chore with the attributed planned date and cover semantics (C5f, C1c)', async () => {
		const wrapper = await mountView()
		await wrapper.find('[data-testid="today-filter-all"]').trigger('click')

		const catchup = wrapper.find('[data-testid="today-section-catchup"]')
		// The occurrence block's planned date stays visible on the card…
		expect(catchup.find('[data-testid="catchup-planned"]').exists()).toBe(true)
		expect(catchup.text()).toContain('Aug 18')
		// …and the C1d cover hint is honest: completing covers without
		// relabeling the miss as on time.
		expect(catchup.text()).toContain('covers the miss')
		// Exactly ONE card per chore (the overdue read resolves one miss).
		expect(catchup.findAll('[data-testid="today-task-item"]')).toHaveLength(1)
	})

	it('renders the calm progress line with pending state and the shared weekly goal (C1e/C1f)', async () => {
		const wrapper = await mountView()

		const progress = wrapper.find('[data-testid="today-progress-line"]')
		expect(progress.exists()).toBe(true)
		expect(progress.find('[data-testid="today-streak"]').text()).toContain('4 days confirmed')
		expect(progress.find('[data-testid="today-streak"]').text()).toContain('today pending')
		expect(progress.find('[data-testid="today-weekly-goal"]').text()).toContain('60%')
		expect(progress.find('[data-testid="today-weekly-goal"]').text()).toContain('80%')
		// No Coins on My Day (C5f/D10a negative pin).
		expect(progress.text()).not.toContain('Coins')
	})

	it('hides the progress line when no progress snapshot exists (neutral start)', async () => {
		mockGameStore.gameState = null
		const wrapper = await mountView()

		expect(wrapper.find('[data-testid="today-progress-line"]').exists()).toBe(false)
	})

	it('renders the neutral overload clarification when planned plus outstanding exceeds the day capacity (C5f)', async () => {
		// Capacity 5 (own weekday budget) vs. planned 3+2+1=6 plus
		// outstanding 2 under the all filter.
		const wrapper = await mountView()
		await wrapper.find('[data-testid="today-filter-all"]').trigger('click')

		const overload = wrapper.find('[data-testid="today-overload"]')
		expect(overload.exists()).toBe(true)
		// Amounts stay visible; the invitation targets the owner replan —
		// neutral, never threatening.
		expect(overload.text()).toContain('8')
		expect(overload.text()).toContain('5')
		expect(overload.text()).toContain('house owner can replan')
	})

	it('renders no overload banner when the day fits the capacity', async () => {
		mockRoomTasksStore.overdueTasks = []
		const wrapper = await mountView()

		expect(wrapper.find('[data-testid="today-overload"]').exists()).toBe(false)
	})

	it('renders next agreed appointments from the delivered range read without optional work (C5f)', async () => {
		const todayKey = new Date().toISOString().slice(0, 10)
		const tomorrow = new Date(Date.now() + 86400000).toISOString().slice(0, 10)
		mockRoomTasksStore.rangeTasks.scheduled = [
			{
				roomTask: { id: 11, taskId: 1, roomId: 1 },
				task: { id: 1, name: 'Do the dishes' },
				room: { id: 1, name: 'Kitchen' },
				occurrenceDetails: [
					{ date: tomorrow, occurrenceId: '12:2', classification: 'agreed', version: 4, fulfilled: false },
					{ date: tomorrow, occurrenceId: '12:9', classification: 'projection', version: null, fulfilled: null },
					{ date: '2026-01-01', occurrenceId: '12:0', classification: 'agreed', version: 1, fulfilled: true },
				],
			},
			{
				roomTask: { id: 21, taskId: 9, roomId: 3 },
				task: { id: 9, name: 'Water the plants' },
				room: { id: 3, name: 'Garden' },
				occurrenceDetails: [
					{ date: todayKey, occurrenceId: '19:1', classification: 'agreed', version: 2, fulfilled: false },
				],
			},
		]
		const wrapper = await mountView()

		const section = wrapper.find('[data-testid="today-section-appointments"]')
		expect(section.exists()).toBe(true)
		const rows = section.findAll('[data-testid="today-appointment"]')
		// Only agreed, unfulfilled, today-or-later occurrences; sorted by
		// date, capped at the small appointment list.
		expect(rows).toHaveLength(2)
		expect(rows[0].text()).toContain('Water the plants')
		expect(rows[1].text()).toContain('Do the dishes')
		// Read-only surface: no completion or edit affordances.
		expect(section.find('button').exists()).toBe(false)
	})

	it('renders the neutral free-day empty state without an engagement prompt (C5f)', async () => {
		mockRoomTasksStore.dailyTasks = []
		mockRoomTasksStore.overdueTasks = []

		const wrapper = await mountView()
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="today-empty-state"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="today-empty-state"]').text()).toContain('Nothing is planned for today.')
		// No arcade/catalog/ranking/Coins surface anywhere on My Day.
		expect(wrapper.text()).not.toContain('Coins')
		expect(wrapper.text()).not.toContain('Arcade')
		expect(wrapper.text()).not.toContain('Leaderboard')
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

		// Catch-up section emptied out…
		expect(wrapper.find('[data-testid="today-section-catchup"]').exists()).toBe(false)
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

	// CS-PLAN-070 WP8 §3.6: the contextual progress/windows help sits on
	// My Day (inline trigger text stays Coin-free, C5f).
	it('offers the contextual progress help next to the My Day header', async () => {
		const wrapper = await mountView()
		expect(wrapper.find('[data-testid="help-context-progress"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="help-context-progress"]').text()).not.toContain('Coins')
	})
})
