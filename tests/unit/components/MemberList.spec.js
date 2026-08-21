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

	it('requires confirm-modal consent before removing a member', async () => {
		const wrapper = await mountList()

		await wrapper.find('[data-testid="member-remove-friend"]').trigger('click')
		expect(wrapper.find('[data-testid="member-remove-confirm"]').exists()).toBe(true)
		expect(mockUserStore.removeUser).not.toHaveBeenCalled()

		await wrapper.find('[data-testid="member-remove-confirm-button"]').trigger('click')
		await flushAsync()

		expect(mockUserStore.removeUser).toHaveBeenCalledWith(3)
		expect(showSuccess).toHaveBeenCalled()
	})

	it('cancel in the confirm modal closes it without removing', async () => {
		const wrapper = await mountList()

		await wrapper.find('[data-testid="member-remove-friend"]').trigger('click')
		await wrapper.find('[data-testid="member-remove-cancel"]').trigger('click')

		expect(wrapper.find('[data-testid="member-remove-confirm"]').exists()).toBe(false)
		expect(mockUserStore.removeUser).not.toHaveBeenCalled()
	})
})
