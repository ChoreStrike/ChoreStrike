import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import AvailabilitySettings from '../../../src/components/Scheduling/AvailabilitySettings.vue'
import { useSchedulingStore } from '../../../src/store/scheduling.js'
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

	it('saves the full week in one bulk call, defaulting unset energy days from the stored budget', async () => {
		const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0))
		store.memberAvailability = [
			{
				userId: 'alice',
				userName: 'Alice',
				weekdayAvailability: {
					monday: true, tuesday: true, wednesday: true, thursday: true,
					friday: true, saturday: true, sunday: true,
				},
				energyBudget: {
					monday: 11, tuesday: 12, wednesday: 13, thursday: 14,
					friday: 15, saturday: 16, sunday: 17,
				},
			},
		]
		api.put.mockResolvedValue({})
		api.get.mockResolvedValue([])

		// Full mount: the edit form lives inside the (unstubbed) modal.
		const wrapper = mount(AvailabilitySettings)
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="edit-availability-button"]').trigger('click')

		// Change monday availability + monday energy; leave the other energy
		// inputs untouched so they must default from the stored budget.
		await wrapper.find('[data-testid="availability-monday"]').setValue(false)
		await wrapper.find('[data-testid="budget-monday-input"]').setValue(5)
		await wrapper.find('[data-testid="save-availability-button"]').trigger('click')
		await flushPromises()

		expect(api.put).toHaveBeenCalledTimes(1)
		expect(api.put).toHaveBeenCalledWith('/api/v1/scheduling/preferences', {
			weekdayAvailability: {
				monday: false, tuesday: true, wednesday: true, thursday: true,
				friday: true, saturday: true, sunday: true,
			},
			energyBudget: {
				monday: 5, tuesday: 12, wednesday: 13, thursday: 14,
				friday: 15, saturday: 16, sunday: 17,
			},
		})
	})

	it('defaults days missing from a partial stored budget to the backend defaults (10 weekdays / 15 weekend)', async () => {
		const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0))
		store.memberAvailability = [
			{
				userId: 'alice',
				userName: 'Alice',
				weekdayAvailability: {
					monday: true, tuesday: true, wednesday: true, thursday: true,
					friday: true, saturday: true, sunday: true,
				},
				// Partial section: only monday is stored (owner day-granular path).
				energyBudget: { monday: 11 },
			},
		]
		api.put.mockResolvedValue({})
		api.get.mockResolvedValue([])

		const wrapper = mount(AvailabilitySettings)
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="edit-availability-button"]').trigger('click')

		await wrapper.find('[data-testid="budget-monday-input"]').setValue(5)
		await wrapper.find('[data-testid="save-availability-button"]').trigger('click')
		await flushPromises()

		expect(api.put).toHaveBeenCalledTimes(1)
		expect(api.put).toHaveBeenCalledWith('/api/v1/scheduling/preferences', {
			weekdayAvailability: {
				monday: true, tuesday: true, wednesday: true, thursday: true,
				friday: true, saturday: true, sunday: true,
			},
			energyBudget: {
				monday: 5, tuesday: 10, wednesday: 10, thursday: 10,
				friday: 10, saturday: 15, sunday: 15,
			},
		})
	})
})
