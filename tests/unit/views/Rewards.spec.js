/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import Rewards from '../../../src/views/Rewards.vue'

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

// Mutable reactive store doubles (the cross-layer contract lives in
// rewards.spec.js / house.spec.js / preferences.spec.js).
const mockRewardsState = {
	catalog: { active: { items: [], cursor: null, exhausted: true, loading: false, error: null }, admin: { items: [], cursor: null, exhausted: true, loading: false, error: null } },
	saving: false,
	conflict: false,
	refreshError: null,
	fetchCatalogPage: jest.fn().mockResolvedValue(),
	createRequest: jest.fn().mockResolvedValue({ id: 5 }),
}

jest.mock('../../../src/store/rewards.js', () => ({
	useRewardsStore: Object.assign(() => require('vue').reactive(mockRewardsState), { $id: 'rewards' }),
}))

const mockHouseState = {
	house: { id: 1, catalogEnabled: true },
	hasHouse: true,
	catalogEnabled: true,
	loading: false,
	fetchHouse: jest.fn().mockResolvedValue(),
}

jest.mock('../../../src/store/house.js', () => ({
	useHouseStore: Object.assign(() => require('vue').reactive(mockHouseState), { $id: 'house' }),
}))

const mockPreferencesState = {
	loaded: true,
	catalogVisible: true,
	fetchPreferences: jest.fn().mockResolvedValue(),
}

jest.mock('../../../src/store/preferences.js', () => ({
	usePreferencesStore: Object.assign(() => require('vue').reactive(mockPreferencesState), { $id: 'preferences' }),
}))

const mockGameState = {
	wallet: null,
	fetchGameState: jest.fn().mockResolvedValue(),
}

jest.mock('../../../src/store/game.js', () => ({
	useGameStore: Object.assign(() => require('vue').reactive(mockGameState), { $id: 'game' }),
}))

