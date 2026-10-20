import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import EnergyBudgetSettings from '../../../src/components/Scheduling/EnergyBudgetSettings.vue'
import WeekBudgetGrid from '../../../src/components/Scheduling/WeekBudgetGrid.vue'
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

const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0))

const fullBudget = (overrides = {}) => ({
	monday: 10, tuesday: 10, wednesday: 10, thursday: 10,
	friday: 10, saturday: 10, sunday: 10,
	...overrides,
})

describe('EnergyBudgetSettings', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSchedulingStore()
		jest.clearAllMocks()
	})

	const mountComponent = () => {
		return mount(EnergyBudgetSettings)
	}

	it('renders with data-testid', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('[data-testid="energy-budget-settings"]').exists()).toBe(true)
	})

	it('renders energy sliders for each day', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('[data-testid="energy-slider-monday"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="energy-slider-sunday"]').exists()).toBe(true)
	})

	it('renders energy number inputs for each day', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('[data-testid="energy-input-monday"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="energy-input-sunday"]').exists()).toBe(true)
	})

	it('renders save button with testid', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('[data-testid="save-energy-budget-button"]').exists()).toBe(true)
	})

	it('renders template buttons', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('[data-testid="template-light"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="template-moderate"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="template-heavy"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="template-weekend-focused"]').exists()).toBe(true)
	})

	it('displays the total weekly energy derived from store data', async () => {
		// Non-default values so the assertion can't pass against defaultBudget() by coincidence.
		store.energyBudget = {
			monday: 1, tuesday: 1, wednesday: 1,
			thursday: 1, friday: 1, saturday: 1, sunday: 1,
		}
		const wrapper = mountComponent()
		// onMounted syncs localBudget from the store; await the re-render.
		await flushPromises()
		expect(wrapper.text()).toContain('7')
		expect(wrapper.text()).not.toContain('80')
	})

	it('keeps the weekly total numeric while a day input is cleared (BUG-UI-001)', async () => {
		store.energyBudget = fullBudget()

		const wrapper = mountComponent()
		await wrapper.find('[data-testid="energy-input-monday"]').setValue('')
		await flushPromises()

		expect(wrapper.find('.total-energy').text()).toBe('60 total energy/week')
		expect(wrapper.text()).not.toContain('NaN')
	})

	it('keeps the weekly total numeric when a day value is non-finite (BUG-UI-001)', async () => {
		store.energyBudget = fullBudget()

		const wrapper = mountComponent()
		// The range-slider path emits valueAsNumber, which is NaN mid-edit.
		wrapper.findComponent(WeekBudgetGrid).vm.$emit('updateDay', { key: 'monday', value: NaN })
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.total-energy').text()).toBe('60 total energy/week')
	})

	it('saves the whole edited week in one bulk request', async () => {
		// Baseline: every day at 10 in the store.
		store.energyBudget = fullBudget()
		api.put.mockResolvedValue({ energyBudget: fullBudget({ monday: 5, tuesday: 7 }) })

		const wrapper = mountComponent()

		// Edit two days; the save must carry the full week (not per-day PUTs),
		// with the untouched days at their current values.
		await wrapper.find('[data-testid="energy-input-monday"]').setValue(5)
		await wrapper.find('[data-testid="energy-input-tuesday"]').setValue(7)
		await wrapper.find('[data-testid="save-energy-budget-button"]').trigger('click')
		await flushPromises()

		expect(api.put).toHaveBeenCalledTimes(1)
		expect(api.put).toHaveBeenCalledWith('/api/v1/scheduling/preferences', {
			energyBudget: fullBudget({ monday: 5, tuesday: 7 }),
		})
		// Store state is replaced from the response.
		expect(store.energyBudget).toEqual(fullBudget({ monday: 5, tuesday: 7 }))
	})

	it('disables the day inputs and templates while a save is in flight', async () => {
		store.energyBudget = fullBudget()
		let resolvePut
		api.put.mockReturnValue(new Promise((resolve) => { resolvePut = resolve }))

		const wrapper = mountComponent()
		await wrapper.find('[data-testid="energy-input-monday"]').setValue(5)
		await wrapper.find('[data-testid="save-energy-budget-button"]').trigger('click')
		await flushPromises()

		// In-flight: no further edits possible, so the response sync can
		// never clobber an edit made between click and response.
		expect(wrapper.find('[data-testid="energy-slider-monday"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="energy-input-tuesday"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="template-light"]').attributes('disabled')).toBeDefined()

		resolvePut({ energyBudget: fullBudget({ monday: 5 }) })
		await flushPromises()
		expect(wrapper.find('[data-testid="energy-input-monday"]').attributes('disabled')).toBeUndefined()
	})
})
