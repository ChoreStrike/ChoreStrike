import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import CalendarView from '../../../src/views/CalendarView.vue'

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
})