const mockUserState = {
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

jest.mock('../../../src/components/Help/HelpEntry.vue', () => ({
	name: 'HelpEntry',
	template: '<span data-testid="help-context-catalog" />',
}))

const mockRouterLinkStub = { props: ['to'], template: '<a><slot /></a>' }

/**
 * Catalog browsing surface (CS-PLAN-070 WP8 §3.1/§3.3/§3.4): both
 * independent gates, neutral hidden state, exact price display, frozen
 * terms review and conflict-safe requesting.
 */
describe('Rewards.vue', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
		mockRewardsState.catalog.active.items = []
		mockRewardsState.catalog.active.exhausted = true
		mockRewardsState.catalog.active.loading = false
		mockRewardsState.conflict = false
		mockRewardsState.refreshError = null
		mockRewardsState.saving = false
		mockHouseState.house = { id: 1, catalogEnabled: true }
		mockHouseState.catalogEnabled = true
		mockHouseState.hasHouse = true
		mockPreferencesState.loaded = true
		mockPreferencesState.catalogVisible = true
		mockGameState.wallet = null
	})

	const mountView = () => {
		const pinia = createPinia()
		setActivePinia(pinia)
		return mount(Rewards, {
			global: {
				plugins: [pinia],
				stubs: { 'router-link': mockRouterLinkStub },
			},
		})
	}

	it('shows a neutral explanation when the personal preference hides browsing (no stale items)', async () => {
		mockPreferencesState.catalogVisible = false
		const wrapper = mountView()
		await flushPromises()

		expect(wrapper.find('[data-testid="rewards-unavailable"]').exists()).toBe(true)
		expect(mockRewardsState.fetchCatalogPage).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="catalog-list"]').exists()).toBe(false)
	})

	it('shows a neutral explanation when the house gate is off, even with a visible preference', async () => {
		mockHouseState.catalogEnabled = false
		const wrapper = mountView()
		await flushPromises()

		expect(wrapper.find('[data-testid="rewards-unavailable"]').exists()).toBe(true)
		expect(mockRewardsState.fetchCatalogPage).not.toHaveBeenCalled()
	})

	it('renders active items with exact formatted prices once both gates are open', async () => {
		mockRewardsState.catalog.active.items = [
			{ id: 1, title: 'Zoo day', description: 'Popcorn included', priceMinor: 1250, version: 0 },
			{ id: 2, title: 'Movie night', description: null, priceMinor: 700, version: 1 },
		]
		mockGameState.wallet = { bookedMinor: 3000, reservedMinor: 700, availableMinor: 2300, fundingHold: false }
		const wrapper = mountView()
		await flushPromises()

		expect(wrapper.find('[data-testid="catalog-list"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="catalog-item-1"]').text()).toContain('Zoo day')
		expect(wrapper.find('[data-testid="catalog-item-price"]').text()).toContain('12.5')
		expect(wrapper.find('[data-testid="wallet-available"]').text()).toContain('23')
		expect(wrapper.find('[data-testid="wallet-reserved"]').text()).toContain('7')
	})

	it('disables new requests neutrally while a funding hold is active', async () => {
		mockRewardsState.catalog.active.items = [{ id: 1, title: 'Zoo day', priceMinor: 500, version: 0 }]
		mockGameState.wallet = { bookedMinor: 100, reservedMinor: 700, availableMinor: 0, fundingHold: true }
		const wrapper = mountView()
		await flushPromises()

		expect(wrapper.find('[data-testid="wallet-hold"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="catalog-request-1"]').attributes('disabled')).toBeDefined()
	})

	it('requires an explicit confirmation of the frozen displayed terms before requesting', async () => {
		mockRewardsState.catalog.active.items = [{ id: 3, title: 'Zoo day', description: 'Frozen terms', priceMinor: 700, version: 2 }]
		const wrapper = mountView()
		await flushPromises()

		await wrapper.find('[data-testid="catalog-request-3"]').trigger('click')
		const dialog = wrapper.find('[data-testid="request-confirm"]')
		expect(dialog.exists()).toBe(true)
		expect(dialog.find('[data-testid="request-confirm-title"]').text()).toContain('Zoo day')

		await wrapper.find('[data-testid="request-confirm-button"]').trigger('click')
		await flushPromises()

		// The displayed item (id, version, price) is the command identity.
		expect(mockRewardsState.createRequest).toHaveBeenCalledWith(
			expect.objectContaining({ id: 3, version: 2, priceMinor: 700 }),
		)
		expect(wrapper.find('[data-testid="request-confirm"]').exists()).toBe(false)
	})

	it('closes the confirmation on a 409 conflict and shows the refreshed-terms note', async () => {
		mockRewardsState.catalog.active.items = [{ id: 3, title: 'Zoo day', priceMinor: 700, version: 2 }]
		// The real store flags `conflict` and re-reads the sources (see
		// rewards.spec.js) — the double simulates that contract. The
		// mutation MUST go through the reactive proxy: raw-object writes
		// never fire Vue triggers.
		mockRewardsState.createRequest.mockImplementationOnce(() => {
			require('vue').reactive(mockRewardsState).conflict = true
			return Promise.reject(Object.assign(new Error('Displayed terms drifted'), { status: 409 }))
		})
		const wrapper = mountView()
		await flushPromises()

		await wrapper.find('[data-testid="catalog-request-3"]').trigger('click')
		await wrapper.find('[data-testid="request-confirm-button"]').trigger('click')
		await flushPromises()

		// Never a silent reservation: the dialog closes, the conflict
		// note demands a renewed review of the refreshed terms.
		expect(wrapper.find('[data-testid="request-confirm"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="catalog-conflict"]').exists()).toBe(true)
	})

	it('keeps authored text as escaped content (no HTML injection)', async () => {
		mockRewardsState.catalog.active.items = [{ id: 4, title: '<img src=x>', description: '<script>alert(1)</script>', priceMinor: 100, version: 0 }]
		const wrapper = mountView()
		await flushPromises()

		const html = wrapper.find('[data-testid="catalog-item-4"]').html()
		expect(html).not.toContain('<img src=x>')
		expect(html).not.toContain('<script>')
	})

	it('re-fetches the catalog when retry follows a failed initial load (no fake empty catalog)', async () => {
		mockRewardsState.fetchCatalogPage.mockRejectedValueOnce(new Error('Network dropped'))
		const wrapper = mountView()
		await flushPromises()

		expect(wrapper.find('[data-testid="rewards-error"]').exists()).toBe(true)

		// The mutation MUST go through the reactive proxy (raw-object
		// writes never fire Vue triggers — see the 409 test above).
		require('vue').reactive(mockRewardsState).catalog.active.items = [{ id: 9, title: 'Zoo day', priceMinor: 700, version: 0 }]
		await wrapper.find('[data-testid="rewards-error"] [data-testid="error-retry"]').trigger('click')
		await flushPromises()

		expect(mockRewardsState.fetchCatalogPage).toHaveBeenCalledTimes(2)
		expect(wrapper.find('[data-testid="catalog-item-9"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="rewards-error"]').exists()).toBe(false)
	})
})
