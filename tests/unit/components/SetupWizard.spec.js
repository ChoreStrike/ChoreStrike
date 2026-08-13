import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import SetupWizard from '../../../src/components/Setup/SetupWizard.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

let mockSetupStore
let mockUserStore

jest.mock('../../../src/store/setup.js', () => ({
	useSetupStore: () => mockSetupStore,
}))
jest.mock('../../../src/store/user.js', () => ({
	useUserStore: () => mockUserStore,
}))

describe('SetupWizard.vue', () => {
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		mockSetupStore = {
			loading: false,
			applying: false,
			levels: [
				{ id: 'putzteufel', label: 'Putzteufel', description: 'a' },
				{ id: 'putzmuffel', label: 'Putzmuffel', description: 'b' },
			],
			roomTypes: [
				{ id: 'kitchen', defaultName: 'Kitchen', icon: 'Utensils', templateIds: [] },
				{ id: 'bathroom', defaultName: 'Bathroom', icon: 'Bath', templateIds: [] },
			],
			templates: [],
			fetchCatalog: jest.fn().mockResolvedValue({}),
			applySetup: jest.fn().mockResolvedValue({ floors: 1, rooms: 1, tasks: 1, roomTasks: 1, skipped: [] }),
		}
		mockUserStore = {
			houseUsers: [{ nextcloudUserId: 'u1', displayName: 'Alice' }],
			fetchHouseUsers: jest.fn().mockResolvedValue([]),
		}
	})

	const mountWizard = () => mount(SetupWizard, {
		global: { plugins: [pinia] },
	})

	it('renders step 1 (quick start) by default', async () => {
		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-testid="wizard-step-quickstart"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="wizard-step-rooms"]').exists()).toBe(false)
	})

	it('navigates to step 2 and generates a room list', async () => {
		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		// Configure: 1 floor, 2 rooms
		await wrapper.find('[data-testid="wizard-floor-count"]').setValue(1)
		await wrapper.find('[data-testid="wizard-rooms-per-floor"]').setValue(2)

		await wrapper.find('[data-testid="wizard-next"]').trigger('click')

		expect(wrapper.find('[data-testid="wizard-step-rooms"]').exists()).toBe(true)
		// 2 rooms generated -> 2 name inputs
		expect(wrapper.find('[data-testid="wizard-room-name-0-0"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="wizard-room-name-0-1"]').exists()).toBe(true)
	})

	it('lets the user pick a cleaning level', async () => {
		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="wizard-level-putzmuffel"]').trigger('click')
		expect(wrapper.vm.quick.intensityLevel).toBe('putzmuffel')
	})

	it('builds the apply payload from the generated rooms and emits applied', async () => {
		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="wizard-floor-count"]').setValue(1)
		await wrapper.find('[data-testid="wizard-rooms-per-floor"]').setValue(1)
		await wrapper.find('[data-testid="wizard-next"]').trigger('click') // -> step 2
		await wrapper.find('[data-testid="wizard-next"]').trigger('click') // -> step 3

		await wrapper.find('[data-testid="wizard-apply"]').trigger('click')
		await wrapper.vm.$nextTick()

		expect(mockSetupStore.applySetup).toHaveBeenCalledTimes(1)
		const payload = mockSetupStore.applySetup.mock.calls[0][0]
		expect(payload.intensityLevel).toBe('putzteufel')
		expect(payload.floors).toHaveLength(1)
		expect(payload.floors[0].rooms[0]).toHaveProperty('roomType')
		expect(payload.floors[0].rooms[0]).toHaveProperty('icon')
		expect(wrapper.emitted('applied')).toBeTruthy()
	})

	it('emits skipped when starting empty', async () => {
		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="wizard-skip"]').trigger('click')
		expect(wrapper.emitted('skipped')).toBeTruthy()
	})

	it('surfaces the store error so a failed apply is visible', async () => {
		mockSetupStore.error = 'The house is already set up.'
		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()

		const error = wrapper.find('[data-testid="wizard-error"]')
		expect(error.exists()).toBe(true)
		expect(error.text()).toBe('The house is already set up.')
	})
})
