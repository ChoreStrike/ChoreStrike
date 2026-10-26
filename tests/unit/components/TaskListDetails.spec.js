/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import TaskListDetails from '../../../src/components/Tasks/TaskListDetails.vue'

jest.mock('@nextcloud/dialogs', () => ({
	showSuccess: jest.fn(),
	showWarning: jest.fn(),
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

const roomTask = (over = {}) => ({
	id: 42,
	roomId: 10,
	taskId: 7,
	isActive: true,
	nextDue: '2999-01-01T00:00:00Z',
	assignees: [],
	...over,
})

const task = (over = {}) => ({
	id: 7,
	name: 'Dishes',
	energyCost: 3,
	flexDays: 0,
	...over,
})

const members = [
	{ nextcloudUserId: 'u1', displayName: 'Alice' },
	{ nextcloudUserId: 'u2', displayName: 'Bob' },
]

const mountDetails = (propsOver = {}) => {
	setActivePinia(createPinia())
	return mount(TaskListDetails, {
		props: {
			item: { roomTask: roomTask(), task: task() },
			members,
			assigneeDraft: ['u1'],
			canManageTasks: true,
			...propsOver,
		},
	})
}

// CS-PLAN-070 WP8 §3.6: the essential agreements/shares help sits on the
// expanded task details.
describe('TaskListDetails contextual help (WP8 §3.6)', () => {
	it('offers the contextual agreements help on the task details', () => {
		const wrapper = mountDetails()
		expect(wrapper.find('[data-testid="help-context-agreements"]').exists()).toBe(true)
	})
})

describe('TaskListDetails (CS-PLAN-067 §3.4)', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it('reuses AssigneeChips without any per-row member fetch', () => {
		const wrapper = mountDetails()
		const chips = wrapper.find('[data-testid="assignee-select"]')
		expect(chips.exists()).toBe(true)
		expect(wrapper.find('[data-testid="assignee-chip-u2"]').exists()).toBe(true)
		// The selected draft chip carries the selected class (interaction parity).
		expect(wrapper.find('[data-testid="assignee-chip-u1"]').classes()).toContain('selected')
	})

	it('hides the picker for children while the checklist stays visible', () => {
		const wrapper = mountDetails({ canManageTasks: false })
		expect(wrapper.find('.assignee-picker').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'TaskChecklist' }).exists()).toBe(true)
	})

	it('keeps the checklist editor owner-gated', () => {
		const ownerWrapper = mountDetails({ isHouseOwner: true })
		expect(ownerWrapper.findComponent({ name: 'TaskChecklist' }).props('canEdit')).toBe(true)

		const memberWrapper = mountDetails({ isHouseOwner: false })
		expect(memberWrapper.findComponent({ name: 'TaskChecklist' }).props('canEdit')).toBe(false)
	})

	it('reports the single toggled member id to the orchestrator', async () => {
		const wrapper = mountDetails()
		await wrapper.find('[data-testid="assignee-chip-u2"]').find('input').setValue(true)
		expect(wrapper.emitted('toggleAssignee')).toEqual([[42, 'u2']])

		const wrapper2 = mountDetails()
		await wrapper2.find('[data-testid="assignee-chip-u1"]').find('input').setValue(false)
		expect(wrapper2.emitted('toggleAssignee')).toEqual([[42, 'u1']])
	})
})

// --- Occurrence agreements (CS-PLAN-070 WP6 §3.5) ---

