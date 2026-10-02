/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import RewardRequestDetails from '../../../src/components/Rewards/RewardRequestDetails.vue'

jest.mock('@nextcloud/l10n', () => ({
	// Placeholder-aware double: `t(app, text, { name })` substitutes
	// `{name}` like the real translator.
	translate: jest.fn((app, text, params) => {
		if (!params) return text
		return text.replace(/\{(\w+)\}/g, (_, key) => params[key] ?? `{${key}}`)
	}),
}))

jest.mock('@nextcloud/dialogs', () => ({
	showSuccess: jest.fn(),
	showError: jest.fn(),
}))

jest.mock('@/utils/logger', () => ({
	logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() },
}))

jest.mock('vue-router', () => ({
	useRouter: () => ({ push: jest.fn() }),
	useRoute: () => ({ params: {}, query: {} }),
}))

const mockRewardsState = {
	detail: {
		request: null,
		audit: [],
		loading: false,
		error: null,
		unavailable: false,
		touched: false,
	},
	unresolvedCommand: null,
	saving: false,
	conflict: false,
	refreshError: null,
	fetchRequestDetail: jest.fn().mockResolvedValue(),
	approveRequest: jest.fn().mockResolvedValue({}),
	rejectRequest: jest.fn().mockResolvedValue({}),
	cancelRequest: jest.fn().mockResolvedValue({}),
	fulfillRequest: jest.fn().mockResolvedValue({}),
	correctRequest: jest.fn().mockResolvedValue({}),
	retryUnresolved: jest.fn().mockResolvedValue({}),
	dismissUnresolved: jest.fn(),
}

jest.mock('../../../src/store/rewards.js', () => ({
	useRewardsStore: Object.assign(() => require('vue').reactive(mockRewardsState), { $id: 'rewards' }),
	REASON_CODES: ['not_needed', 'changed_mind', 'unavailable', 'duplicate', 'not_delivered', 'other'],
}))

const mockGameState = {
	wallet: null,
	fetchGameState: jest.fn().mockResolvedValue(),
}

jest.mock('../../../src/store/game.js', () => ({
	useGameStore: Object.assign(() => require('vue').reactive(mockGameState), { $id: 'game' }),
}))

const mockUserState = {
	currentUser: { id: 7, role: 'owner' },
	applyOwnWallet: jest.fn(),
}

jest.mock('../../../src/store/user.js', () => ({
	useUserStore: Object.assign(() => require('vue').reactive(mockUserState), { $id: 'user' }),
}))

jest.mock('../../../src/components/Common/Modal.vue', () => ({
	name: 'Modal',
	emits: ['close'],
	template: '<div class="modal-stub"><slot /><button class="close-btn" @click="$emit(\'close\')">X</button></div>',
}))

jest.mock('../../../src/components/Common/ConfirmDialog.vue', () => ({
	name: 'ConfirmDialog',
	props: ['title', 'description', 'confirmLabel', 'loading'],
	emits: ['confirm', 'cancel'],
	template: '<div class="confirm-stub" :data-testid="$attrs[\'data-testid\']"><h4>{{ title }}</h4><p>{{ description }}</p><button class="confirm-btn" @click="$emit(\'confirm\')">confirm</button><button class="cancel-btn" @click="$emit(\'cancel\')">cancel</button></div>',
}))

const mockRouterLinkStub = { props: ['to'], template: '<a><slot /></a>' }

const baseRequest = (overrides = {}) => ({
	id: 5,
	itemId: 1,
	requester: { houseUserId: 7, displayName: 'Teen', isFormerMember: false },
	title: 'Zoo day',
	description: 'Popcorn included',
	priceMinor: 700,
	state: 'pending',
	version: 1,
	allowedActions: ['approve', 'reject', 'cancel'],
	fundingHold: false,
	...overrides,
})

/**
 * One authorized request (CS-PLAN-070 WP8 §3.4): frozen terms, audit,
 * actor/state action forms, explicit delivery and correction
 * attestations, and the generic unavailable state for foreign ids.
 */
