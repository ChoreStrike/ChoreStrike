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

const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0))

const stubs = {
	AvailabilitySettings: true,
	EnergyBudgetSettings: true,
	SchedulePreview: true,
	ScheduleCalendar: true,
	SchedulingSuggestions: true,
	SelfAvailabilitySettings: true,
}

describe('SchedulingView', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
		api.get.mockResolvedValue({})
		// The "shows an error banner when initialization fails" test (and any
		// benign init log) routes through src/utils/logger.js → console.error.
		// These are expected failure paths; silence the console noise.
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	it('renders the scheduling view with testid', () => {
		const wrapper = mount(SchedulingView, { global: { stubs } })
		expect(wrapper.find('[data-testid="scheduling-view"]').exists()).toBe(true)
	})

	it('renders the header with title', () => {
		const wrapper = mount(SchedulingView, { global: { stubs } })
		expect(wrapper.find('h1').text()).toBe('Smart Scheduling')
	})

	it('renders the self-availability section for every role', async () => {
		// No currentUser resolved yet (non-owner default): the personal
		// self-service section must still render.
		const wrapper = mount(SchedulingView, { global: { stubs } })
		await flushPromises()

		expect(wrapper.findComponent({ name: 'SelfAvailabilitySettings' }).exists()).toBe(true)
	})

	it('renders the owner availability grid when the current user is owner', async () => {
		const userStore = useUserStore()
		userStore.currentUser = { id: 1, role: 'owner' }

		const wrapper = mount(SchedulingView, { global: { stubs } })
		await flushPromises()
		await flushPromises()

		expect(wrapper.findComponent({ name: 'AvailabilitySettings' }).exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'SelfAvailabilitySettings' }).exists()).toBe(true)
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
})
