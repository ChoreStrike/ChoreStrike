import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import AvailabilitySettings from '../../../src/components/Scheduling/AvailabilitySettings.vue'
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

jest.mock('../../../src/utils/currentUser.js', () => ({
	resolveCurrentUser: jest.fn(() => ({ uid: 'alice', displayName: 'Alice', userId: 'alice' })),
}))

describe('AvailabilitySettings', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSchedulingStore()
		jest.clearAllMocks()
	})

	const mountComponent = () => {
		return mount(AvailabilitySettings, {
			global: {
				stubs: {
					Modal: true,
				},
			},
		})
	}

	it('renders with data-testid', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('[data-testid="availability-settings"]').exists()).toBe(true)
	})

	it('shows empty state when no members', () => {
		store.memberAvailability = []
		const wrapper = mountComponent()
		expect(wrapper.find('.empty-state').exists()).toBe(true)
	})

	it('renders member cards when members exist', () => {
		store.memberAvailability = [
			{ userId: 'alice', userName: 'Alice', weekdayAvailability: { monday: true }, energyBudget: { monday: 10 } },
		]
		const wrapper = mountComponent()
		expect(wrapper.find('.member-card').exists()).toBe(true)
		expect(wrapper.text()).toContain('Alice')
	})

	it('renders availability grid with testid', () => {
		store.memberAvailability = [
			{ userId: 'alice', userName: 'Alice', weekdayAvailability: { monday: true }, energyBudget: { monday: 10 } },
		]
		const wrapper = mountComponent()
		expect(wrapper.find('[data-testid="availability-grid"]').exists()).toBe(true)
	})

	it('shows edit button for current user', async () => {
		store.memberAvailability = [
			{ userId: 'alice', userName: 'Alice', weekdayAvailability: { monday: true }, energyBudget: { monday: 10 } },
		]
		const wrapper = mountComponent()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-testid="edit-availability-button"]').exists()).toBe(true)
	})

	it('renders availability slots with testid', () => {
		store.memberAvailability = [
			{ userId: 'alice', userName: 'Alice', weekdayAvailability: { monday: true }, energyBudget: { monday: 10 } },
		]
		const wrapper = mountComponent()
		expect(wrapper.findAll('[data-testid="availability-slot"]').length).toBe(7)
	})
})
