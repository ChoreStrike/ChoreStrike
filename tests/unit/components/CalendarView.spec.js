import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import CalendarView from '../../../src/views/CalendarView.vue'
import { useRoomTasksStore } from '../../../src/store/roomTasks.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

jest.mock('../../../src/services/api.js', () => ({
	api: {
		get: jest.fn().mockResolvedValue({ scheduled: [], completed: [] }),
	},
}))

jest.mock('../../../src/utils/logger.js', () => ({
	logger: { error: jest.fn(), info: jest.fn(), warn: jest.fn() },
}))

describe('CalendarView', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
	})

	it('renders the calendar view with testids', () => {
		const wrapper = mount(CalendarView, {
			global: { stubs: { TaskCalendar: true } },
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
			global: { stubs: { TaskCalendar: true } },
		})
		expect(wrapper.find('[data-testid="calendar-mode-week"]').classes()).toContain('active')
	})

	it('switches to month mode on toggle click', async () => {
		const wrapper = mount(CalendarView, {
			global: { stubs: { TaskCalendar: true } },
		})
		await wrapper.find('[data-testid="calendar-mode-month"]').trigger('click')
		expect(wrapper.find('[data-testid="calendar-mode-month"]').classes()).toContain('active')
		expect(wrapper.find('[data-testid="calendar-mode-week"]').classes()).not.toContain('active')
	})

	it('changes range label on next navigation', async () => {
		const wrapper = mount(CalendarView, {
			global: { stubs: { TaskCalendar: true } },
		})
		const labelBefore = wrapper.find('[data-testid="calendar-range-label"]').text()
		await wrapper.find('[data-testid="calendar-next"]').trigger('click')
		const labelAfter = wrapper.find('[data-testid="calendar-range-label"]').text()
		expect(labelAfter).not.toBe(labelBefore)
	})

	it('shows 7 days in week mode and 30 in month mode', async () => {
		const wrapper = mount(CalendarView, {
			global: { stubs: { TaskCalendar: true } },
		})
		expect(wrapper.vm.visibleDays).toHaveLength(7)
		await wrapper.find('[data-testid="calendar-mode-month"]').trigger('click')
		expect(wrapper.vm.visibleDays).toHaveLength(30)
	})

	it('renders a recurring marker for recurring tasks in the day detail', async () => {
		const store = useRoomTasksStore()
		const wrapper = mount(CalendarView, {
			global: { stubs: { TaskCalendar: true } },
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

	// CS-PLAN-065 §3.4 (RF-065-02): a committed owner plan may have moved
	// occurrences inside the visible window — the mounted calendar must
	// refetch exactly that window on the `schedule-applied` event.
	describe('schedule-applied reconciliation', () => {
		const appliedHandler = () => {
			const call = subscribe.mock.calls.find(
				([event]) => event === 'chorestrike:schedule-applied',
			)
			expect(call).toBeDefined()
			return call[1]
		}

		it('subscribes on mount and refetches the visible range on the event', async () => {
			const store = useRoomTasksStore()
			const fetchSpy = jest.spyOn(store, 'fetchRangeTasks').mockResolvedValue(undefined)

			const wrapper = mount(CalendarView, {
				global: { stubs: { TaskCalendar: true } },
			})
			await wrapper.vm.$nextTick()

			// The immediate range watcher already fetched once on mount.
			expect(fetchSpy).toHaveBeenCalledTimes(1)
			const [from, to] = fetchSpy.mock.calls[0]
			expect(from).toBe(wrapper.vm.rangeStart)
			expect(to).toBe(wrapper.vm.rangeEnd)

			fetchSpy.mockClear()
			await appliedHandler()({})
			expect(fetchSpy).toHaveBeenCalledTimes(1)
			expect(fetchSpy).toHaveBeenCalledWith(from, to)
		})

		it('unsubscribes the handler on unmount', async () => {
			const store = useRoomTasksStore()
			jest.spyOn(store, 'fetchRangeTasks').mockResolvedValue(undefined)

			const wrapper = mount(CalendarView, {
				global: { stubs: { TaskCalendar: true } },
			})
			const handler = appliedHandler()

			wrapper.unmount()
			expect(unsubscribe).toHaveBeenCalledWith('chorestrike:schedule-applied', handler)
		})
	})
})
