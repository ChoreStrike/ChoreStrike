/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { beforeEach, describe, expect, it, jest } from '@jest/globals'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import TaskRow from '../../../src/components/Tasks/TaskRow.vue'
import { useRoomsStore } from '../../../src/store/rooms.js'
import { useRoomTasksStore } from '../../../src/store/roomTasks.js'
import { useUserStore } from '../../../src/store/user.js'
import { confirmHold, tapHold } from '../helpers/hold.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, params) => Object.entries(params ?? {})
		.reduce((label, [key, value]) => label.replaceAll(`{${key}}`, String(value)), text)),
}))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@/utils/logger', () => ({ logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() } }))

// The opened part, the picker and the form have specs of their own.
jest.mock('../../../src/components/Tasks/TaskRowDetails.vue', () => ({
	name: 'TaskRowDetails',
	props: ['roomTask', 'task', 'cycle', 'assignees'],
	emits: ['record', 'edit'],
	template: '<div data-testid="details-stub" />',
}))
jest.mock('../../../src/components/Tasks/CompletionPicker.vue', () => ({
	name: 'CompletionPicker',
	props: ['roomTaskId', 'taskId', 'assignees', 'withoutSubTasks'],
	emits: ['close', 'confirm'],
	template: '<div data-testid="picker-stub" />',
}))
jest.mock('../../../src/components/Tasks/TaskForm.vue', () => ({
	name: 'TaskForm',
	props: ['item', 'prefill'],
	emits: ['close', 'saved'],
	template: '<div data-testid="form-stub" />',
}))

const dayKey = (offset) => new Date(Date.now() + offset * 86400000).toISOString().slice(0, 10)

function item(overrides = {}) {
	return {
		roomTask: { id: 11, taskId: 1, roomId: 1, isActive: true, nextDue: '2999-01-01T00:00:00Z', assignees: ['kid'] },
		task: { id: 1, name: 'Do the dishes', energyCost: 3, frequencyType: 'daily', frequencyValue: 3, flexDays: 2, visibilityType: 'public' },
		room: { id: 1, name: 'Kitchen', floorId: 3 },
		assignees: ['mom'],
		checklistItemCount: 0,
		...overrides,
	}
}

/**
 * The one task row of Today and the task list (CS-PLAN-091 WP-B6,
 * FEAT-UX-040): it carries what TodayTaskRow and TaskListItem showed.
 */
