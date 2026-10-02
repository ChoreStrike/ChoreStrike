import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import SetupWizard from '../../../src/components/Setup/SetupWizard.vue'
import { useHouseStore } from '../../../src/store/house.js'

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
			error: null,
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
			applySetup: jest.fn().mockResolvedValue({ floors: 1, rooms: 1, tasks: 1, roomTasks: 1, skipped: [], houseName: 'Family Home' }),
			clearError: jest.fn(() => {
				mockSetupStore.error = null
			}),
		}
		mockUserStore = {
			houseUsers: [{ nextcloudUserId: 'u1', displayName: 'Alice' }],
			fetchHouseUsers: jest.fn().mockResolvedValue([]),
			// CS-PLAN-062 D5: the backend payload IS the house-user object.
			inviteUser: jest.fn().mockResolvedValue({ id: 9, nextcloudUserId: 'bob', displayName: 'Bob', role: 'member' }),
		}
	})

	const mountWizard = () => mount(SetupWizard, {
		global: { plugins: [pinia] },
	})

	/** Drive the wizard from step 1 to the preview step (3). */
	const advanceToPreview = async (wrapper) => {
		await wrapper.find('[data-testid="wizard-floor-count"]').setValue(1)
		await wrapper.find('[data-testid="wizard-rooms-per-floor"]').setValue(1)
		await wrapper.find('[data-testid="wizard-next"]').trigger('click') // -> step 2
		await wrapper.find('[data-testid="wizard-next"]').trigger('click') // -> step 3
	}

	/** Drive the wizard through the apply to the invite step (4). */
	const advanceToInviteStep = async (wrapper) => {
		await advanceToPreview(wrapper)
		await wrapper.find('[data-testid="wizard-apply"]').trigger('click')
		await wrapper.vm.$nextTick()
	}

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

	it('sends the house name in the apply payload (CS-PLAN-062 §3.2)', async () => {
		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="wizard-house-name"]').setValue('Family Home')
		await advanceToPreview(wrapper)

		await wrapper.find('[data-testid="wizard-apply"]').trigger('click')
		await flushPromises()

		expect(mockSetupStore.applySetup).toHaveBeenCalledTimes(1)
		const payload = mockSetupStore.applySetup.mock.calls[0][0]
		expect(payload.houseName).toBe('Family Home')
		expect(payload.intensityLevel).toBe('putzteufel')
		expect(payload.floors).toHaveLength(1)
		expect(payload.floors[0].rooms[0]).toHaveProperty('roomType')
		expect(payload.floors[0].rooms[0]).toHaveProperty('icon')
		// Applied is only emitted after the optional invite step finishes
		expect(wrapper.emitted('applied')).toBeFalsy()
		expect(wrapper.find('[data-testid="wizard-step-invite"]').exists()).toBe(true)
	})

	it('performs no separate rename request during setup (CS-PLAN-062 §3.2)', async () => {
		const houseStore = useHouseStore()
		const updateHouseName = jest.fn().mockResolvedValue(undefined)
		houseStore.updateHouseName = updateHouseName

		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="wizard-house-name"]').setValue('Family Home')
		await advanceToInviteStep(wrapper)
		await flushPromises()

		expect(updateHouseName).not.toHaveBeenCalled()
	})

	it('step 4 renders the apply result panel (counts, house name, skipped)', async () => {
		mockSetupStore.templates = [
			{ id: 'k2', name: 'Wipe kitchen counters', roomTypes: ['kitchen'], levels: { putzteufel: {}, putzmuffel: null } },
		]
		mockSetupStore.applySetup.mockResolvedValue({
			floors: 2, rooms: 5, tasks: 8, roomTasks: 8,
			skipped: ['k2'],
			houseName: 'Family Home',
		})

		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		await advanceToInviteStep(wrapper)
		await flushPromises()

		expect(wrapper.find('[data-testid="wizard-apply-result"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="wizard-result-house-name"]').text()).toBe('House name: Family Home')
		expect(wrapper.find('[data-testid="wizard-result-counts"]').text())
			.toBe('2 floors, 5 rooms and 8 tasks were created')
		// Level-exclusive skips are reported with their catalog labels.
		expect(wrapper.find('[data-testid="wizard-result-skipped"]').text())
			.toBe('Skipped at this level: Wipe kitchen counters')
	})

	it('step 4: skipping invites finishes the wizard and emits applied', async () => {
		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		await advanceToInviteStep(wrapper)

		await wrapper.find('[data-testid="wizard-finish"]').trigger('click')

		expect(mockUserStore.inviteUser).not.toHaveBeenCalled()
		expect(wrapper.emitted('applied')).toBeTruthy()
	})

	it('step 4: sends one invite per valid email and shows per-recipient success', async () => {
		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		await advanceToInviteStep(wrapper)

		await wrapper.find('[data-testid="wizard-invite-email-0"]').setValue('mom@example.org')
		await wrapper.find('[data-testid="wizard-invite-add"]').trigger('click')
		await wrapper.find('[data-testid="wizard-invite-email-1"]').setValue('kid@example.org')

		await wrapper.find('[data-testid="wizard-send-invites"]').trigger('click')
		await flushPromises()

		expect(mockUserStore.inviteUser).toHaveBeenCalledWith('mom@example.org', 'member')
		expect(mockUserStore.inviteUser).toHaveBeenCalledWith('kid@example.org', 'member')
		// The step stays open so the statuses stay visible; the user finishes
		// explicitly once every row shows the joined outcome.
		expect(wrapper.emitted('applied')).toBeFalsy()
		expect(wrapper.find('[data-testid="wizard-invite-status-0"]').classes()).toContain('joined')
		expect(wrapper.find('[data-testid="wizard-invite-status-1"]').classes()).toContain('joined')
		expect(wrapper.find('[data-testid="wizard-finish"]').text()).toBe('Finish')

		await wrapper.find('[data-testid="wizard-finish"]').trigger('click')
		expect(wrapper.emitted('applied')).toBeTruthy()
	})

	it('step 4: per-recipient status names the joined user (CS-PLAN-062 §3.4)', async () => {
		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		await advanceToInviteStep(wrapper)

		await wrapper.find('[data-testid="wizard-invite-email-0"]').setValue('bob@example.org')
		await wrapper.find('[data-testid="wizard-send-invites"]').trigger('click')
		await flushPromises()

		const status = wrapper.find('[data-testid="wizard-invite-status-0"]')
		expect(status.exists()).toBe(true)
		expect(status.text()).toBe('Bob joined the house')
		expect(status.classes()).toContain('joined')
	})

	it('step 4: failed invites keep the step open with the reason on the row', async () => {
		const apiError = new Error('No Nextcloud user found with this email address')
		apiError.status = 404
		apiError.code = 'USER_NOT_FOUND'
		mockUserStore.inviteUser.mockRejectedValue(apiError)

		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		await advanceToInviteStep(wrapper)

		await wrapper.find('[data-testid="wizard-invite-email-0"]').setValue('ghost@example.org')
		await wrapper.find('[data-testid="wizard-send-invites"]').trigger('click')
		await flushPromises()

		const status = wrapper.find('[data-testid="wizard-invite-status-0"]')
		expect(status.exists()).toBe(true)
		expect(status.text()).toContain('No Nextcloud user found')
		expect(status.classes()).toContain('failed')
		// The failing row stays editable for a retry without re-typing.
		expect(wrapper.find('[data-testid="wizard-invite-email-0"]').element.value).toBe('ghost@example.org')
		expect(wrapper.find('[data-testid="wizard-invite-email-0"]').attributes('disabled')).toBeUndefined()
		// An invite failure never finishes the wizard...
		expect(wrapper.emitted('applied')).toBeFalsy()
		// ...and never reads as a structural failure (result panel still there).
		expect(wrapper.find('[data-testid="wizard-apply-result"]').exists()).toBe(true)
	})

	it('step 4: retry after a failure re-invites only the pending rows', async () => {
		const apiError = new Error('No Nextcloud user found with this email address')
		mockUserStore.inviteUser
			.mockRejectedValueOnce(apiError)
			.mockResolvedValueOnce({ id: 10, displayName: 'Kid', role: 'member' })

		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		await advanceToInviteStep(wrapper)

		await wrapper.find('[data-testid="wizard-invite-email-0"]').setValue('ghost@example.org')
		await wrapper.find('[data-testid="wizard-invite-add"]').trigger('click')
		await wrapper.find('[data-testid="wizard-invite-email-1"]').setValue('kid@example.org')
		await wrapper.find('[data-testid="wizard-send-invites"]').trigger('click')
		await flushPromises()

		// First row failed, second joined.
		expect(wrapper.find('[data-testid="wizard-invite-status-0"]').classes()).toContain('failed')
		expect(wrapper.find('[data-testid="wizard-invite-status-1"]').classes()).toContain('joined')
		expect(wrapper.emitted('applied')).toBeFalsy()

		// Fix the first email and retry: only the failed row is re-invited.
		mockUserStore.inviteUser.mockResolvedValueOnce({ id: 11, displayName: 'Ghost', role: 'member' })
		await wrapper.find('[data-testid="wizard-invite-email-0"]').setValue('ghost2@example.org')
		await wrapper.find('[data-testid="wizard-send-invites"]').trigger('click')
		await flushPromises()

		expect(mockUserStore.inviteUser).toHaveBeenCalledTimes(3)
		expect(mockUserStore.inviteUser).toHaveBeenLastCalledWith('ghost2@example.org', 'member')
		expect(wrapper.find('[data-testid="wizard-invite-status-0"]').text()).toBe('Ghost joined the house')

		// Every row joined — the explicit finish closes the wizard.
		expect(wrapper.emitted('applied')).toBeFalsy()
		await wrapper.find('[data-testid="wizard-finish"]').trigger('click')
		expect(wrapper.emitted('applied')).toBeTruthy()
	})

	it('a raced 409 renders the recoverable already-set-up state (CS-PLAN-062 §3.5)', async () => {
		const apiError = new Error('The house is already set up. Use the per-room template picker instead.')
		apiError.status = 409
		apiError.code = 'HOUSE_ALREADY_SET_UP'
		// The real store records the message before rethrowing — the
		// recovery state must clear it (Standard B review F2).
		mockSetupStore.applySetup.mockImplementation(async () => {
			mockSetupStore.error = apiError.message
			throw apiError
		})

		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		await advanceToPreview(wrapper)

		await wrapper.find('[data-testid="wizard-apply"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="wizard-already-set-up"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="wizard-step-preview"]').text()).toContain('This house has already been set up.')
		// The apply flow and the store error line stay out of the way — the
		// recovery panel renders alone instead of duplicating the message.
		expect(wrapper.find('[data-testid="wizard-apply"]').exists()).toBe(false)
		expect(mockSetupStore.clearError).toHaveBeenCalled()
		expect(wrapper.find('[data-testid="wizard-error"]').exists()).toBe(false)
		// "Continue to the app" finishes the wizard (Dashboard reloads).
		await wrapper.find('[data-testid="wizard-continue"]').trigger('click')
		expect(wrapper.emitted('applied')).toBeTruthy()
	})

	it('a validation 400 keeps the user on the preview step with input preserved', async () => {
		const apiError = new Error('A floor name is required')
		apiError.status = 400
		apiError.code = 'VALIDATION_ERROR'
		mockSetupStore.applySetup.mockRejectedValue(apiError)
		mockSetupStore.error = 'A floor name is required'

		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="wizard-house-name"]').setValue('Keep Me')
		await advanceToPreview(wrapper)

		await wrapper.find('[data-testid="wizard-apply"]').trigger('click')
		await flushPromises()

		// Still on the preview step, error visible, no recovery takeover.
		expect(wrapper.find('[data-testid="wizard-step-preview"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="wizard-already-set-up"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="wizard-error"]').text()).toBe('A floor name is required')
		expect(wrapper.find('[data-testid="wizard-apply"]').exists()).toBe(true)
		// Input survives: going back to step 1 still shows the entered house name.
		await wrapper.find('[data-testid="wizard-back"]').trigger('click')
		await wrapper.find('[data-testid="wizard-back"]').trigger('click')
		expect(wrapper.find('[data-testid="wizard-house-name"]').element.value).toBe('Keep Me')
	})

	it('emits skipped when starting empty', async () => {
		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="wizard-skip"]').trigger('click')
		expect(wrapper.emitted('skipped')).toBeTruthy()
	})

	// --- CS-PLAN-042 (UX-011): house-name step, persistent dismissal, single-member guard ---

	it('persists a per-house dismissal flag when starting empty', async () => {
		const houseStore = useHouseStore()
		houseStore.house = { id: 42, name: 'Test House' }

		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="wizard-skip"]').trigger('click')

		expect(window.localStorage.getItem('chorestrike:wizard-dismissed:42')).toBe('1')
		expect(wrapper.emitted('skipped')).toBeTruthy()
	})

	it('hides the per-room assignee dropdown while the house has a single member', async () => {
		mockUserStore.houseUsers = [{ nextcloudUserId: 'u1', displayName: 'Alice' }]

		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="wizard-floor-count"]').setValue(1)
		await wrapper.find('[data-testid="wizard-rooms-per-floor"]').setValue(2)
		await wrapper.find('[data-testid="wizard-next"]').trigger('click')

		expect(wrapper.find('[data-testid^="wizard-room-person-"]').exists()).toBe(false)
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

	// --- CS-PLAN-062 §3.5: accessibility (modal dialog, targets, labels) ---

	it('renders a labeled modal dialog with aria-current steps', async () => {
		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()

		const overlay = wrapper.find('[data-testid="setup-wizard"]')
		expect(overlay.attributes('role')).toBe('dialog')
		expect(overlay.attributes('aria-modal')).toBe('true')
		expect(overlay.attributes('aria-label')).toBe('Welcome to ChoreStrike!')

		const steps = wrapper.findAll('.wizard-steps li')
		expect(steps.length).toBe(4)
		expect(steps[0].attributes('aria-current')).toBe('step')
		expect(steps[1].attributes('aria-current')).toBeUndefined()
	})

	it('closes on Escape but no longer on a backdrop click (D8)', async () => {
		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()

		await wrapper.find('[data-testid="setup-wizard"]').trigger('keydown', { key: 'Escape' })
		expect(wrapper.emitted('close')).toBeTruthy()

		// A stray click on the overlay must not discard the input anymore.
		await wrapper.find('[data-testid="setup-wizard"]').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('traps Tab focus inside the dialog', async () => {
		// Focus management needs the tree attached to the document (jsdom).
		const wrapper = mount(SetupWizard, { attachTo: document.body, global: { plugins: [pinia] } })
		await wrapper.vm.$nextTick()

		const container = wrapper.find('.wizard-container').element
		const selector = 'button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'
		const focusables = container.querySelectorAll(selector)
		expect(focusables.length).toBeGreaterThan(0)

		// Tab from the last focusable wraps around to the first.
		focusables[focusables.length - 1].focus()
		await wrapper.find('[data-testid="setup-wizard"]').trigger('keydown', { key: 'Tab' })
		expect(document.activeElement).toBe(focusables[0])

		// Shift+Tab from the first wraps around to the last.
		focusables[0].focus()
		await wrapper.find('[data-testid="setup-wizard"]').trigger('keydown', { key: 'Tab', shiftKey: true })
		expect(document.activeElement).toBe(focusables[focusables.length - 1])

		wrapper.unmount()
	})

	it('gives icon-only buttons an accessible name', async () => {
		const wrapper = mountWizard()
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="wizard-floor-count"]').setValue(1)
		await wrapper.find('[data-testid="wizard-rooms-per-floor"]').setValue(1)
		await wrapper.find('[data-testid="wizard-next"]').trigger('click')

		const removeButton = wrapper.find('[data-testid="wizard-room-remove-0-0"]')
		expect(removeButton.attributes('aria-label')).toBe('Remove room')
		// The 44 px target class is applied.
		expect(removeButton.classes()).toContain('room-remove')

		await wrapper.find('[data-testid="wizard-next"]').trigger('click')
		await wrapper.find('[data-testid="wizard-apply"]').trigger('click')
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-testid="wizard-invite-remove-0"]').exists()).toBe(false) // single row hides it
		expect(wrapper.find('[data-testid="wizard-invite-email-0"]').attributes('aria-label')).toBe('Email address')
	})

	// CS-PLAN-070 WP8 §3.6: the essential account/role help sits on the
	// invite step (existing apply/invite/skip behavior is unchanged).
	it('offers the contextual accounts-and-roles help on the invite step', async () => {
		const wrapper = mountWizard()
		await advanceToInviteStep(wrapper)
		expect(wrapper.find('[data-testid="help-context-setup"]').exists()).toBe(true)
	})
})
