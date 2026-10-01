/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import PauseSettings from '@/components/Settings/PauseSettings.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, params) => {
		if (!params) return text
		return Object.entries(params).reduce((acc, [k, v]) => acc.replaceAll(`{${k}}`, String(v)), text)
	}),
}))

jest.mock('@nextcloud/dialogs', () => ({
	showSuccess: jest.fn(),
	showError: jest.fn(),
	showWarning: jest.fn(),
}))

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

const mockPausesState = {
	pauses: [],
	loading: false,
	saving: false,
	error: null,
	conflict: false,
	fetchPauses: jest.fn().mockResolvedValue([]),
	requestPersonalPause: jest.fn().mockResolvedValue({ pauseGroupId: 1, revision: 1, state: 'requested' }),
	shortenPause: jest.fn().mockResolvedValue({ pauseGroupId: 1, revision: 2, state: 'acknowledged' }),
}

jest.mock('@/store/pauses.js', () => ({
	usePausesStore: () => require('vue').reactive(mockPausesState),
}))

const mockUserState = {
	currentUser: { nextcloudUserId: 'teen', role: 'child' },
}

jest.mock('@/store/user.js', () => ({
	useUserStore: () => require('vue').reactive(mockUserState),
}))

const pauseEntry = (over = {}) => ({
	pauseGroupId: 1,
	revision: 1,
	scope: 'personal',
	subjectWalletId: 11,
	subjectUserId: 'teen',
	state: 'requested',
	startDate: '2026-10-01',
	endDate: '2026-10-03',
	timezone: 'Europe/Berlin',
	createdAt: '2026-09-30T10:00:00+00:00',
	updatedAt: '2026-09-30T10:00:00+00:00',
	reasonCode: 'illness',
	isRetrospectiveIllness: false,
	...over,
})

/**
 * Self-only pause surface (CS-PLAN-070 WP6 §3.4, FEAT-UX-025 remainder):
 * neutral copy, request form, own-list visibility, shortening with the
 * displayed expectedRevision, 409 draft preservation.
 */
describe('PauseSettings', () => {
	beforeEach(() => {
		mockUserState.currentUser = { nextcloudUserId: 'teen', role: 'child' }
		mockPausesState.pauses = []
		mockPausesState.saving = false
		mockPausesState.conflict = false
		mockPausesState.error = null
		mockPausesState.fetchPauses.mockClear().mockResolvedValue([])
		mockPausesState.requestPersonalPause.mockClear().mockResolvedValue({ pauseGroupId: 1, revision: 1, state: 'requested' })
		mockPausesState.shortenPause.mockClear().mockResolvedValue({ pauseGroupId: 1, revision: 2, state: 'acknowledged' })
	})

	const mountComponent = async () => {
		const wrapper = mount(PauseSettings)
		await flushPromises()
		return wrapper
	}

	it('loads the viewer-whitelisted list on mount and shows only the own entries', async () => {
		mockPausesState.pauses = [
			pauseEntry({ pauseGroupId: 1, subjectUserId: 'teen' }),
			pauseEntry({ pauseGroupId: 2, subjectUserId: 'mom', state: 'acknowledged' }),
		]
		const wrapper = await mountComponent()

		expect(mockPausesState.fetchPauses).toHaveBeenCalledTimes(1)
		// A child never sees another member's pause data (C5h).
		expect(wrapper.findAll('[data-testid^="pause-entry-"]')).toHaveLength(1)
		expect(wrapper.find('[data-testid="pause-entry-1"]').exists()).toBe(true)
	})

	it('sends a personal pause request with the whitelisted fields', async () => {
		const wrapper = await mountComponent()

		await wrapper.find('[data-testid="pause-start-input"]').setValue('2026-10-01')
		await wrapper.find('[data-testid="pause-end-input"]').setValue('2026-10-03')
		await wrapper.find('[data-testid="pause-reason-select"]').setValue('illness')
		await wrapper.find('[data-testid="pause-request-submit"]').trigger('submit')
		await flushPromises()

		expect(mockPausesState.requestPersonalPause).toHaveBeenCalledWith({
			startDate: '2026-10-01',
			endDate: '2026-10-03',
			reasonCode: 'illness',
		})
		// The used draft resets; the conflict note stays hidden.
		expect(wrapper.find('[data-testid="pause-start-input"]').element.value).toBe('')
		expect(wrapper.find('[data-testid="pause-conflict"]').exists()).toBe(false)
	})

	it('labels the pending state neutrally and explains the owner decision', async () => {
		mockPausesState.pauses = [pauseEntry({ state: 'requested' })]
		const wrapper = await mountComponent()

		expect(wrapper.text()).toContain('Waiting for the house owner')
	})

	it('offers the shortening for acknowledged pauses with the displayed expectedRevision', async () => {
		mockPausesState.pauses = [pauseEntry({ state: 'acknowledged', revision: 3 })]
		const wrapper = await mountComponent()

		await wrapper.find('[data-testid="pause-shorten-input-1"]').setValue('2026-10-02')
		await wrapper.find('[data-testid="pause-shorten-submit-1"]').trigger('click')
		await flushPromises()

		expect(mockPausesState.shortenPause).toHaveBeenCalledWith(1, {
			endDate: '2026-10-02',
			expectedRevision: 3,
		})
	})

	it('keeps the draft on a 409 and shows the conflict note', async () => {
		// The real store flags the conflict before rethrowing; the mock
		// mirrors that contract (mutate via the reactive proxy).
		mockPausesState.requestPersonalPause.mockImplementation(async () => {
			require('vue').reactive(mockPausesState).conflict = true
			throw Object.assign(new Error('HTTP 409'), { status: 409 })
		})
		const wrapper = await mountComponent()

		await wrapper.find('[data-testid="pause-start-input"]').setValue('2026-10-01')
		await wrapper.find('[data-testid="pause-end-input"]').setValue('2026-10-03')
		await wrapper.find('[data-testid="pause-request-submit"]').trigger('submit')
		await flushPromises()

		expect(wrapper.find('[data-testid="pause-conflict"]').exists()).toBe(true)
		// Draft preservation: the entered values survive the conflict.
		expect(wrapper.find('[data-testid="pause-start-input"]').element.value).toBe('2026-10-01')
		expect(wrapper.find('[data-testid="pause-end-input"]').element.value).toBe('2026-10-03')
	})
})
