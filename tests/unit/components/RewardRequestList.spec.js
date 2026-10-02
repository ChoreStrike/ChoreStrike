/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import RewardRequestList from '../../../src/components/Rewards/RewardRequestList.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
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
	requests: {
		mine: { items: [], cursor: null, exhausted: true, loading: false, error: null },
		house: { items: [], cursor: null, exhausted: true, loading: false, error: null },
	},
	unresolvedCommand: null,
	saving: false,
	refreshError: null,
	fetchRequestPage: jest.fn().mockResolvedValue(),
	retryUnresolved: jest.fn().mockResolvedValue({ id: 5 }),
	dismissUnresolved: jest.fn(),
}

jest.mock('../../../src/store/rewards.js', () => ({
	useRewardsStore: Object.assign(() => require('vue').reactive(mockRewardsState), { $id: 'rewards' }),
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
	isHouseOwner: true,
	refreshCurrentUser: jest.fn().mockResolvedValue({ id: 7, role: 'owner' }),
}

jest.mock('../../../src/store/user.js', () => ({
	useUserStore: Object.assign(() => require('vue').reactive(mockUserState), { $id: 'user' }),
}))

jest.mock('../../../src/components/Help/HelpEntry.vue', () => ({
	name: 'HelpEntry',
	template: '<span data-testid="help-context-catalog" />',
}))

const mockRouterLinkStub = { props: ['to'], template: '<a><slot /></a>' }

const request = (id, overrides = {}) => ({
	id,
	title: `Wish ${id}`,
	priceMinor: 500,
	state: 'pending',
	fundingHold: false,
	requester: { houseUserId: 7, displayName: 'Teen', isFormerMember: false },
	...overrides,
})

/**
 * Own/owner request lists (CS-PLAN-070 WP8 §3.1/§3.4): persistent entry
 * independent of both gates, explicit owner scope, text state labels,
 * funding-hold badges and the uncertain-command retry banner.
 */
describe('RewardRequestList.vue', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
		mockRewardsState.requests.mine.items = []
		mockRewardsState.requests.mine.loading = false
		mockRewardsState.requests.mine.error = null
		mockRewardsState.requests.house.items = []
		mockRewardsState.requests.house.loading = false
		mockRewardsState.requests.house.error = null
		mockRewardsState.unresolvedCommand = null
		mockRewardsState.saving = false
		mockRewardsState.refreshError = null
		mockGameState.wallet = null
		mockUserState.isHouseOwner = true
		mockUserState.currentUser = { id: 7, role: 'owner' }
	})

	const mountList = () => mount(RewardRequestList, {
		global: {
			plugins: [createPinia()],
			stubs: { 'router-link': mockRouterLinkStub },
		},
	})

	it('loads the own list and the wallet independently on mount', async () => {
		mountList()
		await flushPromises()

		expect(mockRewardsState.fetchRequestPage).toHaveBeenCalledWith('mine', { state: null, reset: true })
		expect(mockGameState.fetchGameState).toHaveBeenCalled()
		expect(mockUserState.refreshCurrentUser).toHaveBeenCalled()
	})

	it('never prefetches the owner house scope for anyone', async () => {
		mountList()
		await flushPromises()

		expect(mockRewardsState.fetchRequestPage).toHaveBeenCalledTimes(1)
		expect(mockRewardsState.fetchRequestPage).not.toHaveBeenCalledWith('house', expect.anything())
	})

	it('renders rows with text state labels and links each row to its detail', async () => {
		mockRewardsState.requests.mine.items = [
			request(5, { state: 'pending' }),
			request(4, { state: 'fulfilled' }),
		]
		const wrapper = mountList()
		await flushPromises()

		expect(wrapper.find('[data-testid="request-row-5"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="request-state-5"]').text()).toContain('Waiting for approval')
		expect(wrapper.find('[data-testid="request-state-4"]').text()).toContain('Delivered')
	})

	it('shows a neutral funding-hold badge without punishing language', async () => {
		mockRewardsState.requests.mine.items = [request(5, { fundingHold: true })]
		const wrapper = mountList()
		await flushPromises()

		expect(wrapper.find('[data-testid="request-hold-5"]').text()).toContain('Balance clarification')
	})

	it('shows the house scope tab to owners only and loads it explicitly', async () => {
		const ownerWrapper = mountList()
		await flushPromises()
		expect(ownerWrapper.find('[data-testid="scope-house"]').exists()).toBe(true)

		await ownerWrapper.find('[data-testid="scope-house"]').trigger('click')
		await flushPromises()
		expect(mockRewardsState.fetchRequestPage).toHaveBeenCalledWith('house', { state: null, reset: true })

		mockUserState.isHouseOwner = false
		const memberWrapper = mountList()
		await flushPromises()
		expect(memberWrapper.find('[data-testid="scope-house"]').exists()).toBe(false)
	})

	it('renders former requesters as a localized placeholder without identifiers', async () => {
		mockRewardsState.requests.house.items = [request(6, { requester: { houseUserId: null, displayName: null, isFormerMember: true } })]
		const wrapper = mountList()
		await flushPromises()
		await wrapper.find('[data-testid="scope-house"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="request-row-6"]').text()).toContain('Former member')
		expect(wrapper.find('[data-testid="request-row-6"]').text()).not.toContain('null')
	})

	it('offers the explicit same-key retry for an uncertain command and a dismiss', async () => {
		mockRewardsState.unresolvedCommand = { commandId: 'abc', label: 'request-create' }
		const wrapper = mountList()
		await flushPromises()

		expect(wrapper.find('[data-testid="unresolved-command"]').exists()).toBe(true)
		await wrapper.find('[data-testid="unresolved-retry"]').trigger('click')
		await flushPromises()
		expect(mockRewardsState.retryUnresolved).toHaveBeenCalled()

		mockRewardsState.unresolvedCommand = { commandId: 'abc', label: 'request-create' }
		await wrapper.find('[data-testid="unresolved-dismiss"]').trigger('click')
		expect(mockRewardsState.dismissUnresolved).toHaveBeenCalled()
	})

	it('shows an explicit empty state that keeps requests reachable from the hidden catalog', async () => {
		const wrapper = mountList()
		await flushPromises()

		expect(wrapper.find('[data-testid="requests-empty"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="requests-open-catalog"]').exists()).toBe(true)
	})
})
