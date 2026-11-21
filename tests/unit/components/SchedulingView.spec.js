import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import SchedulingView from '../../../src/views/SchedulingView.vue'
import { useSchedulingStore } from '../../../src/store/scheduling.js'
import { useUserStore } from '../../../src/store/user.js'
import { api } from '../../../src/services/api.js'

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

jest.mock('../../../src/utils/currentUser.js', () => ({
	resolveCurrentUser: jest.fn(() => ({ uid: 'alice', displayName: 'Alice', userId: 'alice' })),
}))

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0))

const stubs = {
	AvailabilitySettings: true,
	ScheduleCalendar: true,
	RouterLink: { props: ['to'], template: '<a :data-to="to.name"><slot /></a>' },
}

describe('SchedulingView', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
		api.get.mockResolvedValue({})
	})

	it('renders the scheduling view with testid', () => {
		const wrapper = mount(SchedulingView, { global: { stubs } })
		expect(wrapper.find('[data-testid="scheduling-view"]').exists()).toBe(true)
	})

	it('renders the header with title', () => {
		const wrapper = mount(SchedulingView, { global: { stubs } })
		expect(wrapper.find('h1').text()).toBe('Smart Scheduling')
	})

	// CS-PLAN-090 WP-D: the own days and energy moved to the personal
	// settings, and the page is reached from the calendar.
	it('no longer holds the personal planning settings, for any role', async () => {
		const wrapper = mount(SchedulingView, { global: { stubs } })
		await flushPromises()

		expect(wrapper.findComponent({ name: 'SelfAvailabilitySettings' }).exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'EnergyBudgetSettings' }).exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'ScheduleCalendar' }).exists()).toBe(true)
	})

	it('leads back to the calendar it is reached from', () => {
		const wrapper = mount(SchedulingView, { global: { stubs } })

		expect(wrapper.get('[data-testid="scheduling-back"]').attributes('data-to')).toBe('calendar')
	})

	it('renders the owner availability grid when the current user is owner', async () => {
		const userStore = useUserStore()
		userStore.currentUser = { id: 1, role: 'owner' }

		const wrapper = mount(SchedulingView, { global: { stubs } })
		await flushPromises()
		await flushPromises()

		expect(wrapper.findComponent({ name: 'AvailabilitySettings' }).exists()).toBe(true)
	})

	it('hides the owner availability grid for non-owners', async () => {
		const userStore = useUserStore()
		userStore.currentUser = { id: 2, role: 'member' }

		const wrapper = mount(SchedulingView, { global: { stubs } })
		await flushPromises()
		await flushPromises()

		// The absent component IS the claim here; the "No Members Found"
		// empty-state assertion lives in the E2E spec where the real
		// AvailabilitySettings renders.
		expect(wrapper.findComponent({ name: 'AvailabilitySettings' }).exists()).toBe(false)
	})

	// ADR-022: nothing is applied as a plan any more — no role gets a planner.
	it.each(['owner', 'member', 'child'])('offers no plan planner to the %s role', async (role) => {
		const userStore = useUserStore()
		userStore.currentUser = { id: 2, role }

		const wrapper = mount(SchedulingView, { global: { stubs } })
		await flushPromises()
		await flushPromises()

		expect(wrapper.find('[data-testid="schedule-planner"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="schedule-calendar"]').exists()).toBe(true)
	})

	it('initializes the scheduling store on mount', async () => {
		const store = useSchedulingStore()
		const spy = jest.spyOn(store, 'initializeScheduling')

		mount(SchedulingView, { global: { stubs } })
		await flushPromises()

		expect(spy).toHaveBeenCalledTimes(1)
	})

	it('shows an error banner when initialization fails', async () => {
		api.get.mockRejectedValueOnce(new Error('preferences unavailable'))

		const wrapper = mount(SchedulingView, { global: { stubs } })
		await flushPromises()
		await flushPromises()

		expect(wrapper.find('[data-testid="scheduling-error"]').exists()).toBe(true)
	})

	// CS-PLAN-070 WP8 §3.6: the essential shares help sits on
	// the scheduling surface.
	it('offers the contextual shares help next to the header', () => {
		const wrapper = mount(SchedulingView, { global: { stubs } })
		expect(wrapper.find('[data-testid="help-context-shares"]').exists()).toBe(true)
	})
})
