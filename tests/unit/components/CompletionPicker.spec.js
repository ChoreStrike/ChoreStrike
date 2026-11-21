/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { beforeEach, describe, expect, it, jest } from '@jest/globals'
import { flushPromises, mount } from '@vue/test-utils'
import CompletionPicker from '../../../src/components/Tasks/CompletionPicker.vue'
import { showRefusal } from '../../../src/utils/errorToast.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@/utils/logger', () => ({
	logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() },
}))
jest.mock('../../../src/utils/errorToast.js', () => ({ showRefusal: jest.fn() }))
jest.mock('@/components/Common/Modal.vue', () => ({
	name: 'Modal',
	props: ['ariaLabel'],
	template: '<div class="modal-stub"><slot /></div>',
}))
jest.mock('@/components/Tasks/TaskChecklist.vue', () => ({
	name: 'TaskChecklist',
	props: ['items', 'progress', 'canEdit', 'roomTaskId', 'taskId'],
	emits: ['toggle'],
	template: '<div data-testid="checklist-stub" />',
}))

let mockChecklistStore
let mockUserStore
jest.mock('../../../src/store/checklist.js', () => ({ useChecklistStore: () => mockChecklistStore }))
jest.mock('../../../src/store/user.js', () => ({ useUserStore: () => mockUserStore }))

/**
 * The picker of a short tap on Done (CS-PLAN-091 WP-B2): who did the work
 * and, for a task with sub-tasks, which of them are done.
 */