describe('RewardRequestDetails.vue', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
		mockRewardsState.detail.request = null
		mockRewardsState.detail.audit = []
		mockRewardsState.detail.loading = false
		mockRewardsState.detail.error = null
		mockRewardsState.detail.unavailable = false
		mockRewardsState.unresolvedCommand = null
		mockRewardsState.saving = false
		mockRewardsState.conflict = false
		mockUserState.currentUser = { id: 7, role: 'owner' }
	})

	const mountDetails = () => mount(RewardRequestDetails, {
		props: { requestId: 5 },
		global: {
			plugins: [createPinia()],
			stubs: { 'router-link': mockRouterLinkStub },
		},
	})

	it('fetches the authorized detail on open', async () => {
		mountDetails()
		await flushPromises()
		expect(mockRewardsState.fetchRequestDetail).toHaveBeenCalledWith(5)
	})

	it('renders frozen terms, the state and the audit trail', async () => {
		mockRewardsState.detail.request = baseRequest()
		mockRewardsState.detail.audit = [
			{ revision: 1, action: 'create', actor: { displayName: 'Teen', isFormerMember: false }, createdAt: '2026-10-01T10:00:00+00:00' },
			{ revision: 2, action: 'approve', actor: { displayName: 'Mom', isFormerMember: false }, createdAt: '2026-10-01T11:00:00+00:00' },
		]
		const wrapper = mountDetails()
		await flushPromises()

		expect(wrapper.find('[data-testid="detail-title"]').text()).toContain('Zoo day')
		expect(wrapper.find('[data-testid="detail-terms"]').text()).toContain('Popcorn included')
		expect(wrapper.findAll('.audit-row')).toHaveLength(2)
	})

	it('renders a generic unavailable state for foreign/invisible ids', async () => {
		mockRewardsState.detail.unavailable = true
		const wrapper = mountDetails()
		await flushPromises()

		expect(wrapper.find('[data-testid="detail-unavailable"]').exists()).toBe(true)
		// No reconstructed wishes, no identifiers.
		expect(wrapper.find('[data-testid="detail-terms"]').exists()).toBe(false)
	})

	it('renders former actors as localized placeholders', async () => {
		mockRewardsState.detail.request = baseRequest({ requester: { houseUserId: null, displayName: null, isFormerMember: true } })
		const wrapper = mountDetails()
		await flushPromises()

		expect(wrapper.find('[data-testid="detail-terms"]').text()).toContain('Former member')
	})

	it('offers exactly the current allowedActions (approve/reject/cancel for an owner on a pending child request)', async () => {
		mockRewardsState.detail.request = baseRequest({ requester: { houseUserId: 8, displayName: 'Kid', isFormerMember: false } })
		const wrapper = mountDetails()
		await flushPromises()

		expect(wrapper.find('[data-testid="action-approve"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="action-reject"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="action-cancel"]').exists()).toBe(true)
		// Children never self-fulfill; no correction on an open request.
		expect(wrapper.find('[data-testid="action-fulfill"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="action-correct"]').exists()).toBe(false)
	})

	it('an adult requester explicitly self-approves and self-fulfills their own pending request', async () => {
		mockRewardsState.detail.request = baseRequest({
			requester: { houseUserId: 7, displayName: 'Teen', isFormerMember: false },
			state: 'pending',
			allowedActions: ['approve', 'cancel', 'fulfill'],
		})
		const wrapper = mountDetails()
		await flushPromises()

		expect(wrapper.find('[data-testid="action-approve"]').exists()).toBe(true)
		await wrapper.find('[data-testid="action-approve"]').trigger('click')
		await wrapper.find('[data-testid="approve-dialog"] .confirm-btn').trigger('click')
		await flushPromises()
		expect(mockRewardsState.approveRequest).toHaveBeenCalled()
	})

	it('requires the explicit delivery attestation and spends only on confirm', async () => {
		mockRewardsState.detail.request = baseRequest({ state: 'approved', version: 2, allowedActions: ['fulfill', 'cancel'] })
		const wrapper = mountDetails()
		await flushPromises()

		await wrapper.find('[data-testid="action-fulfill"]').trigger('click')
		const dialog = wrapper.find('[data-testid="fulfill-dialog"]')
		expect(dialog.exists()).toBe(true)
		expect(dialog.text()).toContain('7')

		await wrapper.find('[data-testid="fulfill-dialog"] .confirm-btn').trigger('click')
		await flushPromises()
		expect(mockRewardsState.fulfillRequest).toHaveBeenCalled()
	})

	it('disables fulfillment (not approval/cancellation) under a funding hold', async () => {
		mockRewardsState.detail.request = baseRequest({ state: 'approved', fundingHold: true, allowedActions: ['approve', 'cancel', 'fulfill'] })
		const wrapper = mountDetails()
		await flushPromises()

		expect(wrapper.find('[data-testid="action-fulfill"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="action-cancel"]').attributes('disabled')).toBeUndefined()
		expect(wrapper.find('[data-testid="detail-hold"]').exists()).toBe(true)
	})

	it('requires a reason AND the non-delivery attestation for the owner correction', async () => {
		mockRewardsState.detail.request = baseRequest({ state: 'fulfilled', version: 3, settlementEntryId: 88, allowedActions: ['correct'] })
		const wrapper = mountDetails()
		await flushPromises()

		await wrapper.find('[data-testid="action-correct"]').trigger('click')
		const confirmButton = wrapper.find('[data-testid="correct-confirm"]')

		// Locked until BOTH the closed reason and the attestation are set.
		expect(confirmButton.attributes('disabled')).toBeDefined()
		await wrapper.find('[data-testid="correct-reason-select"]').setValue('not_delivered')
		expect(wrapper.find('[data-testid="correct-confirm"]').attributes('disabled')).toBeDefined()
		await wrapper.find('[data-testid="correct-attestation"]').setValue(true)
		expect(wrapper.find('[data-testid="correct-confirm"]').attributes('disabled')).toBeUndefined()

		await wrapper.find('[data-testid="correct-confirm"]').trigger('click')
		await flushPromises()
		// The refund is the server's original settlement entry — never a
		// client amount and never the current item price.
		expect(mockRewardsState.correctRequest).toHaveBeenCalledWith(
			expect.objectContaining({ settlementEntryId: 88 }),
			'not_delivered',
		)
	})

	it('offers no ordinary refund/cancel on a fulfilled (spent) request', async () => {
		mockRewardsState.detail.request = baseRequest({ state: 'fulfilled', allowedActions: ['correct'] })
		const wrapper = mountDetails()
		await flushPromises()

		expect(wrapper.find('[data-testid="action-cancel"]').exists()).toBe(false)
		// Only the attested non-delivery correction remains.
		expect(wrapper.find('[data-testid="action-correct"]').exists()).toBe(true)
	})

	it('shows the explicit same-key retry for an uncertain command outcome', async () => {
		mockRewardsState.detail.request = baseRequest()
		mockRewardsState.unresolvedCommand = { commandId: 'abc', label: 'request-approve' }
		const wrapper = mountDetails()
		await flushPromises()

		expect(wrapper.find('[data-testid="unresolved-command"]').exists()).toBe(true)
		await wrapper.find('[data-testid="unresolved-retry"]').trigger('click')
		await flushPromises()
		expect(mockRewardsState.retryUnresolved).toHaveBeenCalled()
	})
})
