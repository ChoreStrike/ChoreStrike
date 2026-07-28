import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import EnergyBudgetSettings from '../../../src/components/Scheduling/EnergyBudgetSettings.vue'
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

	it('persists the full edited set (snapshot) without the store watcher reverting unsaved days', async () => {
		// Baseline: every day at 10 in the store.
		store.energyBudget = {
			monday: 10, tuesday: 10, wednesday: 10, thursday: 10,
			friday: 10, saturday: 10, sunday: 10,
		}
		api.put.mockResolvedValue(undefined)

		const wrapper = mountComponent()

		// Edit two days. Without the pre-loop snapshot, persisting monday would
		// mutate the store, the deep watcher would reset localBudget, and tuesday
		// would be saved as 10 instead of the user's 7.
		await wrapper.find('[data-testid="energy-input-monday"]').setValue(5)
		await wrapper.find('[data-testid="energy-input-tuesday"]').setValue(7)
		await wrapper.find('[data-testid="save-energy-budget-button"]').trigger('click')
		await flushPromises()

		const calls = Object.fromEntries(
			api.put.mock.calls.map(([, body]) => [body.day, body.energy]),
		)
		expect(calls.monday).toBe(5)
		expect(calls.tuesday).toBe(7)
	})
})
