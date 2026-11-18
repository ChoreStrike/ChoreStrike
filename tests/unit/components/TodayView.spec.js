import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import TodayView from '@/views/TodayView.vue'
import { confirmHold } from '../helpers/hold.js'

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

const dayKey = (offset) => new Date(Date.now() + offset * 86400000).toISOString().slice(0, 10)
/** The cycle block of a row (ADR-021): planned `planned` days from today, the window ends `end` days from today. */
const cycle = (planned, end = planned, over = {}) => ({
	cycleKey: 'c1',
	expectedVersion: 0,
	state: end < 0 ? 'overdue' : (planned > 0 && end === planned ? 'upcoming' : 'open'),
	centreOn: dayKey(planned),
	windowStartOn: dayKey(Math.min(planned, 0)),
	windowEndOn: dayKey(end),
	...over,
})

// CS-PLAN-066 (D1): today and overdue rows are the enriched canonical
// execution-row shape `{roomTask, task, room, assignees, cycle}` from the
// dedicated endpoints.
const todayFixture = () => ([
	{
		roomTask: { id: 11, taskId: 1, roomId: 1, isActive: true, nextDue: utcTodayIso(8) },
		task: { id: 1, name: 'Do the dishes', energyCost: 3, instructions: 'Rinse first, then load the dishwasher.' },
		room: { id: 1, name: 'Kitchen' },
		assignees: [],
		checklistItemCount: 1,
		cycle: cycle(0),
	},
	{
		roomTask: { id: 12, taskId: 2, roomId: 1, isActive: true, nextDue: utcTodayIso(9) },
		task: { id: 2, name: 'Wipe kitchen counters', energyCost: 2 },
		room: { id: 1, name: 'Kitchen' },
		assignees: ['mom'],
		cycle: cycle(0),
	},
	{
		roomTask: { id: 13, taskId: 3, roomId: 2, isActive: true, nextDue: utcTodayIso(10) },
		task: { id: 3, name: 'Tidy up toys', energyCost: 1 },
		room: { id: 2, name: 'Living Room' },
		assignees: ['teen'],
		cycle: cycle(0),
	},
])

const overdueFixture = () => ([
	{
		roomTask: { id: 30, taskId: 4, roomId: 2, isActive: true, nextDue: '2026-08-18T08:00:00+00:00' },
		task: { id: 4, name: 'Take out trash', energyCost: 2 },
		room: { id: 2, name: 'Living Room' },
		assignees: ['dad'],
		// The overdue read carries the cycle whose window ended — the row
		// renders its planned day.
		cycle: {
			cycleKey: 'd2026-08-18',
			expectedVersion: 3,
			state: 'overdue',
			centreOn: '2026-08-18',
			windowStartOn: '2026-08-18',
			windowEndOn: '2026-08-18',
		},
	},
])

let mockRoomTasksStore
let mockHouseStore
let mockChecklistStore
let mockUserStore
let mockGameStore
let mockRoomsStore
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

jest.mock('@/store/rooms.js', () => ({
	useRoomsStore: () => mockRoomsStore,
}))
jest.mock('@/store/scheduling.js', () => ({
	useSchedulingStore: () => mockSchedulingStore,
}))

