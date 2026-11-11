/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import AbsenceView from '@/views/AbsenceView.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, params) => {
		if (!params) return text
		return Object.entries(params).reduce((acc, [k, v]) => acc.replaceAll(`{${k}}`, String(v)), text)
	}),
}))

jest.mock('@nextcloud/dialogs', () => ({
	showSuccess: jest.fn(),
	showError: jest.fn(),
}))

jest.mock('@/utils/logger', () => ({
	logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() },
}))

// A fixed "today" keeps the ended/running split independent of the clock.
jest.mock('@/utils/dateKeys.js', () => ({
	...jest.requireActual('@/utils/dateKeys.js'),
	todayKeyInZone: jest.fn(() => '2026-11-10'),
}))

const mockPausesState = {
	pauses: [],
	loading: false,
	saving: false,
	error: null,
	conflict: false,
	fetchPauses: jest.fn(),
	requestPersonalPause: jest.fn(),
	createHousePause: jest.fn(),
	recognizeIllness: jest.fn(),
	approvePause: jest.fn(),
	rejectPause: jest.fn(),
	deletePause: jest.fn(),
}

jest.mock('@/store/pauses.js', () => ({
	usePausesStore: () => require('vue').reactive(mockPausesState),
}))

const mockUserState = {
	currentUser: { nextcloudUserId: 'kim', role: 'child' },
	isHouseOwner: false,
	houseUsers: [],
	fetchHouseUsers: jest.fn(),
}

jest.mock('@/store/user.js', () => ({
	useUserStore: () => require('vue').reactive(mockUserState),
}))

jest.mock('@/store/house.js', () => ({
	useHouseStore: () => ({ timezone: 'Europe/Berlin' }),
}))

const { showError, showSuccess } = require('@nextcloud/dialogs')

const absence = (over = {}) => ({
	pauseGroupId: 1,
	revision: 1,
	scope: 'personal',
	subjectUserId: 'kim',
	state: 'acknowledged',
	startDate: '2026-11-20',
	endDate: '2026-11-22',
	timezone: 'Europe/Berlin',
	// The server's verdict for this viewer: the viewer's own upcoming absence.
	deletable: true,
	...over,
})

const apiError = (status) => Object.assign(new Error(`HTTP ${status}`), { status })

const mountView = () => mount(AbsenceView, {
	global: { stubs: { NcLoadingIcon: true, ErrorState: true } },
})

/**
 * The Absence view (ADR-020, FEAT-UX-065): a plain period for everyone,
 * one shared list, deletion for the subject and the owner while the
 * absence has not ended, and no reason anywhere.
 */
