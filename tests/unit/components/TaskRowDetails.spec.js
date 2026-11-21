/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { beforeEach, describe, expect, it, jest } from '@jest/globals'
import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import TaskRowDetails from '../../../src/components/Tasks/TaskRowDetails.vue'
import { showRefusal } from '../../../src/utils/errorToast.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, params) => Object.entries(params ?? {})
		.reduce((label, [key, value]) => label.replaceAll(`{${key}}`, String(value)), text)),
}))
jest.mock('@/utils/logger', () => ({ logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() } }))
jest.mock('../../../src/utils/errorToast.js', () => ({ showRefusal: jest.fn() }))

let mockChecklistStore
let mockUserStore
let mockRoomTasksStore
jest.mock('../../../src/store/checklist.js', () => ({ useChecklistStore: () => mockChecklistStore }))
jest.mock('../../../src/store/user.js', () => ({ useUserStore: () => mockUserStore }))
jest.mock('../../../src/store/roomTasks.js', () => ({ useRoomTasksStore: () => mockRoomTasksStore }))
jest.mock('../../../src/store/house.js', () => ({ useHouseStore: () => ({ timezone: 'UTC' }) }))

// The parts of the opened row have specs of their own.
jest.mock('../../../src/components/Tasks/PostponeButton.vue', () => ({
	name: 'PostponeButton',
	props: ['roomTaskId', 'cycle', 'flexDays'],
	template: '<div data-testid="postpone-stub" />',
}))
jest.mock('../../../src/components/Tasks/SleepControl.vue', () => ({
	name: 'SleepControl',
	props: ['roomTaskId', 'cycle'],
	template: '<div data-testid="sleep-stub" />',
}))
jest.mock('../../../src/components/Tasks/TaskHistory.vue', () => ({
	name: 'TaskHistory',
	props: ['roomTaskId'],
	template: '<div data-testid="history-stub" />',
}))

const roomTask = (over = {}) => ({ id: 11, taskId: 1, roomId: 1, isActive: true, lastCompleted: null, ...over })
const task = (over = {}) => ({ id: 1, name: 'Do the dishes', frequencyType: 'daily', frequencyValue: 3, flexDays: 2, ...over })
const open = { cycleKey: 'c9', expectedVersion: 2, state: 'open', windowStartOn: '2026-11-18', windowEndOn: '2026-11-20' }

/**
 * The opened part of the one task row (CS-PLAN-091 WP-B6, FEAT-UX-050):
 * facts, sub-tasks to tick, the row's actions and the history.
 */
