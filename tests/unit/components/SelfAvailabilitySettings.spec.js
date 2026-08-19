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

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
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

	it('saves the full week in one bulk call and updates store state on success', async () => {
		const editedWeek = {
			monday: true, tuesday: false, wednesday: true, thursday: true,
			friday: true, saturday: false, sunday: true,
		}
		api.put.mockResolvedValue({ weekdayAvailability: editedWeek })

		const wrapper = mountComponent()

		await wrapper.find('[data-testid="self-availability-tuesday"]').setValue(false)
		await wrapper.find('[data-testid="self-availability-saturday"]').setValue(false)
		await wrapper.find('[data-testid="save-self-availability-button"]').trigger('click')
		await flushPromises()

		expect(api.put).toHaveBeenCalledTimes(1)
		expect(api.put).toHaveBeenCalledWith('/api/v1/scheduling/preferences', {
			weekdayAvailability: editedWeek,
		})

		expect(store.weekdayAvailability).toEqual(editedWeek)
	})

	it('disables the day toggles while a save is in flight', async () => {
		let resolvePut
		api.put.mockReturnValue(new Promise((resolve) => { resolvePut = resolve }))

		const wrapper = mountComponent()
		await wrapper.find('[data-testid="self-availability-tuesday"]').setValue(false)
		await wrapper.find('[data-testid="save-self-availability-button"]').trigger('click')
		await flushPromises()

		// In-flight: no further edits possible, so the response sync can
		// never clobber an edit made between click and response.
		expect(wrapper.find('[data-testid="self-availability-monday"]').attributes('disabled')).toBeDefined()

		resolvePut({ weekdayAvailability: { ...store.weekdayAvailability, tuesday: false } })
		await flushPromises()
		expect(wrapper.find('[data-testid="self-availability-monday"]').attributes('disabled')).toBeUndefined()
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
