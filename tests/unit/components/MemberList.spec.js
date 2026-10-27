import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import MemberList from '@/components/Members/MemberList.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, vars = {}) =>
		text.replace(/\{(\w+)\}/g, (m, key) => (key in vars ? String(vars[key]) : m))
	),
}))

jest.mock('@nextcloud/dialogs', () => ({
	showSuccess: jest.fn(),
	showError: jest.fn(),
}))

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

let mockUserStore

jest.mock('@/store/user.js', () => ({
	useUserStore: () => mockUserStore,
}))

jest.mock('@/components/Common/Icon.vue', () => ({
	name: 'Icon',
	props: ['name', 'size'],
	template: '<span class="icon-stub">icon</span>',
}))

jest.mock('@/components/Common/Modal.vue', () => ({
	name: 'Modal',
	props: ['show'],
	emits: ['close'],
	template: '<div v-if="show !== false" class="modal-stub" data-testid="modal"><slot /></div>',
}))

import { showSuccess, showError } from '@nextcloud/dialogs'

const membersFixture = () => ([
	{ id: 1, nextcloudUserId: 'mom', displayName: 'Mom', role: 'owner' },
	{ id: 2, nextcloudUserId: 'teen', displayName: 'Teen', role: 'child' },
	{ id: 3, nextcloudUserId: 'friend', displayName: 'Friend', role: 'member' },
])

const flushAsync = () => new Promise(resolve => setTimeout(resolve, 0))

const mountList = async () => {
	const wrapper = mount(MemberList, {
		global: { plugins: [createPinia()] },
	})
	await flushAsync()
	return wrapper
}

