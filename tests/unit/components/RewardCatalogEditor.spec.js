/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import RewardCatalogEditor from '../../../src/components/Rewards/RewardCatalogEditor.vue'

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

const mockRewardsState = {
	catalog: { admin: { items: [], cursor: null, exhausted: true, loading: false, error: null } },
	saving: false,
	conflict: false,
	fetchCatalogPage: jest.fn().mockResolvedValue(),
	createCatalogItem: jest.fn().mockResolvedValue({ id: 1 }),
	updateCatalogItem: jest.fn().mockResolvedValue({ id: 1, version: 1 }),
}

jest.mock('../../../src/store/rewards.js', () => ({
	useRewardsStore: Object.assign(() => require('vue').reactive(mockRewardsState), { $id: 'rewards' }),
}))

const mockHouseState = {
	catalogEnabled: false,
	loading: false,
	error: null,
	updateCatalogEnabled: jest.fn().mockResolvedValue(),
}

jest.mock('../../../src/store/house.js', () => ({
	useHouseStore: Object.assign(() => require('vue').reactive(mockHouseState), { $id: 'house' }),
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
	template: '<div class="confirm-stub"><button class="confirm-btn" @click="$emit(\'confirm\')">confirm</button><button class="cancel-btn" @click="$emit(\'cancel\')">cancel</button></div>',
}))

jest.mock('../../../src/components/Help/HelpEntry.vue', () => ({
	name: 'HelpEntry',
	template: '<span data-testid="help-context-owner-pricing" />',
}))

/**
 * Owner catalog administration (CS-PLAN-070 WP8 §3.1/§3.3): gate toggle,
 * exact decimal price entry, escaped authored content and 409-safe drafts.
 */