describe('TaskListDetails occurrence agreements', () => {
	const occurrence = (over = {}) => ({
		occurrenceId: '12:1',
		expectedVersion: 3,
		selectionToken: 'token-42',
		classification: 'agreed',
		kind: 'scheduled',
		plannedDate: '2999-01-01',
		workDate: '2999-01-01',
		timezone: 'Europe/Berlin',
		suspended: false,
		fulfilled: false,
		covered: false,
		...over,
	})

	/** Mount with an occurrence block; the store instance stays reachable
	 * through the active pinia for spying. */
	const mountWithOccurrence = (propsOver = {}, occurrenceOver = {}) => {
		const wrapper = mountDetails({
			isHouseOwner: true,
			item: {
				roomTask: roomTask({ occurrence: occurrence(occurrenceOver) }),
				task: task({ schedulingMode: 'dynamic' }),
			},
			...propsOver,
		})
		const { useSchedulingStore } = require('../../../src/store/scheduling.js')
		return { wrapper, store: useSchedulingStore() }
	}

	beforeEach(() => {
		jest.clearAllMocks()
		setActivePinia(createPinia())
	})

	it('renders the amend entry for owners with an occurrence and hides it from members', () => {
		const { wrapper } = mountWithOccurrence()
		expect(wrapper.find('[data-testid="agreement-amend"]').exists()).toBe(true)

		const memberWrapper = mountDetails({
			isHouseOwner: false,
			item: { roomTask: roomTask({ occurrence: occurrence() }), task: task() },
		})
		expect(memberWrapper.find('[data-testid="occurrence-agreement"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="agreement-handover"]').exists()).toBe(true)
	})

	it('requires a recipient and consent, then records only the one-occurrence handover', async () => {
		const { wrapper, store } = mountWithOccurrence()
		const spy = jest.spyOn(store, 'amendOccurrence').mockResolvedValue({ occurrenceId: '12:1', expectedVersion: 4 })
		const submit = wrapper.find('[data-testid="agreement-handover-submit"]')
		expect(submit.element.disabled).toBe(true)
		await wrapper.find('[data-testid="agreement-handover-member"]').setValue('u2')
		expect(submit.element.disabled).toBe(true)
		await wrapper.find('[data-testid="agreement-consent"]').setValue(true)
		await submit.trigger('click')
		expect(spy).toHaveBeenCalledWith({
			roomTaskId: 42,
			occurrence: occurrence(),
			participants: [{ userId: 'u2', role: 'responsible', sharePct: 100 }],
			consent: true,
		})
		expect(wrapper.emitted('toggleAssignee')).toBeUndefined()
		expect(wrapper.find('[data-testid="agreement-handover-member"]').element.value).toBe('')
		expect(wrapper.find('[data-testid="agreement-consent"]').element.checked).toBe(false)
	})

	it('retains the handover recipient on conflict and does not offer it for fulfilled or suspended work', async () => {
		const { wrapper, store } = mountWithOccurrence()
		jest.spyOn(store, 'amendOccurrence').mockRejectedValue({ status: 409 })
		await wrapper.find('[data-testid="agreement-handover-member"]').setValue('u2')
		await wrapper.find('[data-testid="agreement-consent"]').setValue(true)
		await wrapper.find('[data-testid="agreement-handover-submit"]').trigger('click')
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-testid="agreement-conflict"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="agreement-handover-member"]').element.value).toBe('u2')
		for (const state of [{ fulfilled: true }, { suspended: true }]) {
			const { wrapper: unavailable } = mountWithOccurrence({}, state)
			expect(unavailable.find('[data-testid="agreement-handover"]').exists()).toBe(false)
		}
	})

	it.each(['12:1', '12:2'])('binds conflict drafts and consent to the shown occurrence (%s)', async (nextId) => {
		const { wrapper, store } = mountWithOccurrence()
		jest.spyOn(store, 'amendOccurrence').mockImplementation(async () => {
			await wrapper.setProps({ item: {
				roomTask: roomTask({ occurrence: occurrence({ occurrenceId: nextId, expectedVersion: 4 }) }),
				task: task(),
			} })
			throw { status: 409 }
		})
		await wrapper.find('[data-testid="agreement-amend-date"]').setValue('2999-02-01')
		await wrapper.find('[data-testid="agreement-handover-member"]').setValue('u2')
		await wrapper.find('[data-testid="agreement-consent"]').setValue(true)
		await wrapper.find('[data-testid="agreement-handover-submit"]').trigger('click')
		await new Promise(resolve => setTimeout(resolve, 0))
		const replaced = nextId !== '12:1'
		expect(wrapper.find('[data-testid="agreement-handover-member"]').element.value).toBe(replaced ? '' : 'u2')
		expect(wrapper.find('[data-testid="agreement-amend-date"]').element.value).toBe(replaced ? '' : '2999-02-01')
		expect(wrapper.find('[data-testid="agreement-consent"]').element.checked).toBe(!replaced)
		expect(wrapper.find('[data-testid="agreement-handover-submit"]').element.disabled).toBe(replaced)
		expect(wrapper.find('[data-testid="agreement-replaced"]').exists()).toBe(replaced)
		expect(wrapper.find('[data-testid="agreement-conflict"]').exists()).toBe(!replaced)
	})

	it('sends the amend command with the shown identity, version and consent', async () => {
		const { wrapper, store } = mountWithOccurrence()
		const spy = jest.spyOn(store, 'amendOccurrence').mockResolvedValue({ occurrenceId: '12:1', expectedVersion: 4 })

		await wrapper.find('[data-testid="agreement-amend-date"]').setValue('2999-02-01')
		// Consent gates the submit.
		await wrapper.find('[data-testid="agreement-amend-submit"]').trigger('click')
		expect(spy).not.toHaveBeenCalled()

		await wrapper.find('[data-testid="agreement-consent"]').setValue(true)
		await wrapper.find('[data-testid="agreement-amend-submit"]').trigger('click')

		expect(spy).toHaveBeenCalledTimes(1)
		expect(spy).toHaveBeenCalledWith(expect.objectContaining({
			roomTaskId: 42,
			occurrence: expect.objectContaining({
				occurrenceId: '12:1',
				expectedVersion: 3,
				selectionToken: 'token-42',
			}),
			date: '2999-02-01',
			consent: true,
		}))
	})

	it('shows the catch-up entry only for the current miss (classification late)', async () => {
		const { wrapper } = mountWithOccurrence({}, { classification: 'late' })
		expect(wrapper.find('[data-testid="agreement-catchup"]').exists()).toBe(true)

		const { wrapper: agreedWrapper } = mountWithOccurrence({}, { classification: 'agreed' })
		expect(agreedWrapper.find('[data-testid="agreement-catchup"]').exists()).toBe(false)
	})

	it('sends the catch-up command with the agreed work date and work zone', async () => {
		const { wrapper, store } = mountWithOccurrence({}, { classification: 'late' })
		const spy = jest.spyOn(store, 'catchUpOccurrence').mockResolvedValue({ occurrenceId: '12:1', expectedVersion: 5 })

		await wrapper.find('[data-testid="agreement-catchup-date"]').setValue('2999-02-02')
		await wrapper.find('[data-testid="agreement-consent"]').setValue(true)
		await wrapper.find('[data-testid="agreement-catchup-submit"]').trigger('click')

		expect(spy).toHaveBeenCalledWith(expect.objectContaining({
			roomTaskId: 42,
			occurrence: expect.objectContaining({ occurrenceId: '12:1', expectedVersion: 3 }),
			workDate: '2999-02-02',
			consent: true,
		}))
	})

	it('offers the restart entry only for suspended interval-cadence work', () => {
		const { wrapper } = mountWithOccurrence({}, { suspended: true })
		expect(wrapper.find('[data-testid="agreement-restart"]').exists()).toBe(true)
		// Static cadence tasks never offer the linked restart.
		const { wrapper: staticWrapper } = mountWithOccurrence(
			{ item: { roomTask: roomTask({ occurrence: occurrence({ suspended: true }) }), task: task({ schedulingMode: 'static' }) } },
		)
		expect(staticWrapper.find('[data-testid="agreement-restart"]').exists()).toBe(false)
	})

	it('keeps the draft on a 409 and shows the conflict note', async () => {
		const { wrapper, store } = mountWithOccurrence()
		const conflict = new Error('HTTP 409')
		conflict.status = 409
		jest.spyOn(store, 'amendOccurrence').mockRejectedValue(conflict)

		await wrapper.find('[data-testid="agreement-amend-date"]').setValue('2999-02-01')
		await wrapper.find('[data-testid="agreement-consent"]').setValue(true)
		await wrapper.find('[data-testid="agreement-amend-submit"]').trigger('click')
		await new Promise(resolve => setTimeout(resolve, 0))

		expect(wrapper.find('[data-testid="agreement-conflict"]').exists()).toBe(true)
		// The entered date survived the conflict (draft preservation).
		expect(wrapper.find('[data-testid="agreement-amend-date"]').element.value).toBe('2999-02-01')
		// Consent re-attests for the retry.
		expect(wrapper.find('[data-testid="agreement-consent"]').element.checked).toBe(true)
	})

	// BUG-UX-002: a domain refusal is not "changed in the meantime".
	const amendRejectedWith = async (error) => {
		const { wrapper, store } = mountWithOccurrence()
		jest.spyOn(store, 'amendOccurrence').mockRejectedValue(error)
		await wrapper.find('[data-testid="agreement-amend-date"]').setValue('2999-02-01')
		await wrapper.find('[data-testid="agreement-consent"]').setValue(true)
		await wrapper.find('[data-testid="agreement-amend-submit"]').trigger('click')
		await new Promise(resolve => setTimeout(resolve, 0))
		return wrapper
	}

	it('shows the server reason of a coded refusal inline and keeps the draft', async () => {
		const { showError } = require('@nextcloud/dialogs')
		const wrapper = await amendRejectedWith(Object.assign(
			new Error('This member is not available on that day'),
			{ status: 409, code: 'CONFLICT' },
		))

		const note = wrapper.find('[data-testid="agreement-refused"]')
		expect(note.exists()).toBe(true)
		expect(note.text()).toContain('This member is not available on that day')
		expect(wrapper.find('[data-testid="agreement-conflict"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="agreement-amend-date"]').element.value).toBe('2999-02-01')
		expect(wrapper.find('[data-testid="agreement-consent"]').element.checked).toBe(true)
		expect(showError).not.toHaveBeenCalled()
	})

	it('keeps the changed-in-the-meantime note for a stale target', async () => {
		const wrapper = await amendRejectedWith(Object.assign(
			new Error('The task changed since it was displayed. Reload and try again.'),
			{ status: 409, code: 'STALE_TARGET' },
		))

		expect(wrapper.find('[data-testid="agreement-conflict"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="agreement-refused"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="agreement-amend-date"]').element.value).toBe('2999-02-01')
	})
})