describe('MemberList', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
		mockUserStore = {
			currentUser: { id: 1, nextcloudUserId: 'mom', role: 'owner' },
			houseUsers: membersFixture(),
			initializeCurrentUser: jest.fn().mockResolvedValue({}),
			fetchHouseUsers: jest.fn().mockResolvedValue([]),
			inviteUser: jest.fn().mockResolvedValue({ id: 9, nextcloudUserId: 'bob', displayName: 'Bob', role: 'member' }),
			updateUserRole: jest.fn().mockResolvedValue({ id: 2, role: 'member' }),
			removeUser: jest.fn().mockResolvedValue(),
		}
	})

	it('renders one row per house member with role badges', async () => {
		const wrapper = await mountList()

		expect(wrapper.find('[data-testid="member-row-mom"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="member-row-teen"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="member-row-friend"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="member-role-badge-teen"]').text()).toBe('Child')
	})

	it('disables role change and removal for the own row (self-guard)', async () => {
		const wrapper = await mountList()

		expect(wrapper.find('[data-testid="member-role-select-mom"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="member-remove-mom"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="member-role-select-teen"]').attributes('disabled')).toBeUndefined()
	})

	it('requires a valid email before the invite can be submitted', async () => {
		const wrapper = await mountList()

		await wrapper.find('[data-testid="show-invite-button"]').trigger('click')
		expect(wrapper.find('[data-testid="invite-form"]').exists()).toBe(true)

		await wrapper.find('[data-testid="invite-email-input"]').setValue('not-an-email')
		expect(wrapper.find('[data-testid="invite-submit-button"]').attributes('disabled')).toBeDefined()

		await wrapper.find('[data-testid="invite-email-input"]').setValue('bob@example.org')
		expect(wrapper.find('[data-testid="invite-submit-button"]').attributes('disabled')).toBeUndefined()
	})

	it('invites by email, refetches members and toasts the joined member', async () => {
		const wrapper = await mountList()
		await wrapper.find('[data-testid="show-invite-button"]').trigger('click')
		await wrapper.find('[data-testid="invite-email-input"]').setValue('bob@example.org')
		await wrapper.find('[data-testid="invite-role-select"]').setValue('child')
		await wrapper.find('[data-testid="invite-form"]').trigger('submit')
		await flushAsync()

		expect(mockUserStore.inviteUser).toHaveBeenCalledWith('bob@example.org', 'child')
		expect(mockUserStore.fetchHouseUsers).toHaveBeenCalledTimes(2)
		expect(showSuccess).toHaveBeenCalledWith('Bob joined the house')
	})

	it('surfaces an invite error toast', async () => {
		mockUserStore.inviteUser.mockRejectedValue(new Error('No Nextcloud user found with this email address'))
		const wrapper = await mountList()
		await wrapper.find('[data-testid="show-invite-button"]').trigger('click')
		await wrapper.find('[data-testid="invite-email-input"]').setValue('ghost@example.org')
		await wrapper.find('[data-testid="invite-form"]').trigger('submit')
		await flushAsync()

		expect(showError).toHaveBeenCalled()
	})

	it('changes a role and toasts the update', async () => {
		const wrapper = await mountList()
		const select = wrapper.find('[data-testid="member-role-select-friend"]')
		await select.setValue('child')
		await flushAsync()

		expect(mockUserStore.updateUserRole).toHaveBeenCalledWith(3, 'child')
		expect(showSuccess).toHaveBeenCalled()
	})

	it('shows the server message and resets the select when a role change is rejected', async () => {
		// BUG-SEC-002: the backend rejects e.g. last-owner demotions with a
		// 409 CANNOT_DEMOTE_LAST_OWNER envelope; the shared API client
		// surfaces the envelope message as the error message.
		mockUserStore.updateUserRole.mockRejectedValue(new Error('Cannot demote the last house owner'))
		const wrapper = await mountList()
		const select = wrapper.find('[data-testid="member-role-select-friend"]')
		await select.setValue('owner')
		await flushAsync()

		expect(mockUserStore.updateUserRole).toHaveBeenCalledWith(3, 'owner')
		expect(showError).toHaveBeenCalledWith('Cannot demote the last house owner')
		expect(showSuccess).not.toHaveBeenCalled()
		// The select snaps back to the member's actual (persisted) role.
		expect(wrapper.find('[data-testid="member-role-select-friend"]').element.value).toBe('member')
	})

	it('requires confirm-dialog consent before removing a member', async () => {
		const wrapper = await mountList()

		await wrapper.find('[data-testid="member-remove-friend"]').trigger('click')
		expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(true)
		expect(mockUserStore.removeUser).not.toHaveBeenCalled()

		await wrapper.find('[data-testid="confirm-dialog-confirm"]').trigger('click')
		await flushAsync()

		expect(mockUserStore.removeUser).toHaveBeenCalledWith(3)
		expect(showSuccess).toHaveBeenCalled()
	})

	// FEAT-UX-015 (CS-PLAN-088 D-088-19): another owner is removable; only
	// the own row stays locked.
	it('removes another owner after confirmation', async () => {
		mockUserStore.houseUsers.push({ id: 4, nextcloudUserId: 'admin', displayName: 'Admin', role: 'owner' })
		const wrapper = await mountList()

		expect(wrapper.find('[data-testid="member-remove-admin"]').attributes('disabled')).toBeUndefined()
		await wrapper.find('[data-testid="member-remove-admin"]').trigger('click')
		await wrapper.find('[data-testid="confirm-dialog-confirm"]').trigger('click')
		await flushAsync()

		expect(mockUserStore.removeUser).toHaveBeenCalledWith(4)
		expect(showSuccess).toHaveBeenCalled()
	})

	it('names self-removal when the server refuses an owner target', async () => {
		mockUserStore.removeUser.mockRejectedValue(Object.assign(new Error('refused'), { code: 'CANNOT_REMOVE_OWNER' }))
		const wrapper = await mountList()

		await wrapper.find('[data-testid="member-remove-friend"]').trigger('click')
		await wrapper.find('[data-testid="confirm-dialog-confirm"]').trigger('click')
		await flushAsync()

		expect(showError).toHaveBeenCalledWith('You cannot remove yourself from the house')
	})

	it('cancel in the confirm dialog closes it without removing', async () => {
		const wrapper = await mountList()

		await wrapper.find('[data-testid="member-remove-friend"]').trigger('click')
		await wrapper.find('[data-testid="confirm-dialog-cancel"]').trigger('click')

		expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(false)
		expect(mockUserStore.removeUser).not.toHaveBeenCalled()
	})
})