describe('TaskRow', () => {
	let roomTasksStore

	beforeEach(() => {
		setActivePinia(createPinia())
		roomTasksStore = useRoomTasksStore()
		useRoomsStore().floors = [{ id: 3, name: 'Upstairs' }]
		useUserStore().houseUsers = [{ nextcloudUserId: 'mom', displayName: 'Parent' }, { nextcloudUserId: 'kid', displayName: 'Kid' }]
	})

	const mountRow = (props = {}) => mount(TaskRow, { props: { item: item(), ...props } })
	const doneButton = (wrapper) => wrapper.get('[data-testid="today-complete-button-11"]')

	describe('facts', () => {
		it('shows name, energy, recurrence with its flex days, floor and room, and people', () => {
			const wrapper = mountRow()

			expect(wrapper.get('.task-name').text()).toBe('Do the dishes')
			expect(wrapper.get('[data-testid="task-energy"]').text()).toMatch(/3$/)
			expect(wrapper.get('[data-testid="task-recurrence"]').text()).toContain('Every 3 days ±2')
			expect(wrapper.get('[data-testid="task-location"]').text()).toBe('Upstairs · Kitchen')
			expect(wrapper.get('[data-testid="task-assignees"]').text()).toContain('Assigned to: Parent')
		})

		it('names the assignees of the row before those of its room task, and unassigned work as such', () => {
			expect(mountRow({ item: item({ assignees: undefined }) }).get('[data-testid="task-assignees"]').text()).toContain('Assigned to: Kid')
			expect(mountRow({ item: item({ assignees: [] }) }).get('[data-testid="task-assignees"]').text()).toContain('Unassigned')
		})

		it('finds the room of a list row in the rooms store', () => {
			useRoomsStore().rooms = [{ id: 1, name: 'Bathroom', floorId: 3 }]

			expect(mountRow({ item: item({ room: undefined }), variant: 'list' }).get('[data-testid="task-location"]').text()).toBe('Upstairs · Bathroom')
		})

		it('promises no teamwork bonus, no estimated time and no priority badge (D-091-85)', () => {
			const wrapper = mountRow({ item: item({ task: { ...item().task, estimatedMinutes: 15, priority: 3 } }) })

			expect(wrapper.text()).not.toContain('Teamwork bonus')
			expect(wrapper.text()).not.toContain('15 min')
			expect(wrapper.find('.priority-badge').exists()).toBe(false)
		})

		it('keeps the description and instructions for the opened row', () => {
			const wrapper = mountRow({ item: item({ task: { ...item().task, instructions: 'Rinse first.', description: 'Short note' } }) })

			expect(wrapper.text()).not.toContain('Rinse first.')
			expect(wrapper.text()).not.toContain('Short note')
		})

		it.each([
			['private', 'visibility-lock-icon'],
			['admin-only', 'visibility-crown-icon'],
		])('marks a %s task with its icon only', (visibilityType, testid) => {
			const wrapper = mountRow({ item: item({ task: { ...item().task, visibilityType } }) })

			expect(wrapper.findAll('[data-testid^="visibility-"]').map((icon) => icon.attributes('data-testid'))).toEqual([testid])
		})

		it('shows no visibility icon on a public task', () => {
			expect(mountRow().find('[data-testid^="visibility-"]').exists()).toBe(false)
		})
	})

	describe('Done', () => {
		it('completes the row on a confirmed hold and names the task on the button', async () => {
			const row = item()
			const wrapper = mountRow({ item: row })

			expect(doneButton(wrapper).attributes('aria-label')).toBe('Mark “Do the dishes” as done')
			await confirmHold(wrapper, '[data-testid="today-complete-button-11"]')

			expect(wrapper.emitted('complete')).toEqual([[row]])
			expect(wrapper.find('[data-testid="picker-stub"]').exists()).toBe(false)
		})

		it('does not complete on a plain click', async () => {
			const wrapper = mountRow()

			await doneButton(wrapper).trigger('click')

			expect(wrapper.emitted('complete')).toBeUndefined()
		})

		it('opens the picker on a short tap, and its split completes the row', async () => {
			const row = item({ checklistItemCount: 2 })
			const wrapper = mountRow({ item: row })

			await tapHold(wrapper, '[data-testid="today-complete-button-11"]')

			expect(wrapper.emitted('complete')).toBeUndefined()
			const picker = wrapper.getComponent({ name: 'CompletionPicker' })
			expect(picker.props()).toEqual({ roomTaskId: 11, taskId: 1, assignees: ['mom'], withoutSubTasks: false })

			const split = { participants: [{ userId: 'mom', sharePct: 100 }], allocationReviewed: true }
			picker.vm.$emit('confirm', split)
			await flushPromises()

			expect(wrapper.emitted('complete')).toEqual([[row, split]])
			expect(wrapper.findComponent({ name: 'CompletionPicker' }).exists()).toBe(false)
		})

		it('tells the picker that a row without sub-tasks has none to ask for', async () => {
			const wrapper = mountRow()

			await tapHold(wrapper, '[data-testid="today-complete-button-11"]')

			expect(wrapper.getComponent({ name: 'CompletionPicker' }).props('withoutSubTasks')).toBe(true)
		})

		it('completes nothing when the picker closes', async () => {
			const wrapper = mountRow()
			await tapHold(wrapper, '[data-testid="today-complete-button-11"]')

			wrapper.getComponent({ name: 'CompletionPicker' }).vm.$emit('close')
			await flushPromises()

			expect(wrapper.emitted('complete')).toBeUndefined()
			expect(wrapper.findComponent({ name: 'CompletionPicker' }).exists()).toBe(false)
		})

		it('marks the button busy while the completion is in flight', async () => {
			const wrapper = mountRow()
			roomTasksStore.completingIds = { 11: true }
			await wrapper.vm.$nextTick()

			expect(doneButton(wrapper).attributes('disabled')).toBeDefined()
			expect(doneButton(wrapper).attributes('aria-busy')).toBe('true')
		})

		it('uses the list testids in the task list', () => {
			const wrapper = mountRow({ variant: 'list' })

			expect(wrapper.find('[data-testid="task-item"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="complete-task-button"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="today-task-item"]').exists()).toBe(false)
		})
	})

	describe('done rows', () => {
		it('shows a finished one-time row with its check mark instead of the button', () => {
			const wrapper = mountRow({ item: item({ roomTask: { ...item().roomTask, isActive: false } }) })

			expect(wrapper.find('[data-testid="today-completed-check-11"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="today-complete-button-11"]').exists()).toBe(false)
			expect(wrapper.get('[data-testid="today-task-item"]').classes()).toContain('completed')
			expect(wrapper.get('[data-testid="today-task-item"]').classes()).not.toContain('cs-just-completed')
		})

		it('shows the check mark and the feedback while a recurring row was just completed (D-090-30)', async () => {
			const wrapper = mountRow()
			const row = wrapper.get('[data-testid="today-task-item"]')
			expect(row.classes()).not.toContain('cs-just-completed')

			roomTasksStore.justCompletedIds = { 11: true }
			await wrapper.vm.$nextTick()

			expect(row.classes()).toEqual(expect.arrayContaining(['cs-just-completed', 'completed']))
			expect(wrapper.find('[data-testid="today-completed-check-11"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="today-complete-button-11"]').exists()).toBe(false)

			roomTasksStore.justCompletedIds = {}
			await wrapper.vm.$nextTick()

			expect(wrapper.find('[data-testid="today-complete-button-11"]').exists()).toBe(true)
		})
	})

	/** The cycle block of a row planned `planned` days from today whose window ends `end` days from today. */
	const cycle = (planned, end = planned) => ({
		cycleKey: 'c1',
		expectedVersion: 0,
		state: end < 0 ? 'overdue' : 'open',
		centreOn: dayKey(planned),
		windowStartOn: dayKey(planned),
		windowEndOn: dayKey(end),
	})

	describe('an overdue row', () => {
		const overdue = (block) => mountRow({ variant: 'overdue', item: item({ cycle: block }) })

		it('shows the planned day and the days since its window ended', () => {
			const wrapper = overdue(cycle(-3))

			expect(wrapper.get('[data-testid="today-task-item"]').classes()).toContain('overdue')
			expect(wrapper.get('[data-testid="catchup-planned"]').text()).toMatch(/^planned /)
			expect(wrapper.get('[data-testid="catchup-planned"]').text()).not.toContain('until')
			expect(wrapper.get('[data-testid="today-overdue-days"]').text()).toBe('3 days')
		})

		it('names the end of a window that reached past the planned day and counts from it', () => {
			const wrapper = overdue(cycle(-3, -1))

			expect(wrapper.get('[data-testid="catchup-planned"]').text()).toMatch(/^planned .+, until .+/)
			expect(wrapper.get('[data-testid="today-overdue-days"]').text()).toBe('1 day')
		})

		it('shows no day count for a window that ended today', () => {
			expect(overdue(cycle(0)).find('[data-testid="today-overdue-days"]').exists()).toBe(false)
		})

		it('reads the cycle from the room task when the row carries none', () => {
			const wrapper = mountRow({ variant: 'overdue', item: item({ roomTask: { ...item().roomTask, cycle: cycle(-2) } }) })

			expect(wrapper.get('[data-testid="today-overdue-days"]').text()).toBe('2 days')
		})

		it('shows neither line nor count without a cycle block', () => {
			const wrapper = mountRow({ variant: 'overdue' })

			expect(wrapper.find('[data-testid="catchup-planned"]').exists()).toBe(false)
			expect(wrapper.find('[data-testid="today-overdue-days"]').exists()).toBe(false)
		})

		it('marks a list row whose cycle is overdue, and no list row of an open window', () => {
			const overdueRow = mountRow({ variant: 'list', item: item({ cycle: { state: 'overdue', windowEndOn: dayKey(-2) } }) })
			const openRow = mountRow({ variant: 'list', item: item({ cycle: cycle(-1, 1) }) })

			expect(overdueRow.get('[data-testid="task-item"]').classes()).toContain('overdue')
			expect(overdueRow.find('[data-testid="today-overdue-days"]').exists()).toBe(false)
			expect(openRow.get('[data-testid="task-item"]').classes()).not.toContain('overdue')
		})

		// Every row is a card (the operator's GUI review of WP-B6): the card carries the red frame.
		it('marks the card of an overdue row, on Today and in the list, and of no other row', () => {
			const card = (wrapper) => wrapper.get('.task-row-group').classes()

			expect(card(overdue(cycle(-3)))).toEqual(expect.arrayContaining(['overdue']))
			// The list's cards keep their own class: Today's inset inside the red block is not theirs.
			expect(card(mountRow({ variant: 'list', item: item({ cycle: { state: 'overdue', windowEndOn: dayKey(-2) } }) })))
				.toEqual(expect.arrayContaining(['list', 'overdue']))
			expect(card(mountRow({ variant: 'list', item: item({ cycle: cycle(-1, 1) }) }))).not.toContain('overdue')
			expect(card(mountRow({ item: item({ cycle: cycle(0) }) }))).not.toContain('overdue')
			expect(card(mountRow({ variant: 'possible', item: item({ cycle: cycle(1, 2) }) }))).not.toContain('overdue')
		})

		it('marks a list row without a cycle block as its due text reads it, from the stored status', () => {
			const wrapper = mountRow({ variant: 'list', item: item({ roomTask: { ...item().roomTask, status: 'overdue' } }) })

			expect(wrapper.get('[data-testid="task-item"]').classes()).toContain('overdue')
			expect(wrapper.get('.task-due').classes()).toContain('overdue')
		})
	})

	it('names the planned day of a row that is only possible today, and no day for a due or list row', () => {
		const block = cycle(1, 2)
		const possible = mountRow({ variant: 'possible', item: item({ cycle: block }) })

		expect(possible.get('[data-testid="today-open-window-planned-11"]').text()).toMatch(/^planned /)
		expect(possible.get('[data-testid="today-open-window-planned-11"]').text()).not.toContain('until')
		expect(possible.find('[data-testid="today-overdue-days"]').exists()).toBe(false)
		expect(mountRow({ item: item({ cycle: block }) }).find('.task-planned').exists()).toBe(false)
		expect(mountRow({ variant: 'list', item: item({ cycle: block }) }).find('.task-planned').exists()).toBe(false)
	})

	it('shows the due text in the task list only', () => {
		expect(mountRow({ variant: 'list' }).find('.task-due').exists()).toBe(true)
		expect(mountRow().find('.task-due').exists()).toBe(false)
	})

	describe('opening in place', () => {
		const expand = (wrapper) => wrapper.get('[data-testid="task-expand"]')

		it('opens and closes the details with a named, controlled toggle', async () => {
			const wrapper = mountRow()
			expect(expand(wrapper).attributes('aria-expanded')).toBe('false')
			expect(expand(wrapper).attributes('aria-label')).toBe('Details of “Do the dishes”')
			expect(wrapper.find('[data-testid="details-stub"]').exists()).toBe(false)

			await expand(wrapper).trigger('click')

			expect(expand(wrapper).attributes('aria-expanded')).toBe('true')
			const details = wrapper.getComponent({ name: 'TaskRowDetails' })
			expect(details.attributes('id')).toBe(expand(wrapper).attributes('aria-controls'))
			expect(details.props()).toEqual({ roomTask: item().roomTask, task: item().task, cycle: null, assignees: ['mom'] })
			expect(wrapper.get('.task-row-group').classes()).toContain('open')

			await expand(wrapper).trigger('click')
			expect(wrapper.find('[data-testid="details-stub"]').exists()).toBe(false)
		})

		it('opens the picker for someone else from the details', async () => {
			const wrapper = mountRow()
			await wrapper.get('[data-testid="task-expand"]').trigger('click')

			await wrapper.getComponent({ name: 'TaskRowDetails' }).vm.$emit('record')

			expect(wrapper.findComponent({ name: 'CompletionPicker' }).exists()).toBe(true)
		})

		it('opens the one form with what the row knows of its room task, and closes it', async () => {
			const block = cycle(1, 2)
			const wrapper = mountRow({ item: item({ cycle: block }) })
			await wrapper.get('[data-testid="task-expand"]').trigger('click')

			await wrapper.getComponent({ name: 'TaskRowDetails' }).vm.$emit('edit')

			const form = wrapper.getComponent({ name: 'TaskForm' })
			expect(form.props('item')).toEqual({ task: item().task, roomTask: { ...item().roomTask, assignees: ['mom'], cycle: block } })
			await form.vm.$emit('close')
			expect(wrapper.findComponent({ name: 'TaskForm' }).exists()).toBe(false)
		})
	})
})