describe('CompletionPicker', () => {
	const mountPicker = async (props = {}) => {
		const wrapper = mount(CompletionPicker, { props: { roomTaskId: 11, taskId: 3, assignees: ['kid'], ...props } })
		await flushPromises()
		return wrapper
	}
	const dialog = (wrapper) => wrapper.findComponent({ name: 'ParticipationDialog' })

	beforeEach(() => {
		jest.clearAllMocks()
		mockChecklistStore = {
			items: {},
			getTaskItems: jest.fn((taskId) => mockChecklistStore.items[taskId] ?? []),
			getRoomTaskProgress: jest.fn(() => [{ checklistItemId: 77, isChecked: true }]),
			fetchTaskItems: jest.fn(async (taskId) => mockChecklistStore.items[taskId] ?? []),
			fetchRoomTaskProgress: jest.fn().mockResolvedValue({}),
			toggleItem: jest.fn().mockResolvedValue({}),
		}
		mockUserStore = {
			currentUser: { nextcloudUserId: 'mom' },
			houseUsers: [
				{ nextcloudUserId: 'mom', displayName: 'Mom' },
				{ nextcloudUserId: 'kid', displayName: 'Kid' },
				{ nextcloudUserId: 'guest', displayName: null },
			],
		}
	})

	it('offers the whole household and ticks who a hold would credit', async () => {
		const wrapper = await mountPicker()

		expect(dialog(wrapper).props('members')).toEqual([
			{ userId: 'mom', displayName: 'Mom' },
			{ userId: 'kid', displayName: 'Kid' },
			{ userId: 'guest', displayName: 'guest' },
		])
		expect(dialog(wrapper).props('preselected')).toEqual(['kid'])
	})

	it('ticks the recorder for unassigned work', async () => {
		expect(dialog(await mountPicker({ assignees: [] })).props('preselected')).toEqual(['mom'])
	})

	it('reads the people first where the view has not, then ticks who a hold would credit', async () => {
		const household = mockUserStore.houseUsers
		mockUserStore.currentUser = null
		mockUserStore.houseUsers = []
		let release
		mockUserStore.initializeCurrentUser = jest.fn(() => new Promise((resolve) => { release = resolve }).then(() => {
			mockUserStore.currentUser = { nextcloudUserId: 'mom' }
		}))
		mockUserStore.fetchHouseUsers = jest.fn(async () => { mockUserStore.houseUsers = household })

		const wrapper = mount(CompletionPicker, { props: { roomTaskId: 11, taskId: 3, assignees: [] } })
		await flushPromises()
		// The dialog builds its rows once, so it waits for the people.
		expect(dialog(wrapper).exists()).toBe(false)

		release()
		await flushPromises()
		expect(dialog(wrapper).props('members')).toHaveLength(3)
		expect(dialog(wrapper).props('preselected')).toEqual(['mom'])
	})

	it('opens with what there is when the people cannot be read', async () => {
		mockUserStore.houseUsers = []
		mockUserStore.fetchHouseUsers = jest.fn().mockRejectedValue(new Error('offline'))

		expect(dialog(await mountPicker()).exists()).toBe(true)
	})

	it('hands the confirmed split on and closes on request', async () => {
		const wrapper = await mountPicker()
		const split = { participants: [{ userId: 'kid', sharePct: 100 }], allocationReviewed: true }

		dialog(wrapper).vm.$emit('confirm', split)
		dialog(wrapper).vm.$emit('close')

		expect(wrapper.emitted('confirm')).toEqual([[split]])
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('shows no sub-task section for a task without sub-tasks and reads no ticks for it', async () => {
		const wrapper = await mountPicker()

		expect(mockChecklistStore.fetchTaskItems).toHaveBeenCalledWith(3)
		expect(mockChecklistStore.fetchRoomTaskProgress).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="picker-subtasks"]').exists()).toBe(false)
	})

	it('reads nothing for a task known to have no sub-tasks to tick', async () => {
		mockChecklistStore.items[3] = [{ id: 77, label: 'Carpet' }]
		const wrapper = await mountPicker({ withoutSubTasks: true })

		expect(mockChecklistStore.fetchTaskItems).not.toHaveBeenCalled()
		expect(mockChecklistStore.fetchRoomTaskProgress).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="picker-subtasks"]').exists()).toBe(false)
	})

	it('hands on who cannot be credited, with the reason', async () => {
		const wrapper = await mountPicker({ unavailable: ['kid'], unavailableNote: 'counted today' })

		expect(dialog(wrapper).props()).toMatchObject({ unavailable: ['kid'], unavailableNote: 'counted today' })
		expect(wrapper.get('[data-testid="participant-active-kid"]').element.disabled).toBe(true)
		expect(wrapper.get('[data-testid="participant-active-kid"]').element.checked).toBe(false)
		expect(wrapper.get('[data-testid="participant-row-kid"]').text()).toContain('counted today')
		expect(wrapper.get('[data-testid="participant-active-mom"]').element.disabled).toBe(false)
	})

	it('shows the sub-tasks with their ticks and saves a tick at once', async () => {
		mockChecklistStore.items[3] = [{ id: 77, label: 'Carpet' }, { id: 78, label: 'Under the sofa' }]
		const wrapper = await mountPicker()

		const checklist = wrapper.findComponent({ name: 'TaskChecklist' })
		expect(mockChecklistStore.fetchRoomTaskProgress).toHaveBeenCalledWith(11)
		expect(checklist.props()).toMatchObject({ roomTaskId: 11, progress: [{ checklistItemId: 77, isChecked: true }] })
		// Nothing the ticks-only checklist does not declare falls through to its markup.
		expect(checklist.attributes('taskid')).toBeUndefined()
		expect(checklist.attributes('canedit')).toBeUndefined()
		expect(wrapper.get('[data-testid="picker-subtasks-note"]').text()).toContain('completes the task with every sub-task')

		checklist.vm.$emit('toggle', { itemId: 78, roomTaskId: 11 })
		await flushPromises()

		expect(mockChecklistStore.toggleItem).toHaveBeenCalledWith(11, 78)
		// A tick alone completes nothing: the task stays open (D-089-02).
		expect(wrapper.emitted('confirm')).toBeUndefined()
	})

	it('shows why the server refused a tick, as the opened row does, and keeps the picker open', async () => {
		mockChecklistStore.items[3] = [{ id: 77, label: 'Carpet' }]
		const failure = Object.assign(new Error('stale'), { status: 409, code: 'STALE_TARGET' })
		mockChecklistStore.toggleItem.mockRejectedValue(failure)
		const wrapper = await mountPicker()

		wrapper.findComponent({ name: 'TaskChecklist' }).vm.$emit('toggle', { itemId: 77, roomTaskId: 11 })
		await flushPromises()

		expect(showRefusal).toHaveBeenCalledWith(failure, 'Could not update the checklist')
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('still lets the people be picked when the sub-tasks do not load', async () => {
		mockChecklistStore.fetchTaskItems.mockRejectedValue(new Error('offline'))

		expect(dialog(await mountPicker()).exists()).toBe(true)
	})
})
