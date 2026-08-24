import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import AvailabilityEditModal from '../../../src/components/Scheduling/AvailabilityEditModal.vue'
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
