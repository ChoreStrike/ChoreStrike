import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ScheduleCalendar from '../../../src/components/Scheduling/ScheduleCalendar.vue'
import { useSchedulingStore } from '../../../src/store/scheduling.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

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
