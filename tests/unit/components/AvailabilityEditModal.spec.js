import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import AvailabilityEditModal from '../../../src/components/Scheduling/AvailabilityEditModal.vue'
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

jest.mock('../../../src/utils/currentUser.js', () => ({
	resolveCurrentUser: jest.fn(() => ({ uid: 'alice', displayName: 'Alice', userId: 'alice' })),
}))

const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('AvailabilityEditModal', () => {
	let store

	const selfMember = {
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
	}

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSchedulingStore()
		jest.clearAllMocks()
	})

	it('emits close when Cancel is clicked', async () => {
		const wrapper = mount(AvailabilityEditModal, { props: { member: selfMember } })
		await wrapper.find('[data-testid="cancel-edit-button"]').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('names the dialog accessibly from its heading key (UX-016)', () => {
		const wrapper = mount(AvailabilityEditModal, { props: { member: selfMember } })
		expect(wrapper.find('[data-testid="modal"]').attributes('aria-label')).toBe('Edit Availability')
	})

	it('saves the current user via the bulk self preferences PUT', async () => {
		api.put.mockResolvedValue({})
		api.get.mockResolvedValue([])

		const wrapper = mount(AvailabilityEditModal, { props: { member: selfMember } })
		await wrapper.find('[data-testid="availability-monday"]').setValue(false)
		await wrapper.find('[data-testid="save-availability-button"]').trigger('click')
		await flushPromises()

		expect(api.put).toHaveBeenCalledTimes(1)
		expect(api.put).toHaveBeenCalledWith('/api/v1/scheduling/preferences', {
			weekdayAvailability: {
				monday: false, tuesday: true, wednesday: true, thursday: true,
				friday: true, saturday: true, sunday: true,
			},
			energyBudget: {
				monday: 11, tuesday: 12, wednesday: 13, thursday: 14,
				friday: 15, saturday: 16, sunday: 17,
			},
		})
	})

	it("saves another member via the owner-only member PUT (UX-014)", async () => {
		store.memberAvailability = [{ ...selfMember, userId: 'bob' }]
		api.put.mockResolvedValue({})
		api.get.mockResolvedValue([])

		const bob = { ...selfMember, userId: 'bob' }
		const wrapper = mount(AvailabilityEditModal, { props: { member: bob } })

		await wrapper.find('[data-testid="budget-monday-input"]').setValue(9)
		await wrapper.find('[data-testid="save-availability-button"]').trigger('click')
		await flushPromises()

		expect(api.put).toHaveBeenCalledTimes(1)
		expect(api.put).toHaveBeenCalledWith('/api/v1/scheduling/members/bob', {
			weekdayAvailability: {
				monday: true, tuesday: true, wednesday: true, thursday: true,
				friday: true, saturday: true, sunday: true,
			},
			energyBudget: {
				monday: 9, tuesday: 12, wednesday: 13, thursday: 14,
				friday: 15, saturday: 16, sunday: 17,
			},
		})
	})
})

