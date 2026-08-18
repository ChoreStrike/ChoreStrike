import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import SelfAvailabilitySettings from '../../../src/components/Scheduling/SelfAvailabilitySettings.vue'
import { useSchedulingStore } from '../../../src/store/scheduling.js'
import { api } from '../../../src/services/api.js'
import { showError } from '@nextcloud/dialogs'

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

const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0))

const allDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']

describe('SelfAvailabilitySettings', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSchedulingStore()
		jest.clearAllMocks()
		api.put.mockResolvedValue({})
		// The API-failure test routes through src/utils/logger.js →
		// console.error; silence the expected failure-path noise.
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	const mountComponent = () => mount(SelfAvailabilitySettings)

	it('renders with data-testid and seven day toggles', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('[data-testid="self-availability-settings"]').exists()).toBe(true)
		allDays.forEach((day) => {
			expect(wrapper.find(`[data-testid="self-availability-${day}"]`).exists()).toBe(true)
		})
	})

	it('reflects the current weekdayAvailability from the store', async () => {
		store.weekdayAvailability = {
			monday: true, tuesday: true, wednesday: true, thursday: true,
			friday: true, saturday: false, sunday: false,
		}
		const wrapper = mountComponent()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-testid="self-availability-saturday"]').element.checked).toBe(false)
		expect(wrapper.find('[data-testid="self-availability-sunday"]').element.checked).toBe(false)
		expect(wrapper.find('[data-testid="self-availability-monday"]').element.checked).toBe(true)
	})

	it('shows the available-days count from local state', async () => {
		store.weekdayAvailability = {
			monday: true, tuesday: true, wednesday: true, thursday: true,
			friday: true, saturday: false, sunday: false,
		}
		const wrapper = mountComponent()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-testid="self-availability-count"]').text()).toContain('5/7')
	})

	it('disables save until a toggle differs from the store', async () => {
		const wrapper = mountComponent()
		const saveButton = wrapper.find('[data-testid="save-self-availability-button"]')
		expect(saveButton.attributes('disabled')).toBeDefined()

		await wrapper.find('[data-testid="self-availability-monday"]').setValue(false)
		expect(saveButton.attributes('disabled')).toBeUndefined()
	})

	it('saves only changed days and updates store state on success', async () => {
		const wrapper = mountComponent()

		await wrapper.find('[data-testid="self-availability-tuesday"]').setValue(false)
		await wrapper.find('[data-testid="self-availability-saturday"]').setValue(false)
		await wrapper.find('[data-testid="save-self-availability-button"]').trigger('click')
		await flushPromises()

		expect(api.put).toHaveBeenCalledTimes(2)
		expect(api.put).toHaveBeenCalledWith('/api/v1/scheduling/availability', { day: 'tuesday', available: false })
		expect(api.put).toHaveBeenCalledWith('/api/v1/scheduling/availability', { day: 'saturday', available: false })

		expect(store.weekdayAvailability.tuesday).toBe(false)
		expect(store.weekdayAvailability.saturday).toBe(false)
		expect(store.weekdayAvailability.monday).toBe(true)
	})

	it('shows an error toast and keeps local edits on API failure', async () => {
		api.put.mockRejectedValueOnce(new Error('network down'))
		const wrapper = mountComponent()

		await wrapper.find('[data-testid="self-availability-wednesday"]').setValue(false)
		await wrapper.find('[data-testid="save-self-availability-button"]').trigger('click')
		await flushPromises()

		expect(api.put).toHaveBeenCalledTimes(1)
		expect(showError).toHaveBeenCalled()
		// Store state must not be corrupted by the failed call.
		expect(store.weekdayAvailability.wednesday).toBe(true)
		// Local edit survives so the user can retry.
		expect(wrapper.find('[data-testid="self-availability-wednesday"]').element.checked).toBe(false)
	})

	it('cancel reverts local edits to the store state', async () => {
		const wrapper = mountComponent()

		await wrapper.find('[data-testid="self-availability-friday"]').setValue(false)
		await wrapper.find('[data-testid="cancel-self-availability-button"]').trigger('click')

		expect(wrapper.find('[data-testid="self-availability-friday"]').element.checked).toBe(true)
		const saveButton = wrapper.find('[data-testid="save-self-availability-button"]')
		expect(saveButton.attributes('disabled')).toBeDefined()
	})
})
