import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ScheduleCalendar from '../../../src/components/Scheduling/ScheduleCalendar.vue'
import { useSchedulingStore } from '../../../src/store/scheduling.js'
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
