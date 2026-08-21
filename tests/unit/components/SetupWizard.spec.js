import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import SetupWizard from '../../../src/components/Setup/SetupWizard.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, vars = {}) =>
		text.replace(/\{(\w+)\}/g, (m, key) => (key in vars ? String(vars[key]) : m))
	),
}))

jest.mock('@nextcloud/dialogs', () => ({
	showSuccess: jest.fn(),
	showError: jest.fn(),
	showWarning: jest.fn(),
}))

jest.mock('../../../src/utils/logger.js', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
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
			inviteUser: jest.fn().mockResolvedValue({ exists: true, user: { displayName: 'Bob' } }),
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

	it('builds the apply payload from the generated rooms, then moves to the invite step', async () => {
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
		// Applied is only emitted after the optional invite step finishes
		expect(wrapper.emitted('applied')).toBeFalsy()
		expect(wrapper.find('[data-testid="wizard-step-invite"]').exists()).toBe(true)
	})

	it('step 4: skipping invites finishes the wizard and emits applied', async () => {
		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="wizard-floor-count"]').setValue(1)
		await wrapper.find('[data-testid="wizard-rooms-per-floor"]').setValue(1)
		await wrapper.find('[data-testid="wizard-next"]').trigger('click')
		await wrapper.find('[data-testid="wizard-next"]').trigger('click')
		await wrapper.find('[data-testid="wizard-apply"]').trigger('click')
		await wrapper.vm.$nextTick()

		await wrapper.find('[data-testid="wizard-finish"]').trigger('click')

		expect(mockUserStore.inviteUser).not.toHaveBeenCalled()
		expect(wrapper.emitted('applied')).toBeTruthy()
	})

	it('step 4: sends one invite per valid email and finishes', async () => {
		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="wizard-floor-count"]').setValue(1)
		await wrapper.find('[data-testid="wizard-rooms-per-floor"]').setValue(1)
		await wrapper.find('[data-testid="wizard-next"]').trigger('click')
		await wrapper.find('[data-testid="wizard-next"]').trigger('click')
		await wrapper.find('[data-testid="wizard-apply"]').trigger('click')
		await wrapper.vm.$nextTick()

		await wrapper.find('[data-testid="wizard-invite-email-0"]').setValue('mom@example.org')
		await wrapper.find('[data-testid="wizard-invite-add"]').trigger('click')
		await wrapper.find('[data-testid="wizard-invite-email-1"]').setValue('kid@example.org')

		await wrapper.find('[data-testid="wizard-send-invites"]').trigger('click')
		await wrapper.vm.$nextTick()

		expect(mockUserStore.inviteUser).toHaveBeenCalledWith('mom@example.org', 'member')
		expect(mockUserStore.inviteUser).toHaveBeenCalledWith('kid@example.org', 'member')
		expect(wrapper.emitted('applied')).toBeTruthy()
	})

	it('step 4: failed invites keep the step open with feedback', async () => {
		mockUserStore.inviteUser.mockRejectedValue(new Error('No Nextcloud user found'))
		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="wizard-floor-count"]').setValue(1)
		await wrapper.find('[data-testid="wizard-rooms-per-floor"]').setValue(1)
		await wrapper.find('[data-testid="wizard-next"]').trigger('click')
		await wrapper.find('[data-testid="wizard-next"]').trigger('click')
		await wrapper.find('[data-testid="wizard-apply"]').trigger('click')
		await wrapper.vm.$nextTick()

		await wrapper.find('[data-testid="wizard-invite-email-0"]').setValue('ghost@example.org')
		await wrapper.find('[data-testid="wizard-send-invites"]').trigger('click')
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="wizard-invite-feedback"]').text()).toContain('failed')
		expect(wrapper.emitted('applied')).toBeFalsy()
	})

	it('emits skipped when starting empty', async () => {
		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="wizard-skip"]').trigger('click')
		expect(wrapper.emitted('skipped')).toBeTruthy()
	})

	it('apartment pins one floor and hides the basement option; house restores defaults (CR-027-03)', async () => {
		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-testid="wizard-basement"]').exists()).toBe(true)

		await wrapper.find('[data-testid="wizard-home-type"]').setValue('apartment')

		expect(wrapper.vm.quick.floorCount).toBe(1)
		expect(wrapper.vm.quick.basement).toBe(false)
		expect(wrapper.find('[data-testid="wizard-basement"]').exists()).toBe(false)

		await wrapper.find('[data-testid="wizard-home-type"]').setValue('house')

		expect(wrapper.vm.quick.floorCount).toBe(2)
		expect(wrapper.find('[data-testid="wizard-basement"]').exists()).toBe(true)
	})

	it('preview shows per-room and total task counts from the catalog templates (CR-027-04)', async () => {
		// kitchen: 2 templates (one level-exclusive on putzmuffel), bathroom /
		// hallway / household: 1 each — counts must mirror applySetup's
		// materialization incl. the auto rooms (D7).
		mockSetupStore.templates = [
			{ id: 'k1', roomTypes: ['kitchen'], levels: { putzteufel: {}, putzmuffel: {} } },
			{ id: 'k2', roomTypes: ['kitchen'], levels: { putzteufel: {}, putzmuffel: null } },
			{ id: 'b1', roomTypes: ['bathroom'], levels: { putzteufel: {}, putzmuffel: {} } },
			{ id: 'h1', roomTypes: ['hallway'], levels: { putzteufel: {}, putzmuffel: {} } },
			{ id: 'hh1', roomTypes: ['household'], levels: { putzteufel: {}, putzmuffel: {} } },
		]

		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="wizard-floor-count"]').setValue(1)
		await wrapper.find('[data-testid="wizard-rooms-per-floor"]').setValue(2)
		await wrapper.find('[data-testid="wizard-next"]').trigger('click') // -> step 2
		await wrapper.find('[data-testid="wizard-next"]').trigger('click') // -> step 3

		// Rooms are generated round-robin: kitchen, bathroom.
		expect(wrapper.find('[data-testid="wizard-room-task-count-0-0"]').text()).toBe('≈ 2 tasks')
		expect(wrapper.find('[data-testid="wizard-room-task-count-0-1"]').text()).toBe('≈ 1 tasks')
		// Auto rooms: hallway per floor + household room (both options on).
		expect(wrapper.find('[data-testid="wizard-room-task-count-0-hallway"]').text()).toBe('≈ 1 tasks')
		expect(wrapper.find('[data-testid="wizard-room-task-count-household"]').text()).toBe('≈ 1 tasks')
		// 2 + 1 + 1 (hallway) + 1 (household) = 5 on putzteufel.
		expect(wrapper.find('[data-testid="wizard-preview-task-count"]').text()).toBe('≈ 5 tasks will be created')

		// Level-exclusive template drops out on the relaxed level (D7 a).
		await wrapper.find('[data-testid="wizard-back"]').trigger('click')
		await wrapper.find('[data-testid="wizard-back"]').trigger('click')
		await wrapper.find('[data-testid="wizard-level-putzmuffel"]').trigger('click')
		await wrapper.find('[data-testid="wizard-next"]').trigger('click')
		await wrapper.find('[data-testid="wizard-next"]').trigger('click')

		expect(wrapper.find('[data-testid="wizard-room-task-count-0-0"]').text()).toBe('≈ 1 tasks')
		expect(wrapper.find('[data-testid="wizard-preview-task-count"]').text()).toBe('≈ 4 tasks will be created')
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