// The wizard itself is covered by SetupWizard.spec.js; here only its entry.
jest.mock('@/components/Setup/SetupWizard.vue', () => ({
	__esModule: true,
	default: { name: 'SetupWizard', emits: ['applied', 'skipped', 'close'], template: '<div class="setup-wizard-stub" />' },
	isWizardDismissedForHouse: () => false,
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
			completingIds: {},
			justCompletedIds: {},
			isCompleting: jest.fn(id => mockRoomTasksStore.completingIds[id] === true),
			wasJustCompleted: jest.fn(id => mockRoomTasksStore.justCompletedIds[id] === true),
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
					// Late work: the next cycle counts from today and is not due today.
					const row = mockRoomTasksStore.overdueTasks.find(item => item.roomTask.id === 30)
					mockRoomTasksStore.overdueTasks = mockRoomTasksStore.overdueTasks.filter(item => item.roomTask.id !== 30)
					return { roomTask: row.roomTask, rewards: {} }
				}
				return { roomTask: { id: roomTaskId, isActive: false }, rewards: {} }
			}),
		})
		mockHouseStore = { timezone: 'UTC', house: { id: 1 }, fetchHouse: jest.fn().mockResolvedValue({ id: 1 }) }
		mockRoomsStore = reactive({
			// A set-up house whose floors no other view has loaded yet.
			floors: [],
			fetchFloorsWithRooms: jest.fn().mockImplementation(async () => {
				mockRoomsStore.floors = [{ id: 3, name: 'Upstairs' }]
				return []
			}),
			getFloorById: jest.fn(floorId => (floorId === 3 ? { id: 3, name: 'Upstairs' } : undefined)),
		})
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
			isHouseOwner: true,
			houseUsers: [{ nextcloudUserId: 'mom', displayName: 'Parent' }],
			fetchHouseUsers: jest.fn().mockResolvedValue([]),
			initializeCurrentUser: jest.fn().mockResolvedValue(undefined),
		}
		mockGameStore = {
			gameState: {},
			wallet: { availableMinor: 12850 },
			rhythm: { confirmed: 4, current: 4, best: 9, pending: true },
			weeklyGoal: { dueCount: 10, completedCount: 6, percent: 60, targetPercent: 80, achieved: false, weekStart: null, weekEnd: null },
			fetchGameState: jest.fn().mockResolvedValue({}),
		}
		mockSchedulingStore = {
			energyBudget: { monday: 5, tuesday: 5, wednesday: 5, thursday: 5, friday: 5, saturday: 5, sunday: 5 },
			weekdayAvailability: { monday: true, tuesday: true, wednesday: true, thursday: true, friday: true, saturday: true, sunday: true },
			memberAvailability: [],
			getOwnCapacityForDay: () => 5,
			fetchUserPreferences: jest.fn().mockResolvedValue({}),
		}
	})

	const flushAsync = () => new Promise(resolve => setTimeout(resolve, 0))

	const mountView = async () => {
		const wrapper = mount(TodayView, {
			global: {
				plugins: [createPinia()],
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

	it('reads the day and the overdue work, not the due-today count no part of Today shows', async () => {
		await mountView()

		expect(mockRoomTasksStore.fetchFilteredDailyTasks).toHaveBeenCalledTimes(1)
		expect(mockRoomTasksStore.fetchOverdueTasks).toHaveBeenCalledTimes(1)
		expect(mockRoomTasksStore.fetchDueTodayTasks).not.toHaveBeenCalled()
	})

	it('keeps completion available when names fail and retries the optional member read', async () => {
		mockUserStore.houseUsers = []
		mockUserStore.fetchHouseUsers.mockRejectedValueOnce(new Error('offline'))
		const wrapper = await mountView()
		expect(wrapper.find('[data-testid="today-error"]').exists()).toBe(false)
		expect(wrapper.get('[data-testid="member-names-error"]').text()).toContain('Failed to load members')
		await confirmHold(wrapper, '[data-testid="today-complete-button-11"]')
		expect(mockRoomTasksStore.completeRoomTask).toHaveBeenCalledWith(11)
		await wrapper.get('[data-testid="member-names-error"] button').trigger('click')
		await flushAsync()
		expect(wrapper.find('[data-testid="member-names-error"]').exists()).toBe(false)
		expect(mockRoomTasksStore.fetchFilteredDailyTasks).toHaveBeenCalledTimes(1)
	})

	it('renders ONE overdue row per chore with its planned day and says what completing it means (C5f, C1c)', async () => {
		const wrapper = await mountView()
		await wrapper.find('[data-testid="today-filter-all"]').trigger('click')

		const catchup = wrapper.find('[data-testid="today-section-catchup"]')
		// The cycle's planned day stays visible on the row…
		expect(catchup.find('[data-testid="catchup-planned"]').exists()).toBe(true)
		expect(catchup.text()).toContain('Aug 18')
		// …and the hint is honest: done now, the work is late, never on time.
		expect(catchup.text()).toContain('counts as late work')
		// Exactly ONE row per chore (ADR-021 decision 1: one red entry).
		expect(catchup.findAll('[data-testid="today-task-item"]')).toHaveLength(1)
	})

	it('orders the page: status line, overdue block, due today, what is also possible (WP-B)', async () => {
		mockRoomTasksStore.dailyTasks = [...todayFixture(), openWindowRow()]
		const wrapper = await mountView()
		await wrapper.find('[data-testid="today-filter-all"]').trigger('click')

		const order = ['today-status-line', 'today-section-catchup', 'today-section-today', 'today-section-open-window']
			.map((testId) => wrapper.html().indexOf(`data-testid="${testId}"`))
		expect(order.every((position) => position >= 0)).toBe(true)
		expect([...order].sort((a, b) => a - b)).toEqual(order)
		expect(wrapper.get('[data-testid="today-title"]').text()).toBe('Today')
		expect(wrapper.get('[data-testid="today-date"]').text())
			.toBe(new Date().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }))
		expect(wrapper.get('[data-testid="today-section-catchup"] h2').text()).toBe('Overdue · 1')
		expect(wrapper.get('.section-count').text()).toBe('3 tasks')
	})

	it('counts a single due task in the singular', async () => {
		mockRoomTasksStore.dailyTasks = [todayFixture()[0]]
		const wrapper = await mountView()

		expect(wrapper.get('.section-count').text()).toBe('1 task')
	})

	it('shows the own status: open energy against the day, weekly goal, streak and coins (WP-B)', async () => {
		const wrapper = await mountView()

		const status = wrapper.get('[data-testid="today-status-line"]')
		// Own and unassigned work: 3 + 2 of the day's 5.
		expect(status.get('[data-testid="today-energy"]').text()).toContain('5 of 5')
		expect(status.get('[data-testid="today-weekly-goal"]').text()).toContain('60%')
		expect(status.get('[data-testid="today-streak"]').text()).toContain('4 days')
		expect(status.get('[data-testid="today-streak"]').text()).toContain('today pending')
		expect(status.get('[data-testid="own-wallet-available"]').text()).toBe('128.5')
	})

	it('keeps the status about the viewer when the list shows everyone', async () => {
		const wrapper = await mountView()
		await wrapper.find('[data-testid="today-filter-all"]').trigger('click')

		expect(wrapper.get('[data-testid="today-energy"]').text()).toContain('5 of 5')
		expect(wrapper.find('[data-testid="today-overload"]').exists()).toBe(false)
	})

	it('shows dashes, never zeros, while the status reads have not delivered (neutral start)', async () => {
		mockGameStore.gameState = null
		mockGameStore.wallet = null
		mockSchedulingStore.fetchUserPreferences.mockRejectedValue(new Error('offline'))
		const wrapper = await mountView()

		const values = wrapper.findAll('[data-testid="today-status-line"] .status-value').map((value) => value.text())
		expect(values).toEqual(['–', '–', '–', '–'])
		expect(wrapper.find('[data-testid="today-overload"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="today-error"]').exists()).toBe(false)
	})

	it('renders the neutral overload clarification when the own open work exceeds the day capacity (C5f)', async () => {
		// Own and unassigned work 3 + 2, plus an own miss of 2, against 5.
		mockRoomTasksStore.overdueTasks[0].assignees = ['mom']
		const wrapper = await mountView()

		const overload = wrapper.find('[data-testid="today-overload"]')
		expect(overload.exists()).toBe(true)
		// Amounts stay visible; the invitation targets the owner replan —
		// neutral, never threatening.
		expect(overload.text()).toContain('7 of 5')
		expect(overload.text()).toContain('house owner can replan')
		expect(wrapper.get('[data-testid="today-energy"]').classes()).toContain('over')
	})

	it('renders no overload banner when the day fits the capacity', async () => {
		mockRoomTasksStore.overdueTasks = []
		const wrapper = await mountView()

		expect(wrapper.find('[data-testid="today-overload"]').exists()).toBe(false)
	})

	it('renders the neutral free-day empty state without an engagement prompt (C5f)', async () => {
		mockRoomTasksStore.dailyTasks = []
		mockRoomTasksStore.overdueTasks = []

		const wrapper = await mountView()
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="today-empty-state"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="today-empty-state"]').text()).toContain('Nothing is planned for today.')
		// The free day says it once, not again under an empty heading.
		expect(wrapper.find('[data-testid="today-section-today"]').exists()).toBe(false)
		// No arcade, catalog or ranking surface anywhere on Today.
		expect(wrapper.text()).not.toContain('Arcade')
		expect(wrapper.text()).not.toContain('Leaderboard')
	})

	it('shows no upcoming appointments and reads no range for them (D-090-11)', async () => {
		const wrapper = await mountView()

		expect(wrapper.find('[data-testid="today-section-appointments"]').exists()).toBe(false)
		expect(mockRoomTasksStore.fetchRangeTasks).not.toHaveBeenCalled()
	})

	// BUG-UX-005 (CS-PLAN-088 feedback #5): work whose flex window is open
	// although it is planned for another day can be completed from Today.
	function openWindowRow(over = {}) {
		return {
			roomTask: { id: 41, taskId: 7, roomId: 1, isActive: true, nextDue: `${dayKey(2)}T00:00:00+00:00` },
			task: { id: 7, name: 'Clean the oven', energyCost: 2 },
			room: { id: 1, name: 'Kitchen' },
			assignees: [],
			openWindow: true,
			// Planned tomorrow, the window reaches from today to the day after.
			cycle: cycle(1, 2, over),
		}
	}

	it('keeps rows with an open flex window behind one line and completes them from there', async () => {
		mockRoomTasksStore.dailyTasks = [openWindowRow()]
		mockRoomTasksStore.overdueTasks = []

		const wrapper = await mountView()
		const toggle = wrapper.get('[data-testid="today-section-open-window"]')

		expect(toggle.text()).toContain('Also possible today · 1')
		expect(toggle.attributes('aria-expanded')).toBe('false')
		expect(wrapper.find('[data-testid="today-complete-button-41"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('Nothing due today')
		expect(wrapper.find('[data-testid="today-empty-state"]').exists()).toBe(false)

		await toggle.trigger('click')

		expect(toggle.attributes('aria-expanded')).toBe('true')
		expect(wrapper.get('[data-testid="today-open-window-planned-41"]').text()).toContain('planned')
		await confirmHold(wrapper, '[data-testid="today-complete-button-41"]')
		expect(mockRoomTasksStore.completeRoomTask).toHaveBeenCalledWith(41)
	})

	it('drops an open-window row once its cycle is no longer open', async () => {
		mockRoomTasksStore.dailyTasks = [openWindowRow({ state: 'upcoming' })]
		mockRoomTasksStore.overdueTasks = []

		const wrapper = await mountView()

		expect(wrapper.find('[data-testid="today-section-open-window"]').exists()).toBe(false)
	})

	it('renders the short plain instructions of a row (CS-PLAN-066 §3.5)', async () => {
		const wrapper = await mountView()

		expect(wrapper.text()).toContain('Rinse first, then load the dishwasher.')
		// Plain escaped text — no v-html surface.
		expect(wrapper.html()).not.toContain('v-html')
	})

	it('reuses floors another view has loaded (FEAT-UX-047)', async () => {
		mockRoomsStore.floors = [{ id: 3, name: 'Upstairs' }]
		const wrapper = await mountView()

		expect(mockRoomsStore.fetchFloorsWithRooms).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="open-setup-wizard"]').exists()).toBe(false)
	})

	it('adds up the energy of the definitions: an edit of the energy shows in the status at once', async () => {
		mockSchedulingStore.getOwnCapacityForDay = () => 4
		mockRoomTasksStore.dailyTasks = [{ ...todayFixture()[0], task: { id: 1, name: 'Do the dishes', energyCost: 3 } }]
		mockRoomTasksStore.overdueTasks = []
		const wrapper = await mountView()

		expect(wrapper.get('[data-testid="today-energy"]').text()).toContain('3 of 4')
		expect(wrapper.find('[data-testid="today-overload"]').exists()).toBe(false)
	})

	it('gives a row the same facts as a task list: energy, recurrence, floor and room (FEAT-UX-047)', async () => {
		mockRoomTasksStore.dailyTasks = [{
			...todayFixture()[0],
			task: { id: 1, name: 'Do the dishes', energyCost: 5, frequencyType: 'daily', frequencyValue: 3, flexDays: 2 },
			room: { id: 1, name: 'Kitchen', floorId: 3 },
		}]
		const wrapper = await mountView()
		const row = wrapper.get('[data-testid="today-task-item"]')

		expect(row.get('[data-testid="task-energy"]').text()).toMatch(/ 5$/)
		expect(row.get('[data-testid="task-recurrence"]').text()).toContain('Every 3 days ±2')
		expect(row.get('[data-testid="task-location"]').text()).toBe('Upstairs · Kitchen')
	})

	it('reads the floors once per view and keeps the room when they fail (FEAT-UX-047)', async () => {
		mockRoomsStore.fetchFloorsWithRooms.mockRejectedValue(new Error('floors down'))
		mockRoomsStore.getFloorById.mockReturnValue(undefined)
		mockRoomTasksStore.dailyTasks = [{ ...todayFixture()[0], room: { id: 1, name: 'Kitchen', floorId: 3 } }]
		const wrapper = await mountView()

		expect(mockRoomsStore.fetchFloorsWithRooms).toHaveBeenCalledTimes(1)
		expect(wrapper.get('[data-testid="task-location"]').text()).toBe('Kitchen')
		expect(wrapper.find('[data-testid="today-error"]').exists()).toBe(false)
		// A failed read says nothing about the house: no setup offer.
		expect(wrapper.find('[data-testid="open-setup-wizard"]').exists()).toBe(false)
	})

	describe('the setup wizard (D-090-12)', () => {
		const emptyHouse = () => {
			mockRoomsStore.fetchFloorsWithRooms.mockResolvedValue([])
			mockRoomTasksStore.dailyTasks = []
			mockRoomTasksStore.overdueTasks = []
		}

		it('opens for the owner of a house without floors and reloads the day once it applied', async () => {
			emptyHouse()
			const wrapper = await mountView()
			await flushPromises()

			const wizard = wrapper.findComponent({ name: 'SetupWizard' })
			expect(wizard.exists()).toBe(true)
			expect(wrapper.find('[data-testid="open-setup-wizard"]').exists()).toBe(true)
			expect(mockRoomTasksStore.fetchFilteredDailyTasks).toHaveBeenCalledTimes(1)

			wizard.vm.$emit('applied')
			await flushPromises()

			expect(mockRoomTasksStore.fetchFilteredDailyTasks).toHaveBeenCalledTimes(2)
			expect(mockGameStore.fetchGameState).toHaveBeenCalledTimes(2)
		})

		it('stays away from a member who is not the owner', async () => {
			emptyHouse()
			mockUserStore.isHouseOwner = false
			const wrapper = await mountView()
			await flushPromises()

			expect(wrapper.findComponent({ name: 'SetupWizard' }).exists()).toBe(false)
			expect(wrapper.find('[data-testid="open-setup-wizard"]').exists()).toBe(false)
		})
	})

	it('shows assigned-to-me and unassigned tasks under the mine filter (default)', async () => {
		const wrapper = await mountView()
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('Do the dishes')
		expect(wrapper.text()).toContain('Wipe kitchen counters')
		expect(wrapper.text()).not.toContain('Tidy up toys')
		expect(wrapper.text()).not.toContain('Take out trash')
		expect(wrapper.text()).toContain('Assigned to: Parent')
		expect(wrapper.text()).toContain('Unassigned')
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

		await confirmHold(wrapper, '[data-testid="today-complete-button-11"]')
		await wrapper.vm.$nextTick()

		expect(mockRoomTasksStore.completeRoomTask).toHaveBeenCalledWith(11)
		expect(wrapper.find('[data-testid="today-completed-check-11"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="today-complete-button-11"]').exists()).toBe(false)
	})

	it('holds a just-completed recurring row in place with its feedback, then lets it go (D-090-30)', async () => {
		mockRoomTasksStore.completeRoomTask.mockImplementation(async (roomTaskId) => {
			// The store reconciles the read and marks the row in one step:
			// the next cycle is planned for tomorrow.
			const index = mockRoomTasksStore.dailyTasks.findIndex(item => item.roomTask.id === roomTaskId)
			const row = mockRoomTasksStore.dailyTasks[index]
			mockRoomTasksStore.dailyTasks[index] = { ...row, cycle: cycle(1) }
			mockRoomTasksStore.justCompletedIds = { [roomTaskId]: true }
			return { roomTask: mockRoomTasksStore.dailyTasks[index].roomTask, rewards: {} }
		})
		const wrapper = await mountView()
		const names = () => wrapper.findAll('[data-testid="today-task-item"] .task-name').map((name) => name.text())
		expect(names()).toEqual(['Do the dishes', 'Wipe kitchen counters'])

		await confirmHold(wrapper, '[data-testid="today-complete-button-11"]')

		expect(names()).toEqual(['Do the dishes', 'Wipe kitchen counters'])
		const held = wrapper.findAll('[data-testid="today-task-item"]')[0]
		expect(held.classes()).toContain('cs-just-completed')
		expect(held.find('[data-testid="today-completed-check-11"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="today-complete-button-11"]').exists()).toBe(false)
		// The status already counts the work as done.
		expect(wrapper.get('[data-testid="today-energy"]').text()).toContain('2 of 5')

		mockRoomTasksStore.justCompletedIds = {}
		await wrapper.vm.$nextTick()

		expect(names()).toEqual(['Wipe kitchen counters'])
	})

	it('holds a completed overdue row in the overdue block until its feedback is over', async () => {
		mockRoomTasksStore.completeRoomTask.mockImplementation(async (roomTaskId) => {
			// The window is closed late; the overdue read no longer lists the task.
			const row = mockRoomTasksStore.overdueTasks.find(item => item.roomTask.id === roomTaskId)
			mockRoomTasksStore.overdueTasks = []
			mockRoomTasksStore.justCompletedIds = { [roomTaskId]: true }
			return { roomTask: row.roomTask, rewards: {} }
		})
		// The viewer's own task: it completes without the participation dialog.
		mockRoomTasksStore.overdueTasks[0].assignees = ['mom']
		const wrapper = await mountView()

		await confirmHold(wrapper, '[data-testid="today-complete-button-30"]')

		expect(wrapper.findAll('[data-testid="today-completed-check-30"]')).toHaveLength(1)
		expect(wrapper.get('[data-testid="today-section-catchup"]').text()).toContain('Take out trash')
		expect(wrapper.find('[data-testid="today-complete-button-30"]').exists()).toBe(false)

		mockRoomTasksStore.justCompletedIds = {}
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="today-section-catchup"]').exists()).toBe(false)
		expect(wrapper.text()).not.toContain('Take out trash')
	})

	it('asks who took part before completing a task that belongs to someone else (WP3 §3.6)', async () => {
		mockRoomTasksStore.dailyTasks[0].assignees = ['teen']
		const wrapper = await mountView()
		await wrapper.find('[data-testid="today-filter-all"]').trigger('click')

		await confirmHold(wrapper, '[data-testid="today-complete-button-11"]')

		expect(mockRoomTasksStore.completeRoomTask).not.toHaveBeenCalled()
		const dialog = wrapper.findComponent({ name: 'ParticipationDialog' })
		expect(dialog.exists()).toBe(true)

		const payload = { participants: [{ userId: 'mom', sharePct: 100 }], allocationReviewed: true }
		dialog.vm.$emit('confirm', payload)
		await flushPromises()

		expect(mockRoomTasksStore.completeRoomTask).toHaveBeenCalledWith(11, payload)
		expect(wrapper.findComponent({ name: 'ParticipationDialog' }).exists()).toBe(false)
	})

	it('marks the Done button busy while its completion is in flight (WP-E)', async () => {
		const wrapper = await mountView()
		const button = () => wrapper.get('[data-testid="today-complete-button-11"]')
		expect(button().attributes('disabled')).toBeUndefined()

		mockRoomTasksStore.completingIds = { 11: true }
		await wrapper.vm.$nextTick()

		expect(button().attributes('disabled')).toBeDefined()
		expect(button().attributes('aria-busy')).toBe('true')
	})

	it('completing a recurring task removes it from the list once its next cycle is planned for another day', async () => {
		mockRoomTasksStore.completeRoomTask.mockImplementation(async (roomTaskId) => {
			const index = mockRoomTasksStore.dailyTasks.findIndex(item => item.roomTask.id === roomTaskId)
			mockRoomTasksStore.dailyTasks[index] = { ...mockRoomTasksStore.dailyTasks[index], cycle: cycle(1) }
			return { roomTask: mockRoomTasksStore.dailyTasks[index].roomTask, rewards: {} }
		})
		const wrapper = await mountView()

		await confirmHold(wrapper, '[data-testid="today-complete-button-11"]')
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).not.toContain('Do the dishes')
	})

	it('completing an overdue task empties the overdue block: its next cycle counts from today', async () => {
		mockRoomTasksStore.overdueTasks[0].assignees = ['mom']
		const wrapper = await mountView()

		await confirmHold(wrapper, '[data-testid="today-complete-button-30"]')
		await flushAsync()

		expect(wrapper.find('[data-testid="today-section-catchup"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="today-complete-button-30"]').exists()).toBe(false)
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

		await confirmHold(wrapper, '[data-testid="today-complete-button-11"]')
		await wrapper.vm.$nextTick()

		expect(showError).toHaveBeenCalled()
		expect(wrapper.find('[data-testid="today-complete-button-11"]').exists()).toBe(true)
	})

	it('appends the API code to the completion failure toast (CS-PLAN-084 WP-F)', async () => {
		mockRoomTasksStore.completeRoomTask.mockRejectedValue(Object.assign(new Error('Conflict'), { status: 409, code: 'CONFLICT' }))
		const { showError } = jest.requireMock('@nextcloud/dialogs')
		const wrapper = await mountView()

		await confirmHold(wrapper, '[data-testid="today-complete-button-11"]')

		expect(showError).toHaveBeenCalledWith('Could not complete the task (CONFLICT)')
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

	// BUG-UX-003 (CS-PLAN-088 D-088-19): the rows carry the item count, so
	// a checklist-less row never offers a toggle — no click needed to learn
	// that, and no per-row request.
	it('offers the checklist toggle only for rows with checklist items', async () => {
		const wrapper = await mountView()
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="today-checklist-toggle-11"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="today-checklist-toggle-12"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="today-checklist-toggle-13"]').exists()).toBe(false)
		expect(mockChecklistStore.fetchTaskItems).not.toHaveBeenCalled()
	})

	it('collapses again when the items were deleted since the row was read', async () => {
		const wrapper = await mountView()
		await wrapper.vm.$nextTick()

		await wrapper.find('[data-testid="today-checklist-toggle-11"]').trigger('click')
		await flushAsync()

		expect(wrapper.find('[data-testid="task-checklist"]').exists()).toBe(false)
	})

	// CS-PLAN-070 WP8 §3.6: the contextual progress/windows help sits on
	// Today (its inline trigger text stays Coin-free, C5f).
	it('offers the contextual progress help next to the Today header', async () => {
		const wrapper = await mountView()
		expect(wrapper.find('[data-testid="help-context-progress"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="help-context-progress"]').text()).not.toContain('Coins')
	})
})