describe('RewardCatalogEditor.vue', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
		mockRewardsState.catalog.admin.items = []
		mockRewardsState.catalog.admin.exhausted = true
		mockRewardsState.catalog.admin.loading = false
		mockRewardsState.saving = false
		mockRewardsState.conflict = false
		mockHouseState.catalogEnabled = false
		mockHouseState.loading = false
		mockHouseState.error = null
	})

	const mountEditor = () => mount(RewardCatalogEditor, {
		global: { plugins: [createPinia()] },
	})

	it('loads the admin scope (archived items included, even while the gate is off)', async () => {
		mountEditor()
		await flushPromises()

		expect(mockRewardsState.fetchCatalogPage).toHaveBeenCalledWith('admin', { reset: true })
	})

	it('toggles the household gate through the house store writer', async () => {
		const wrapper = mountEditor()
		await flushPromises()

		await wrapper.find('[data-testid="catalog-gate-toggle"]').setValue(true)
		await wrapper.find('[data-testid="catalog-gate-save"]').trigger('click')
		await flushPromises()

		expect(mockHouseState.updateCatalogEnabled).toHaveBeenCalledWith(true)
	})

	// CS-PLAN-086 R086B-01: the house store's error is shared by every house
	// save on the Settings page; only the gate's own failure shows its note.
	it('shows the gate error only for a failed gate save, not for another house error', async () => {
		// Another house save on the page failed before the editor rendered.
		mockHouseState.error = 'Owner role required'
		const wrapper = mountEditor()
		await flushPromises()
		expect(wrapper.find('[data-testid="catalog-gate-error"]').exists()).toBe(false)

		mockHouseState.updateCatalogEnabled.mockRejectedValueOnce(new Error('Conflict'))
		await wrapper.find('[data-testid="catalog-gate-toggle"]').setValue(true)
		await wrapper.find('[data-testid="catalog-gate-save"]').trigger('click')
		await flushPromises()
		expect(wrapper.get('[data-testid="catalog-gate-error"]').attributes('role')).toBe('alert')

		await wrapper.find('[data-testid="catalog-gate-save"]').trigger('click')
		await flushPromises()
		expect(wrapper.find('[data-testid="catalog-gate-error"]').exists()).toBe(false)
	})

	it('parses exact decimal prices (whole and two digits) into integer minor units', async () => {
		const wrapper = mountEditor()
		await flushPromises()

		await wrapper.find('[data-testid="new-item-title"]').setValue('Zoo day')
		await wrapper.find('[data-testid="new-item-price"]').setValue('12.50')
		await wrapper.find('[data-testid="new-item-submit"]').trigger('submit')
		await flushPromises()

		expect(mockRewardsState.createCatalogItem).toHaveBeenCalledWith(
			expect.objectContaining({ title: 'Zoo day', priceMinor: 1250 }),
		)
	})

	it('accepts whole prices and comma decimals', async () => {
		const wrapper = mountEditor()
		await flushPromises()

		await wrapper.find('[data-testid="new-item-title"]').setValue('A')
		await wrapper.find('[data-testid="new-item-price"]').setValue('7')
		await wrapper.find('[data-testid="new-item-submit"]').trigger('submit')
		await flushPromises()
		expect(mockRewardsState.createCatalogItem.mock.calls[0][0].priceMinor).toBe(700)

		await wrapper.find('[data-testid="new-item-title"]').setValue('B')
		await wrapper.find('[data-testid="new-item-price"]').setValue('7,50')
		await wrapper.find('[data-testid="new-item-submit"]').trigger('submit')
		await flushPromises()
		expect(mockRewardsState.createCatalogItem.mock.calls[1][0].priceMinor).toBe(750)
	})

	it('rejects malformed prices with an actionable message and sends nothing', async () => {
		const wrapper = mountEditor()
		await flushPromises()

		await wrapper.find('[data-testid="new-item-title"]').setValue('Zoo day')
		await wrapper.find('[data-testid="new-item-price"]').setValue('12.345')
		await wrapper.find('[data-testid="new-item-submit"]').trigger('submit')
		await flushPromises()

		expect(mockRewardsState.createCatalogItem).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="catalog-form-error"]').exists()).toBe(true)
	})

	it('requires a title and enforces the 120-character bound', async () => {
		const wrapper = mountEditor()
		await flushPromises()

		await wrapper.find('[data-testid="new-item-price"]').setValue('5')
		await wrapper.find('[data-testid="new-item-submit"]').trigger('submit')
		await flushPromises()
		expect(mockRewardsState.createCatalogItem).not.toHaveBeenCalled()

		await wrapper.find('[data-testid="new-item-title"]').setValue('x'.repeat(121))
		await wrapper.find('[data-testid="new-item-submit"]').trigger('submit')
		await flushPromises()
		expect(mockRewardsState.createCatalogItem).not.toHaveBeenCalled()
	})

	it('archives an item through the update route (never DELETE)', async () => {
		mockRewardsState.catalog.admin.items = [{ id: 8, title: 'Old treat', description: null, priceMinor: 300, archived: false, version: 2 }]
		const wrapper = mountEditor()
		await flushPromises()

		await wrapper.find('[data-testid="admin-archive-8"]').trigger('click')
		await wrapper.find('[data-testid="archive-dialog"] .confirm-btn').trigger('click')
		await flushPromises()

		expect(mockRewardsState.updateCatalogItem).toHaveBeenCalledWith(
			expect.objectContaining({ id: 8 }),
			expect.objectContaining({ archived: true }),
		)
	})

	it('keeps the edit draft on a 409 conflict and shows the conflict note', async () => {
		mockRewardsState.catalog.admin.items = [{ id: 8, title: 'Old treat', description: null, priceMinor: 300, archived: false, version: 2 }]
		// The real store flags `conflict` and re-reads the admin page
		// (see rewards.spec.js) — the double simulates that contract. The
		// mutation MUST go through the reactive proxy: raw-object writes
		// never fire Vue triggers.
		mockRewardsState.updateCatalogItem.mockImplementationOnce(() => {
			require('vue').reactive(mockRewardsState).conflict = true
			return Promise.reject(Object.assign(new Error('Terms drifted'), { status: 409 }))
		})
		const wrapper = mountEditor()
		await flushPromises()

		await wrapper.find('[data-testid="admin-edit-8"]').trigger('click')
		await wrapper.find('[data-testid="edit-item-price"]').setValue('9.99')
		await wrapper.find('[data-testid="edit-item-save"]').trigger('click')
		await flushPromises()

		// The dialog stays open (draft preserved); only the conflict note shows.
		expect(wrapper.find('[data-testid="catalog-item-edit"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="catalog-editor-conflict"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="edit-item-price"]').element.value).toBe('9.99')
	})
})
