import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ScheduleCalendar from '../../../src/components/Scheduling/ScheduleCalendar.vue'
import { useSchedulingStore } from '../../../src/store/scheduling.js'
import { useRoomTasksStore } from '../../../src/store/roomTasks.js'
import { useTasksStore } from '../../../src/store/tasks.js'
import { useUserStore } from '../../../src/store/user.js'
import { useHouseStore } from '../../../src/store/house.js'
import { houseDateOffset, journeyClock } from '../../e2e/fixtures/house-clock.js'
import { getDayNames, getLocale } from '@nextcloud/l10n'

jest.mock('@nextcloud/dialogs', () => ({
	showSuccess: jest.fn(),
	showError: jest.fn(),
}))

jest.mock('../../../src/services/api.js', () => ({
	api: {
		get: jest.fn(),
		put: jest.fn(),
		post: jest.fn(),
	},
}))

describe('ScheduleCalendar', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSchedulingStore()
		jest.clearAllMocks()
		getLocale.mockReturnValue('en')
	})

	const mountComponent = () => {
		return mount(ScheduleCalendar)
	}

	it.each(['2026-09-12', '2026-09-13', '2026-09-14'])('keeps the overloaded fixture visible on %s (Saturday/Sunday/Monday)', async day => {
		jest.useFakeTimers().setSystemTime(new Date(`${day}T12:00:00Z`))
		useHouseStore().house = { timezone: 'UTC' }
		useUserStore().currentUser = { role: 'owner', nextcloudUserId: 'dad' }
		const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']
		store.memberAvailability = [{ userId: 'dad', userName: 'Dad',
			weekdayAvailability: Object.fromEntries(days.map(key => [key, true])),
			energyBudget: Object.fromEntries(days.map(key => [key, 28])) }]
		const fixtureDay = houseDateOffset(0, 'UTC')
		useTasksStore().tasks = [{ id: 1, energyCost: 30 }]
		useRoomTasksStore().roomTasks = [{ id: 1, taskId: 1, isActive: true, nextDue: `${fixtureDay}T12:00:00Z` }]
		const wrapper = mountComponent()
		try {
			await wrapper.vm.$nextTick()
			expect(fixtureDay).toBe(day)
			expect(wrapper.findAll('[data-testid="overcapacity-marker"]')).toHaveLength(1)
			expect(wrapper.get('[data-testid="overcapacity-marker"]').text()).toContain('30/28')
		} finally { wrapper.unmount(); jest.useRealTimers() }
	})

	it.each(Array.from({ length: 24 }, (_, hour) => hour))('the journey clock stays away from midnight at UTC hour %i', hour => {
		const now = new Date(Date.UTC(2026, 8, 14, hour))
		const clock = journeyClock(now)
		const localHour = Number(new Intl.DateTimeFormat('en-GB', { timeZone: clock.timeZone, hour: 'numeric', hourCycle: 'h23' }).format(now))
		expect(localHour).toBeGreaterThanOrEqual(6)
		expect(localHour).toBeLessThanOrEqual(19)
		expect(clock.today).toBe(houseDateOffset(0, clock.timeZone, now))
	})

	it('renders with data-testid', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('[data-testid="schedule-calendar"]').exists()).toBe(true)
	})

	it('renders navigation buttons', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('[data-testid="previous-week-button"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="next-week-button"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="today-button"]').exists()).toBe(true)
	})

	it('renders 7 day headers', async () => {
		const wrapper = mountComponent()
		await wrapper.vm.$nextTick()
		expect(wrapper.findAll('.day-header').length).toBe(7)
	})

	it('uses the Nextcloud day names for the week header (CR-016-06)', async () => {
		const wrapper = mountComponent()
		await wrapper.vm.$nextTick()

		const names = getDayNames().map((name) => name.toLowerCase())
		for (const header of wrapper.findAll('.day-header .day-name')) {
			expect(names).toContain(header.text().toLowerCase())
		}
	})

	it('formats the week range locale-aware with Intl (en shape)', () => {
		getLocale.mockReturnValue('en')
		const wrapper = mountComponent()
		const range = wrapper.find('[data-testid="week-range"]').text()
		// en: "Aug 24 - Aug 30" — month before day.
		expect(range).toMatch(/^[A-Z][a-z]{2} \d{1,2} - [A-Z][a-z]{2} \d{1,2}$/)
	})

	it('formats the week range locale-aware with Intl (de shape)', () => {
		getLocale.mockReturnValue('de')
		const wrapper = mountComponent()
		const range = wrapper.find('[data-testid="week-range"]').text()
		// de: "24. Aug. - 30. Aug." — day before month, dotted. Month
		// abbreviations vary between ICU builds ("Sep."/"Sept."), so accept
		// 3-4 letters after the initial capital.
		expect(range).toMatch(/^\d{1,2}\. [A-Z][a-z]{2,4}\.? - \d{1,2}\. [A-Z][a-z]{2,4}\.?$/)
	})

	it('shows empty state when no members', () => {
		store.memberAvailability = []
		const wrapper = mountComponent()
		expect(wrapper.find('.empty-state').exists()).toBe(true)
	})

	it('renders member rows when members exist', () => {
		store.memberAvailability = [
			{ userId: 'alice', userName: 'Alice', weekdayAvailability: { monday: true }, energyBudget: { monday: 10 } },
		]
		const wrapper = mountComponent()
		expect(wrapper.find('.member-row').exists()).toBe(true)
		expect(wrapper.text()).toContain('Alice')
	})
})
