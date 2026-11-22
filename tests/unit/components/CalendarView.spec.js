import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import CalendarView from '../../../src/views/CalendarView.vue'
import { useRoomTasksStore } from '../../../src/store/roomTasks.js'
import { useUserStore } from '../../../src/store/user.js'
import { api } from '../../../src/services/api.js'
import { addDaysToKey, todayKeyInZone } from '../../../src/utils/dateKeys.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

jest.mock('../../../src/services/api.js', () => ({
	api: {
		get: jest.fn(),
	},
}))

/** What the three reads of the view answer, by path. */
const answers = (suggestion = { days: [], overdue: [], members: [] }) => (path) => {
	if (path === '/api/v1/scheduling/suggestion') {
		return Promise.resolve(suggestion)
	}
	return Promise.resolve(path === '/api/v1/users' ? [] : { scheduled: [], completed: [] })
}
const flush = () => new Promise((resolve) => setTimeout(resolve, 0))
const suggestionCalls = () => api.get.mock.calls.filter(([path]) => path === '/api/v1/scheduling/suggestion')

jest.mock('../../../src/utils/logger.js', () => ({
	logger: { error: jest.fn(), info: jest.fn(), warn: jest.fn() },
}))

describe('CalendarView', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
		api.get.mockImplementation(answers())
	})

	it('renders the calendar view with testids', () => {
		const wrapper = mount(CalendarView, {
			global: { stubs: { TaskCalendar: true, RouterLink: true } },
		})
		expect(wrapper.find('[data-testid="calendar-view"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="calendar-mode-toggle"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="calendar-mode-week"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="calendar-mode-month"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="calendar-prev"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="calendar-next"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="calendar-range-label"]').exists()).toBe(true)
	})

	it('defaults to week mode', () => {
		const wrapper = mount(CalendarView, {
			global: { stubs: { TaskCalendar: true, RouterLink: true } },
		})
		expect(wrapper.find('[data-testid="calendar-mode-week"]').classes()).toContain('active')
	})

	it('switches to month mode on toggle click', async () => {
		const wrapper = mount(CalendarView, {
			global: { stubs: { TaskCalendar: true, RouterLink: true } },
		})
		await wrapper.find('[data-testid="calendar-mode-month"]').trigger('click')
		expect(wrapper.find('[data-testid="calendar-mode-month"]').classes()).toContain('active')
		expect(wrapper.find('[data-testid="calendar-mode-week"]').classes()).not.toContain('active')
	})

	it('changes range label on next navigation', async () => {
		const wrapper = mount(CalendarView, {
			global: { stubs: { TaskCalendar: true, RouterLink: true } },
		})
		const labelBefore = wrapper.find('[data-testid="calendar-range-label"]').text()
		await wrapper.find('[data-testid="calendar-next"]').trigger('click')
		const labelAfter = wrapper.find('[data-testid="calendar-range-label"]').text()
		expect(labelAfter).not.toBe(labelBefore)
	})

	it('shows 7 days in week mode and 30 in month mode', async () => {
		const wrapper = mount(CalendarView, {
			global: { stubs: { TaskCalendar: true, RouterLink: true } },
		})
		expect(wrapper.vm.visibleDays).toHaveLength(7)
		await wrapper.find('[data-testid="calendar-mode-month"]').trigger('click')
		expect(wrapper.vm.visibleDays).toHaveLength(30)
	})

	it('renders a recurring marker for recurring tasks in the day detail', async () => {
		const store = useRoomTasksStore()
		const wrapper = mount(CalendarView, {
			global: { stubs: { TaskCalendar: true, RouterLink: true } },
		})
		// Let the mount-time range fetch settle before seeding the store.
		await wrapper.vm.$nextTick()

		const day = wrapper.vm.visibleDays[0]
		store.rangeTasks = {
			scheduled: [{
				roomTask: { id: 1, nextDue: day + 'T10:00:00' },
				task: { name: 'Dishes', recurrenceMode: 'recurring' },
				room: { name: 'Kitchen' },
				flexWindow: {},
				occurrences: [day],
			}],
			completed: [],
			from: day,
			to: day,
		}
		await wrapper.setData({ selectedDay: day })
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="calendar-day-list"]').exists()).toBe(true)
		expect(wrapper.find('.task-repeat').exists()).toBe(true)
	})

	// CR-084-01: a past planned day whose flex window is still open is due,
	// not overdue — the day entry follows the server's per-day late mark.
	it('marks only day entries whose window has ended as overdue', async () => {
		const store = useRoomTasksStore()
		const wrapper = mount(CalendarView, {
			global: { stubs: { TaskCalendar: true, RouterLink: true } },
		})
		await wrapper.vm.$nextTick()

		const [missedDay, openDay] = wrapper.vm.visibleDays
		store.rangeTasks = {
			scheduled: [{
				roomTask: { id: 1, isActive: true, nextDue: missedDay + 'T00:00:00', status: 'overdue' },
				task: { name: 'Dishes', recurrenceMode: 'recurring' },
				room: { name: 'Kitchen' },
				cycles: [
					{ date: missedDay, cycleKey: `d${missedDay}`, current: true, late: true },
					{ date: openDay, cycleKey: null, current: false, late: false },
				],
			}],
			completed: [],
			from: missedDay,
			to: openDay,
		}

		await wrapper.setData({ selectedDay: missedDay })
		expect(wrapper.find('[data-testid="calendar-day-list"] .task-item').classes()).toContain('overdue')

		await wrapper.setData({ selectedDay: openDay })
		expect(wrapper.find('[data-testid="calendar-day-list"] .task-item').classes()).not.toContain('overdue')
	})

	it('falls back to the row rule for a day entry without a late mark', () => {
		const wrapper = mount(CalendarView, {
			global: { stubs: { TaskCalendar: true, RouterLink: true } },
		})
		const roomTask = { isActive: true, nextDue: '2000-01-01T00:00:00', status: 'due_today' }
		expect(wrapper.vm.isOverdue({ roomTask, dueDate: '2000-01-01', late: null })).toBe(false)
		expect(wrapper.vm.isOverdue({ roomTask: { ...roomTask, status: 'overdue' }, dueDate: '2000-01-01', late: null })).toBe(true)
	})

	// CS-PLAN-091 WP-C (ADR-022): the week is a list of days with the live
	// suggestion; the weekly plan's page and its link are gone.
	describe('the week with the live suggestion', () => {
		const today = todayKeyInZone('UTC')
		const entry = (userIds, taskName) => ({ roomTaskId: 1, cycleKey: 'c1', userIds, energy: 2, overload: false, windowStartOn: today, windowEndOn: today, taskName, roomName: 'Kitchen' })
		const mountWeek = async (suggestion) => {
			if (suggestion) {
				api.get.mockImplementation(answers(suggestion))
			}
			useUserStore().currentUser = { id: 1, nextcloudUserId: 'mom', role: 'owner' }
			const wrapper = mount(CalendarView)
			await flush()
			return wrapper
		}

		it('shows the week as a list of days, without the grid and without a link to a weekly plan', async () => {
			const wrapper = await mountWeek()

			expect(wrapper.findAll('[data-testid^="calendar-agenda-day-"]')).toHaveLength(7)
			expect(wrapper.find('[data-testid="task-calendar"]').exists()).toBe(false)
			expect(wrapper.find('[data-testid="calendar-open-plan"]').exists()).toBe(false)
			expect(wrapper.get('[data-testid="calendar-hint"]').text()).toContain('A suggestion, not a plan')
		})

		it('reads the suggestion from today to the end of the shown week, never for a past day', async () => {
			const wrapper = await mountWeek()

			expect(suggestionCalls()).toEqual([['/api/v1/scheduling/suggestion', { params: { from: today, to: wrapper.vm.rangeEnd } }]])
		})

		it('ends the read at the horizon and reads none for a week outside it', async () => {
			const wrapper = await mountWeek()
			const horizonEnd = addDaysToKey(today, 13)

			await wrapper.get('[data-testid="calendar-next"]').trigger('click')
			await flush()
			const second = suggestionCalls()[1][1].params
			expect(second.from).toBe(wrapper.vm.rangeStart)
			expect(second.to <= horizonEnd).toBe(true)

			await wrapper.get('[data-testid="calendar-next"]').trigger('click')
			await wrapper.get('[data-testid="calendar-next"]').trigger('click')
			await flush()
			// Three weeks ahead no day of the week lies inside the 14-day horizon.
			expect(wrapper.vm.rangeStart > horizonEnd).toBe(true)
			expect(suggestionCalls().every(([, config]) => config.params.to <= horizonEnd)).toBe(true)
			expect(wrapper.findAll('[data-testid="calendar-agenda-entry"]')).toHaveLength(0)

			await wrapper.get('[data-testid="calendar-prev"]').trigger('click')
			await wrapper.get('[data-testid="calendar-prev"]').trigger('click')
			await wrapper.get('[data-testid="calendar-prev"]').trigger('click')
			await wrapper.get('[data-testid="calendar-prev"]').trigger('click')
			await flush()
			// A week that is over shows what was done, without a suggestion read.
			const calls = suggestionCalls().length
			expect(wrapper.vm.rangeEnd < today).toBe(true)
			expect(suggestionCalls()).toHaveLength(calls)
		})

		it('shows the viewer\'s suggestion first and everyone\'s on request', async () => {
			const wrapper = await mountWeek({
				days: [{ day: today, entries: [entry(['mom'], 'Mine'), entry(['kid'], 'The kid\'s')] }],
				overdue: [],
				members: [{ userId: 'mom', day: today, budget: 6, placed: 2, doneMinor: 0, preferred: false, absent: false }],
			})
			const names = () => wrapper.findAll('[data-testid="calendar-agenda-entry"] .entry-name').map((name) => name.text())

			expect(wrapper.get('[data-testid="calendar-filter-mine"]').attributes('aria-pressed')).toBe('true')
			expect(names()).toEqual(['Mine'])
			expect(wrapper.get(`[data-testid="calendar-agenda-load-${today}"]`).text()).toBe('2/6')

			await wrapper.get('[data-testid="calendar-filter-all"]').trigger('click')
			expect(names()).toHaveLength(2)
		})

		it('keeps the grid for the month and reads no suggestion there', async () => {
			const wrapper = await mountWeek()
			const calls = suggestionCalls().length

			await wrapper.get('[data-testid="calendar-mode-month"]').trigger('click')
			await flush()

			expect(wrapper.find('[data-testid="task-calendar"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="calendar-agenda"]').exists()).toBe(false)
			expect(wrapper.find('[data-testid="calendar-filter-mine"]').exists()).toBe(false)
			expect(suggestionCalls()).toHaveLength(calls)
		})

		it('shows the error state when the suggestion cannot be read', async () => {
			api.get.mockImplementation((path) => (path === '/api/v1/scheduling/suggestion' ? Promise.reject(new Error('down')) : answers()(path)))
			const wrapper = await mountWeek()

			expect(wrapper.find('[data-testid="calendar-error"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="calendar-agenda"]').exists()).toBe(false)
		})
	})
})