describe('TaskRowDetails', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		mockChecklistStore = reactive({
			items: {},
			progress: {},
			getTaskItems: (taskId) => mockChecklistStore.items[taskId] || [],
			getRoomTaskProgress: (roomTaskId) => mockChecklistStore.progress[roomTaskId] || [],
			fetchTaskItems: jest.fn(async (taskId) => mockChecklistStore.items[taskId] || []),
			fetchRoomTaskProgress: jest.fn().mockResolvedValue([]),
			toggleItem: jest.fn().mockResolvedValue({}),
		})
		mockUserStore = reactive({ canManageTasks: true, houseUsers: [{ nextcloudUserId: 'mom', displayName: 'Parent' }] })
		mockRoomTasksStore = reactive({
			justCompletedIds: {},
			wasJustCompleted: (id) => mockRoomTasksStore.justCompletedIds[id] === true,
		})
	})

	const mountDetails = async (props = {}) => {
		const wrapper = mount(TaskRowDetails, { props: { roomTask: roomTask(), task: task(), cycle: open, assignees: ['mom'], ...props } })
		await flushPromises()
		return wrapper
	}
	const fact = (wrapper, label) => {
		const terms = wrapper.findAll('dt')
		const index = terms.findIndex((term) => term.text() === label)
		return wrapper.findAll('dd')[index].text()
	}

	describe('facts', () => {
		it('shows rhythm, window, last done and the assigned people', async () => {
			const wrapper = await mountDetails({ roomTask: roomTask({ lastCompleted: '2026-11-15T09:00:00Z' }) })

			expect(fact(wrapper, 'Rhythm')).toBe('Every 3 days ±2')
			expect(wrapper.get('[data-testid="task-row-window"]').text()).toMatch(/18.* – .*20/)
			expect(fact(wrapper, 'Last done')).toMatch(/15/)
			expect(fact(wrapper, 'Assigned')).toBe('Parent')
		})

		it('says what is not there yet', async () => {
			const wrapper = await mountDetails({ cycle: null, assignees: [] })

			expect(wrapper.get('[data-testid="task-row-window"]').text()).toBe('—')
			expect(fact(wrapper, 'Last done')).toBe('Not done yet')
			expect(fact(wrapper, 'Assigned')).toBe('Unassigned')
		})

		it('shows a one-day window once, a sleeping task as asleep and a bonus task as such', async () => {
			expect((await mountDetails({ cycle: { ...open, windowEndOn: '2026-11-18' } })).get('[data-testid="task-row-window"]').text()).not.toContain('–')
			expect((await mountDetails({ cycle: { state: 'asleep', expectedVersion: 3, wakeOn: null } })).get('[data-testid="task-row-window"]').text()).toBe('Asleep')
			expect(fact(await mountDetails({ task: task({ isVoluntary: true }) }), 'Rhythm')).toBe('Bonus task')
		})

		it('shows description and instructions as plain text, and no paragraph without them', async () => {
			const wrapper = await mountDetails({ task: task({ description: 'Short <b>note</b>', instructions: 'Rinse first.' }) })

			expect(wrapper.get('.task-text').text()).toBe('Short <b>note</b>\n\nRinse first.')
			expect(wrapper.find('.task-text b').exists()).toBe(false)
			expect((await mountDetails()).find('.task-text').exists()).toBe(false)
		})
	})

	describe('sub-tasks', () => {
		it('reads the sub-tasks and the ticks of the cycle when it opens', async () => {
			mockChecklistStore.items[1] = [{ id: 77, label: 'Rinse plates', sortOrder: 1 }]

			await mountDetails()

			expect(mockChecklistStore.fetchTaskItems).toHaveBeenCalledWith(1)
			expect(mockChecklistStore.fetchRoomTaskProgress).toHaveBeenCalledWith(11)
		})

		it('asks for no ticks of a task without sub-tasks', async () => {
			await mountDetails()

			expect(mockChecklistStore.fetchTaskItems).toHaveBeenCalledWith(1)
			expect(mockChecklistStore.fetchRoomTaskProgress).not.toHaveBeenCalled()
		})

		it('asks nothing when the row already knows that the task has no sub-tasks', async () => {
			const wrapper = await mountDetails({ subTaskCount: 0 })

			expect(mockChecklistStore.fetchTaskItems).not.toHaveBeenCalled()
			expect(mockChecklistStore.fetchRoomTaskProgress).not.toHaveBeenCalled()
			expect(wrapper.find('[data-testid="task-checklist"]').exists()).toBe(false)

			// A row that counts some reads them.
			await mountDetails({ subTaskCount: 2 })
			expect(mockChecklistStore.fetchTaskItems).toHaveBeenCalledWith(1)
		})

		it('shows the execute-only checklist only when the task has sub-tasks', async () => {
			expect((await mountDetails()).find('[data-testid="task-checklist"]').exists()).toBe(false)

			mockChecklistStore.items[1] = [{ id: 77, label: 'Rinse plates', sortOrder: 1 }]
			mockChecklistStore.progress[11] = [{ checklistItemId: 77, isChecked: true }]
			const wrapper = await mountDetails()

			expect(wrapper.get('[data-testid="checklist-item-77"] input').element.checked).toBe(true)
			expect(wrapper.get('[data-testid="checklist-item-77"] input').element.disabled).toBe(false)
		})

		it('ticks a sub-task and shows why a tick was refused', async () => {
			mockChecklistStore.items[1] = [{ id: 77, label: 'Rinse plates', sortOrder: 1 }]
			const wrapper = await mountDetails()

			await wrapper.get('[data-testid="checklist-item-77"] input').setValue(true)
			expect(mockChecklistStore.toggleItem).toHaveBeenCalledWith(11, 77)

			const conflict = Object.assign(new Error('Conflict'), { status: 409, code: 'CONFLICT' })
			mockChecklistStore.toggleItem.mockRejectedValueOnce(conflict)
			await wrapper.get('[data-testid="checklist-item-77"] input').setValue(false)
			await flushPromises()
			expect(showRefusal).toHaveBeenCalledWith(conflict, 'Could not update the checklist')
		})

		it('shows the ticks of a finished task without taking new ones', async () => {
			mockChecklistStore.items[1] = [{ id: 77, label: 'Rinse plates', sortOrder: 1 }]

			const wrapper = await mountDetails({ roomTask: roomTask({ isActive: false }) })

			expect(wrapper.get('[data-testid="checklist-item-77"] input').element.disabled).toBe(true)
		})

		it('says when the sub-tasks could not be read, still opens, and reads them again on retry', async () => {
			mockChecklistStore.fetchRoomTaskProgress.mockRejectedValueOnce(new Error('offline'))
			mockChecklistStore.items[1] = [{ id: 77, label: 'Rinse plates', sortOrder: 1 }]

			const wrapper = await mountDetails()

			expect(wrapper.text()).toContain('Could not load the sub-tasks')
			expect(wrapper.find('[data-testid="checklist-item-77"]').exists()).toBe(false)
			expect(wrapper.find('[data-testid="history-stub"]').exists()).toBe(true)

			await wrapper.getComponent({ name: 'ErrorState' }).vm.$emit('retry')
			await flushPromises()
			expect(wrapper.text()).not.toContain('Could not load the sub-tasks')
			expect(wrapper.find('[data-testid="checklist-item-77"]').exists()).toBe(true)
		})

		it('reads the ticks again when the open row was just completed: the next cycle starts without them', async () => {
			mockChecklistStore.items[1] = [{ id: 77, label: 'Rinse plates', sortOrder: 1 }]
			await mountDetails()
			mockChecklistStore.fetchRoomTaskProgress.mockClear()

			mockRoomTasksStore.justCompletedIds = { 11: true }
			await flushPromises()
			expect(mockChecklistStore.fetchRoomTaskProgress).toHaveBeenCalledWith(11)

			mockRoomTasksStore.justCompletedIds = { 12: true }
			await flushPromises()
			expect(mockChecklistStore.fetchRoomTaskProgress).toHaveBeenCalledTimes(1)
		})
	})

	describe('actions', () => {
		it('offers postpone, for someone else, edit and the wake-up of a sleeping task on an active task for adults', async () => {
			const wrapper = await mountDetails()

			expect(wrapper.getComponent({ name: 'PostponeButton' }).props()).toEqual({ roomTaskId: 11, cycle: open, flexDays: 2 })
			expect(wrapper.getComponent({ name: 'SleepControl' }).props()).toEqual({ roomTaskId: 11, cycle: open })
			await wrapper.get('[data-testid="task-record-other"]').trigger('click')
			await wrapper.get('[data-testid="task-edit-button"]').trigger('click')

			expect(wrapper.emitted('record')).toHaveLength(1)
			expect(wrapper.emitted('edit')).toHaveLength(1)
		})

		it('leaves edit and the wake-up out for a child', async () => {
			mockUserStore.canManageTasks = false
			const wrapper = await mountDetails()

			expect(wrapper.find('[data-testid="task-record-other"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="task-edit-button"]').exists()).toBe(false)
			expect(wrapper.findComponent({ name: 'SleepControl' }).exists()).toBe(false)
		})

		it('offers no action on a finished task, and its history still', async () => {
			const wrapper = await mountDetails({ roomTask: roomTask({ isActive: false }) })

			expect(wrapper.find('.row-actions').exists()).toBe(false)
			expect(wrapper.getComponent({ name: 'TaskHistory' }).props('roomTaskId')).toBe(11)
		})

		it('hands a task without flex days on as such', async () => {
			const wrapper = await mountDetails({ task: task({ flexDays: undefined }) })

			expect(wrapper.getComponent({ name: 'PostponeButton' }).props('flexDays')).toBe(0)
		})
	})
})