describe('AbsenceView', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		Object.assign(mockPausesState, { pauses: [], loading: false, saving: false, error: null, conflict: false })
		mockPausesState.fetchPauses.mockResolvedValue([])
		mockPausesState.requestPersonalPause.mockResolvedValue({ pauseGroupId: 9, revision: 1, state: 'acknowledged' })
		mockPausesState.createHousePause.mockResolvedValue({ pauseGroupId: 9, revision: 1, state: 'acknowledged' })
		mockPausesState.recognizeIllness.mockResolvedValue({ pauseGroupId: 9, revision: 1, state: 'acknowledged' })
		mockPausesState.approvePause.mockResolvedValue({})
		mockPausesState.rejectPause.mockResolvedValue({})
		mockPausesState.deletePause.mockResolvedValue({ pauseGroupId: 1, revision: 2, state: 'cancelled' })
		mockUserState.currentUser = { nextcloudUserId: 'kim', role: 'child' }
		mockUserState.isHouseOwner = false
		mockUserState.houseUsers = [
			{ nextcloudUserId: 'kim', displayName: 'Kim' },
			{ nextcloudUserId: 'mia', displayName: 'Mia' },
		]
		mockUserState.fetchHouseUsers.mockResolvedValue()
	})

	it('loads the shared list and offers a child the plain period form', async () => {
		const wrapper = mountView()
		await flushPromises()

		expect(mockPausesState.fetchPauses).toHaveBeenCalledTimes(1)
		expect(wrapper.find('[data-testid="absence-own-form"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="absence-empty"]').exists()).toBe(true)
		expect(wrapper.find('select').exists()).toBe(false)
		expect(wrapper.text()).toContain('No reason, no request.')
		expect(wrapper.find('[data-testid="absence-owner-panel"]').exists()).toBe(false)
	})

	it('enters the own absence with the two dates only and clears the form', async () => {
		const wrapper = mountView()
		const submit = wrapper.find('[data-testid="absence-own-submit"]')
		expect(submit.attributes('disabled')).toBeDefined()

		await wrapper.find('[data-testid="absence-own-start"]').setValue('2026-12-05')
		await wrapper.find('[data-testid="absence-own-end"]').setValue('2026-12-06')
		await wrapper.find('[data-testid="absence-own-form"]').trigger('submit')
		await flushPromises()

		expect(mockPausesState.requestPersonalPause).toHaveBeenCalledWith({ startDate: '2026-12-05', endDate: '2026-12-06' })
		expect(showSuccess).toHaveBeenCalledWith('The absence was entered.')
		expect(wrapper.find('[data-testid="absence-own-start"]').element.value).toBe('')
	})

	it('keeps the submit disabled while the end lies before the start', async () => {
		const wrapper = mountView()

		await wrapper.find('[data-testid="absence-own-start"]').setValue('2026-12-06')
		await wrapper.find('[data-testid="absence-own-end"]').setValue('2026-12-05')

		expect(wrapper.find('[data-testid="absence-own-submit"]').attributes('disabled')).toBeDefined()
	})

	it('keeps the draft and shows the conflict note after a 409', async () => {
		mockPausesState.requestPersonalPause.mockImplementation(async () => {
			mockPausesState.conflict = true
			throw apiError(409)
		})
		const wrapper = mountView()
		await wrapper.find('[data-testid="absence-own-start"]').setValue('2026-12-05')
		await wrapper.find('[data-testid="absence-own-end"]').setValue('2026-12-06')
		await wrapper.find('[data-testid="absence-own-form"]').trigger('submit')
		await flushPromises()

		expect(wrapper.find('[data-testid="absence-own-start"]').element.value).toBe('2026-12-05')
		expect(showError).not.toHaveBeenCalled()
	})

	it('reports any other failure with a toast and keeps the draft', async () => {
		mockPausesState.requestPersonalPause.mockRejectedValue(apiError(500))
		const wrapper = mountView()
		await wrapper.find('[data-testid="absence-own-start"]').setValue('2026-12-05')
		await wrapper.find('[data-testid="absence-own-end"]').setValue('2026-12-06')
		await wrapper.find('[data-testid="absence-own-form"]').trigger('submit')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('The absence could not be entered.')
		expect(wrapper.find('[data-testid="absence-own-end"]').element.value).toBe('2026-12-06')
	})

	it('shows everyone\'s entries without a reason: open ones by start, ended ones last', async () => {
		mockPausesState.pauses = [
			absence({ pauseGroupId: 1, subjectUserId: 'mia', startDate: '2026-11-23', endDate: '2026-11-27', reasonCode: 'illness' }),
			absence({ pauseGroupId: 2, startDate: '2026-11-01', endDate: '2026-11-03' }),
			absence({ pauseGroupId: 3, scope: 'house', subjectUserId: null, startDate: '2026-12-24', endDate: '2027-01-02' }),
			absence({ pauseGroupId: 4, startDate: '2026-11-08', endDate: '2026-11-10' }),
			absence({ pauseGroupId: 5, state: 'rejected' }),
		]
		const wrapper = mountView()
		await flushPromises()

		const rows = wrapper.findAll('.absence-entry')
		expect(rows.map((row) => row.attributes('data-testid'))).toEqual([
			'absence-entry-4', 'absence-entry-1', 'absence-entry-3', 'absence-entry-2',
		])
		expect(rows[1].text()).toContain('Mia')
		expect(rows[2].text()).toContain('Whole household')
		expect(rows[3].classes()).toContain('ended')
		expect(wrapper.text()).not.toContain('illness')
		expect(wrapper.text()).not.toContain('Illness')
	})

	it('shows a delete control exactly where the server allows this viewer to delete', async () => {
		mockPausesState.pauses = [
			absence({ pauseGroupId: 1 }),
			absence({ pauseGroupId: 2, subjectUserId: 'mia', deletable: false }),
			absence({ pauseGroupId: 3, scope: 'house', subjectUserId: null, deletable: false }),
			absence({ pauseGroupId: 4, startDate: '2026-11-01', endDate: '2026-11-09', deletable: false }),
			absence({ pauseGroupId: 5, startDate: '2026-11-08', endDate: '2026-11-10' }),
			// An entry without the server's verdict offers no control.
			absence({ pauseGroupId: 6, deletable: undefined }),
		]
		const wrapper = mountView()
		await flushPromises()

		const deletable = wrapper.findAll('.absence-delete').map((button) => button.attributes('data-testid'))
		expect(deletable).toEqual(['absence-delete-5', 'absence-delete-1'])
		expect(wrapper.find('[data-testid="absence-delete-1"]').attributes('aria-label')).toBe('Delete the absence of Kim')
	})

	it('deletes only after the confirmation, with the displayed revision', async () => {
		mockPausesState.pauses = [absence({ pauseGroupId: 7, revision: 3 })]
		const wrapper = mountView()
		await flushPromises()

		await wrapper.find('[data-testid="absence-delete-7"]').trigger('click')
		expect(mockPausesState.deletePause).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(true)

		await wrapper.find('[data-testid="confirm-dialog-confirm"]').trigger('click')
		await flushPromises()

		expect(mockPausesState.deletePause).toHaveBeenCalledWith(7, 3)
		expect(showSuccess).toHaveBeenCalledWith('The absence was deleted.')
		expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(false)
	})

	it('cancelling the confirmation deletes nothing', async () => {
		mockPausesState.pauses = [absence({ pauseGroupId: 7 })]
		const wrapper = mountView()
		await flushPromises()

		await wrapper.find('[data-testid="absence-delete-7"]').trigger('click')
		await wrapper.find('[data-testid="confirm-dialog-cancel"]').trigger('click')

		expect(mockPausesState.deletePause).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(false)
	})

	it('reports a failed deletion and closes the dialog', async () => {
		mockPausesState.deletePause.mockRejectedValue(apiError(403))
		mockPausesState.pauses = [absence({ pauseGroupId: 7 })]
		const wrapper = mountView()
		await flushPromises()

		await wrapper.find('[data-testid="absence-delete-7"]').trigger('click')
		await wrapper.find('[data-testid="confirm-dialog-confirm"]').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('The absence could not be deleted.')
		expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(false)
	})

	it('shows the owner the delete controls the server allows, labelled per subject', async () => {
		mockUserState.currentUser = { nextcloudUserId: 'pat', role: 'owner' }
		mockUserState.isHouseOwner = true
		mockPausesState.pauses = [
			absence({ pauseGroupId: 1 }),
			absence({ pauseGroupId: 2, subjectUserId: 'mia' }),
			absence({ pauseGroupId: 3, scope: 'house', subjectUserId: null }),
			absence({ pauseGroupId: 4, startDate: '2026-11-01', endDate: '2026-11-09', deletable: false }),
		]
		const wrapper = mountView()
		await flushPromises()

		expect(wrapper.findAll('.absence-delete')).toHaveLength(3)
		expect(wrapper.find('[data-testid="absence-delete-4"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="absence-delete-3"]').attributes('aria-label')).toBe('Delete the absence of the whole household')
	})

	it('lets the owner close a request that was pending before the update', async () => {
		mockUserState.currentUser = { nextcloudUserId: 'pat', role: 'owner' }
		mockUserState.isHouseOwner = true
		mockPausesState.pauses = [absence({ pauseGroupId: 5, revision: 1, state: 'requested', startDate: '2026-10-01', endDate: '2026-10-03' })]
		const wrapper = mountView()
		await flushPromises()

		expect(wrapper.find('[data-testid="absence-pending-5"]').exists()).toBe(true)
		// A request never covered anything: it can be withdrawn whatever its dates.
		expect(wrapper.find('[data-testid="absence-delete-5"]').exists()).toBe(true)
		await wrapper.find('[data-testid="absence-approve-5"]').trigger('click')
		await wrapper.find('[data-testid="absence-reject-5"]').trigger('click')
		await flushPromises()

		expect(mockPausesState.approvePause).toHaveBeenCalledWith(5, 1)
		expect(mockPausesState.rejectPause).toHaveBeenCalledWith(5, 1)
	})

	it('offers the owner a decision but no delete control on a pending shortening of an ended absence', async () => {
		mockUserState.currentUser = { nextcloudUserId: 'pat', role: 'owner' }
		mockUserState.isHouseOwner = true
		// D-090-25: the server reads "ended" from the revision that governs today.
		mockPausesState.pauses = [absence({ pauseGroupId: 5, revision: 2, state: 'requested', startDate: '2026-11-01', endDate: '2026-11-02', deletable: false })]
		const wrapper = mountView()
		await flushPromises()

		expect(wrapper.find('[data-testid="absence-approve-5"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="absence-delete-5"]').exists()).toBe(false)
	})

	it('shows a member their own pending request without decision buttons', async () => {
		mockPausesState.pauses = [absence({ pauseGroupId: 5, state: 'requested' })]
		const wrapper = mountView()
		await flushPromises()

		expect(wrapper.find('[data-testid="absence-pending-5"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="absence-approve-5"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="absence-delete-5"]').exists()).toBe(true)
	})

	it('names a departed subject neutrally', async () => {
		mockPausesState.pauses = [absence({ subjectUserId: null, deletable: false })]
		const wrapper = mountView()
		await flushPromises()

		expect(wrapper.find('.absence-entry-name').text()).toBe('Former member')
		expect(wrapper.find('.absence-delete').exists()).toBe(false)
	})

	it('offers a retry when the list could not be loaded', async () => {
		mockPausesState.fetchPauses.mockRejectedValue(apiError(500))
		mockPausesState.error = 'HTTP 500'
		const wrapper = mountView()
		await flushPromises()

		const errorState = wrapper.findComponent({ name: 'ErrorState' })
		expect(errorState.exists()).toBe(true)
		errorState.vm.$emit('retry')
		expect(mockPausesState.fetchPauses).toHaveBeenCalledTimes(2)
	})

	describe('owner panel', () => {
		beforeEach(() => {
			mockUserState.currentUser = { nextcloudUserId: 'pat', role: 'owner' }
			mockUserState.isHouseOwner = true
		})

		it('enters a household absence without a reason', async () => {
			const wrapper = mountView()
			await wrapper.find('[data-testid="absence-house-start"]').setValue('2026-12-24')
			await wrapper.find('[data-testid="absence-house-end"]').setValue('2027-01-02')
			await wrapper.find('[data-testid="absence-house-form"]').trigger('submit')
			await flushPromises()

			expect(mockPausesState.createHousePause).toHaveBeenCalledWith({ startDate: '2026-12-24', endDate: '2027-01-02' })
			expect(wrapper.find('[data-testid="absence-house-start"]').element.value).toBe('')
		})

		it('enters an absence for someone else afterwards, once a person is chosen', async () => {
			const wrapper = mountView()
			await wrapper.find('[data-testid="absence-later-start"]').setValue('2026-11-03')
			await wrapper.find('[data-testid="absence-later-end"]').setValue('2026-11-05')
			expect(wrapper.find('[data-testid="absence-later-submit"]').attributes('disabled')).toBeDefined()
			expect(wrapper.find('[data-testid="absence-later-end"]').attributes('max')).toBe('2026-11-10')

			await wrapper.find('[data-testid="absence-later-member"]').setValue('mia')
			await wrapper.find('[data-testid="absence-later-form"]').trigger('submit')
			await flushPromises()

			expect(mockPausesState.recognizeIllness).toHaveBeenCalledWith({ subjectUserId: 'mia', startDate: '2026-11-03', endDate: '2026-11-05' })
			expect(showSuccess).toHaveBeenCalledWith('The absence was entered.')
		})

		it('keeps the draft when the entry fails', async () => {
			mockPausesState.createHousePause.mockRejectedValue(apiError(500))
			const wrapper = mountView()
			await wrapper.find('[data-testid="absence-house-start"]').setValue('2026-12-24')
			await wrapper.find('[data-testid="absence-house-end"]').setValue('2027-01-02')
			await wrapper.find('[data-testid="absence-house-form"]').trigger('submit')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('The absence could not be entered.')
			expect(wrapper.find('[data-testid="absence-house-start"]').element.value).toBe('2026-12-24')
		})
	})
})