// CS-PLAN-065 decision D5 (RF-065-02): the owner-only child daily-task
// limit UI — omitted leaves the stored value unchanged, disabling sends an
// explicit null, an enabled integer 1-100 is validated before the request.
describe('AvailabilityEditModal — daily task limit (D5)', () => {
	let store

	const otherMember = (overrides = {}) => ({
		userId: 'bob',
		userName: 'Bob',
		weekdayAvailability: {
			monday: true, tuesday: true, wednesday: true, thursday: true,
			friday: true, saturday: true, sunday: true,
		},
		energyBudget: {
			monday: 11, tuesday: 12, wednesday: 13, thursday: 14,
			friday: 15, saturday: 16, sunday: 17,
		},
		...overrides,
	})

	const mountFor = (member) => mount(AvailabilityEditModal, { props: { member } })

	const savedPutBody = () => {
		expect(api.put).toHaveBeenCalledTimes(1)
		const [url, body] = api.put.mock.calls[0]
		expect(url).toBe('/api/v1/scheduling/members/bob')
		return body
	}

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSchedulingStore()
		jest.clearAllMocks()
		api.put.mockResolvedValue({})
		api.get.mockResolvedValue([])
	})

	it('hides the limit section when editing your own availability', () => {
		const wrapper = mountFor(otherMember({ userId: 'alice' }))
		expect(wrapper.find('[data-testid="limit-enabled-toggle"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="limit-value-input"]').exists()).toBe(false)
		expect(wrapper.text()).not.toContain('Daily Task Limit')
	})

	it('shows the limit section with toggle and bounds for another member', () => {
		const wrapper = mountFor(otherMember({ maxDailyTasks: null }))
		expect(wrapper.text()).toContain('Daily Task Limit')
		const toggle = wrapper.find('[data-testid="limit-enabled-toggle"]')
		expect(toggle.exists()).toBe(true)
		expect(toggle.element.checked).toBe(false)
		// Disabled toggle → no value input (null = unlimited).
		expect(wrapper.find('[data-testid="limit-value-input"]').exists()).toBe(false)
	})

	it('sends an explicit integer when the limit is enabled and filled', async () => {
		const wrapper = mountFor(otherMember({ maxDailyTasks: null }))
		await wrapper.find('[data-testid="limit-enabled-toggle"]').setValue(true)
		await wrapper.find('[data-testid="limit-value-input"]').setValue(5)
		await wrapper.find('[data-testid="save-availability-button"]').trigger('click')
		await flushPromises()

		expect(savedPutBody().maxDailyTasks).toBe(5)
	})

	it('prefills the stored finite limit and sends null when disabled', async () => {
		const wrapper = mountFor(otherMember({ maxDailyTasks: 4 }))
		expect(wrapper.find('[data-testid="limit-enabled-toggle"]').element.checked).toBe(true)
		expect(wrapper.find('[data-testid="limit-value-input"]').element.value).toBe('4')

		await wrapper.find('[data-testid="limit-enabled-toggle"]').setValue(false)
		await wrapper.find('[data-testid="save-availability-button"]').trigger('click')
		await flushPromises()

		expect(savedPutBody().maxDailyTasks).toBeNull()
	})

	it('omits the limit key entirely when the limit is unchanged', async () => {
		const wrapper = mountFor(otherMember({ maxDailyTasks: 4 }))
		// Only an energy edit — the stored limit must ride along untouched.
		await wrapper.find('[data-testid="budget-monday-input"]').setValue(9)
		await wrapper.find('[data-testid="save-availability-button"]').trigger('click')
		await flushPromises()

		expect(savedPutBody()).not.toHaveProperty('maxDailyTasks')
	})

	it('enables Save on a limit-only change', async () => {
		const wrapper = mountFor(otherMember({ maxDailyTasks: 4 }))
		expect(wrapper.find('[data-testid="save-availability-button"]').attributes('disabled')).toBeDefined()

		await wrapper.find('[data-testid="limit-value-input"]').setValue(6)
		expect(wrapper.find('[data-testid="save-availability-button"]').attributes('disabled')).toBeUndefined()
	})

	it.each([0, 101])('rejects the out-of-range limit %i before any request', async (value) => {
		const wrapper = mountFor(otherMember({ maxDailyTasks: null }))
		await wrapper.find('[data-testid="limit-enabled-toggle"]').setValue(true)
		await wrapper.find('[data-testid="limit-value-input"]').setValue(value)
		await wrapper.find('[data-testid="save-availability-button"]').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('The daily task limit must be between 1 and 100')
		expect(api.put).not.toHaveBeenCalled()
	})

	it('accepts the boundary values 1 and 100', async () => {
		const wrapper = mountFor(otherMember({ maxDailyTasks: null }))
		await wrapper.find('[data-testid="limit-enabled-toggle"]').setValue(true)
		await wrapper.find('[data-testid="limit-value-input"]').setValue(1)
		await wrapper.find('[data-testid="save-availability-button"]').trigger('click')
		await flushPromises()
		expect(savedPutBody().maxDailyTasks).toBe(1)

		jest.clearAllMocks()
		api.put.mockResolvedValue({})
		api.get.mockResolvedValue([])
		const upper = mountFor(otherMember({ maxDailyTasks: 1 }))
		await upper.find('[data-testid="limit-value-input"]').setValue(100)
		await upper.find('[data-testid="save-availability-button"]').trigger('click')
		await flushPromises()
		expect(savedPutBody().maxDailyTasks).toBe(100)
	})
})
