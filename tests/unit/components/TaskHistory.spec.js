/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { afterEach, beforeEach, describe, expect, it, jest } from '@jest/globals'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import TaskHistory from '../../../src/components/Tasks/TaskHistory.vue'
import { showRefusal } from '../../../src/utils/errorToast.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => Object.entries(vars).reduce((out, [key, value]) => out.replace(`{${key}}`, String(value)), text),
}))
jest.mock('@/utils/logger', () => ({
	logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() },
}))
jest.mock('../../../src/utils/errorToast.js', () => ({ showRefusal: jest.fn() }))
jest.mock('@/components/Common/ConfirmDialog.vue', () => ({
	name: 'ConfirmDialog',
	props: ['title', 'description', 'loading'],
	emits: ['confirm', 'cancel'],
	template: '<div data-testid="confirm-dialog">{{ title }}</div>',
}))

let mockStore
jest.mock('../../../src/store/taskCycle.js', () => ({ useTaskCycleStore: () => mockStore }))
jest.mock('../../../src/store/house.js', () => ({ useHouseStore: () => ({ timezone: 'UTC' }) }))

const share = (displayName) => ({ userId: displayName.toLowerCase(), displayName, sharePct: 50 })
const entries = () => [
	{ id: 42, version: 1, workDate: '2026-11-16', displayName: 'Kim', allocation: [share('Kim'), share('Mia')], canCorrect: true, recordedForOthers: false },
	{ id: 41, version: 0, workDate: null, completedAt: '2026-11-09T18:00:00Z', displayName: 'Alex', allocation: [share('Mia')], canCorrect: false, recordedForOthers: true },
]

/**
 * The history of a task in its opened row (CS-PLAN-091 WP-B3): who did it
 * when, and the date change and deletion of the entries the server lets
 * the viewer correct.
 */
describe('TaskHistory', () => {
	let history

	const mountHistory = async () => {
		const wrapper = mount(TaskHistory, { props: { roomTaskId: 7 } })
		await flushPromises()
		return wrapper
	}
	const rows = (wrapper) => wrapper.findAll('[data-testid="task-history-entry"]')

	beforeEach(() => {
		jest.clearAllMocks()
		jest.useFakeTimers()
		jest.setSystemTime(new Date('2026-11-18T10:00:00Z'))
		history = entries()
		mockStore = {
			historyOf: jest.fn(() => history),
			fetchHistory: jest.fn().mockResolvedValue([]),
			changeDate: jest.fn().mockResolvedValue({}),
			deleteEntry: jest.fn().mockResolvedValue(),
		}
	})

	afterEach(() => {
		jest.useRealTimers()
	})

	it('reads the history of its task and lists who did it when', async () => {
		const wrapper = await mountHistory()

		expect(mockStore.fetchHistory).toHaveBeenCalledWith(7)
		expect(rows(wrapper)).toHaveLength(2)
		expect(rows(wrapper)[0].text()).toContain('Kim, Mia')
		// A row from before work dates counts on the household day of its instant.
		expect(rows(wrapper)[1].find('strong').text()).toBe(rows(wrapper)[1].find('strong').text().replace('16', '9'))
		expect(rows(wrapper)[1].get('[data-testid="task-history-recorder"]').text()).toBe('recorded by Alex')
		expect(rows(wrapper)[0].find('[data-testid="task-history-recorder"]').exists()).toBe(false)
	})

	it('offers the corrections only on the entries the server grants', async () => {
		const wrapper = await mountHistory()

		expect(rows(wrapper)[0].find('[data-testid="task-history-redate"]').exists()).toBe(true)
		expect(rows(wrapper)[0].find('[data-testid="task-history-delete"]').exists()).toBe(true)
		expect(rows(wrapper)[1].find('button').exists()).toBe(false)
		expect(wrapper.get('[data-testid="task-history-rights"]').text()).toContain('You can change or delete your own latest entry')
	})

	// RF-091-09: the note follows the rights the server sends, not a client role.
	it('shows no note about what members may change when every entry can be corrected', async () => {
		history = entries().map((entry) => ({ ...entry, canCorrect: true }))
		const wrapper = await mountHistory()

		expect(wrapper.find('[data-testid="task-history-rights"]').exists()).toBe(false)
		expect(wrapper.text()).not.toContain('own latest entry')
	})

	it('names the year of an entry from another year', async () => {
		history = [{ ...entries()[0], workDate: '2025-12-30' }]
		const wrapper = await mountHistory()

		expect(rows(wrapper)[0].find('strong').text()).toContain('2025')
	})

	it('moves focus into the date edit and back to its button (RF-091-09)', async () => {
		const wrapper = mount(TaskHistory, { props: { roomTaskId: 7 }, attachTo: document.body })
		await flushPromises()

		await wrapper.get('[data-testid="task-history-redate"]').trigger('click')
		await flushPromises()
		expect(document.activeElement?.dataset.testid).toBe('task-history-date')

		await wrapper.get('[data-testid="task-history-date"]').setValue('2026-11-14')
		await wrapper.get('[data-testid="task-history-save"]').trigger('click')
		await flushPromises()
		expect(document.activeElement?.dataset.testid).toBe('task-history-redate')
		wrapper.unmount()
	})

	it('changes the date of an entry, never to a day in the future', async () => {
		const wrapper = await mountHistory()
		await wrapper.get('[data-testid="task-history-redate"]').trigger('click')

		const input = wrapper.get('[data-testid="task-history-date"]')
		expect(input.element.value).toBe('2026-11-16')
		expect(input.attributes('max')).toBe('2026-11-18')
		// The same day is no change.
		expect(wrapper.get('[data-testid="task-history-save"]').element.disabled).toBe(true)

		await input.setValue('2026-11-14')
		await wrapper.get('[data-testid="task-history-save"]').trigger('click')
		await flushPromises()

		expect(mockStore.changeDate).toHaveBeenCalledWith(7, history[0], '2026-11-14')
		expect(wrapper.find('[data-testid="task-history-date"]').exists()).toBe(false)
	})

	it('keeps the draft open and shows the reason when the day is refused', async () => {
		const refusal = Object.assign(new Error('The date must be after the previous entry of this task'), { status: 400, code: 'VALIDATION_ERROR' })
		mockStore.changeDate.mockRejectedValue(refusal)
		const wrapper = await mountHistory()
		await wrapper.get('[data-testid="task-history-redate"]').trigger('click')
		await wrapper.get('[data-testid="task-history-date"]').setValue('2026-11-09')

		await wrapper.get('[data-testid="task-history-save"]').trigger('click')
		await flushPromises()

		expect(showRefusal).toHaveBeenCalledWith(refusal, 'Could not change the date')
		expect(wrapper.get('[data-testid="task-history-date"]').element.value).toBe('2026-11-09')
	})

	it('deletes an entry only after the confirmation', async () => {
		const wrapper = await mountHistory()
		await wrapper.get('[data-testid="task-history-delete"]').trigger('click')
		expect(mockStore.deleteEntry).not.toHaveBeenCalled()

		wrapper.findComponent({ name: 'ConfirmDialog' }).vm.$emit('confirm')
		await flushPromises()

		expect(mockStore.deleteEntry).toHaveBeenCalledWith(7, history[0])
		expect(wrapper.findComponent({ name: 'ConfirmDialog' }).exists()).toBe(false)
	})

	it('reads nothing itself after its own delete, and moves the focus to the entry that took the place', async () => {
		const list = reactive({ entries: [
			{ ...entries()[0], id: 43, version: 0 },
			entries()[0],
			entries()[1],
		] })
		mockStore.historyOf = () => list.entries
		const handlerOf = (name) => subscribe.mock.calls.find(([event]) => event === name)?.[1]
		mockStore.deleteEntry.mockImplementation(async () => {
			// The undo announces itself while the delete runs; the store then reads the history once.
			handlerOf('chorestrike:task-uncompleted')({ roomTaskId: 7 })
			list.entries = list.entries.slice(1)
		})
		const wrapper = mount(TaskHistory, { props: { roomTaskId: 7 }, attachTo: document.body })
		await flushPromises()
		mockStore.fetchHistory.mockClear()

		await wrapper.findAll('[data-testid="task-history-delete"]')[0].trigger('click')
		wrapper.findComponent({ name: 'ConfirmDialog' }).vm.$emit('confirm')
		await flushPromises()

		expect(mockStore.fetchHistory).not.toHaveBeenCalled()
		expect(document.activeElement).toBe(wrapper.findAll('[data-testid="task-history-redate"]')[0].element)
		wrapper.unmount()
	})

	it('moves the focus to the heading when the last entry it could correct is deleted', async () => {
		const list = reactive({ entries: entries() })
		mockStore.historyOf = () => list.entries
		mockStore.deleteEntry.mockImplementation(async () => {
			list.entries = list.entries.slice(1)
		})
		const wrapper = mount(TaskHistory, { props: { roomTaskId: 7 }, attachTo: document.body })
		await flushPromises()

		await wrapper.get('[data-testid="task-history-delete"]').trigger('click')
		wrapper.findComponent({ name: 'ConfirmDialog' }).vm.$emit('confirm')
		await flushPromises()

		expect(document.activeElement).toBe(wrapper.get('.history-heading').element)
		wrapper.unmount()
	})

	it('shows why a deletion failed', async () => {
		const failure = Object.assign(new Error('Forbidden'), { status: 403, code: 'FORBIDDEN' })
		mockStore.deleteEntry.mockRejectedValue(failure)
		const wrapper = await mountHistory()
		await wrapper.get('[data-testid="task-history-delete"]').trigger('click')

		wrapper.findComponent({ name: 'ConfirmDialog' }).vm.$emit('confirm')
		await flushPromises()

		expect(showRefusal).toHaveBeenCalledWith(failure, 'Could not delete the entry')
	})

	it('says so when the task was never done', async () => {
		history = []

		expect((await mountHistory()).get('[data-testid="task-history-empty"]').text()).toBe('Not done yet')
	})

	it('reads the entries again after a completion or an undo of its task elsewhere', async () => {
		const wrapper = await mountHistory()
		expect(mockStore.fetchHistory).toHaveBeenCalledTimes(1)
		const handlerOf = (name) => subscribe.mock.calls.find(([event]) => event === name)?.[1]

		handlerOf('chorestrike:task-completed')({ roomTaskId: 8 })
		await flushPromises()
		expect(mockStore.fetchHistory).toHaveBeenCalledTimes(1)

		handlerOf('chorestrike:task-completed')({ roomTaskId: 7 })
		handlerOf('chorestrike:task-uncompleted')({ roomTaskId: 7 })
		await flushPromises()
		expect(mockStore.fetchHistory).toHaveBeenCalledTimes(3)

		// Gone with the row: no read for a history nobody shows.
		wrapper.unmount()
		expect(unsubscribe).toHaveBeenCalledWith('chorestrike:task-completed', handlerOf('chorestrike:task-completed'))
		expect(unsubscribe).toHaveBeenCalledWith('chorestrike:task-uncompleted', handlerOf('chorestrike:task-uncompleted'))
	})

	it('offers a retry when the history cannot be read', async () => {
		history = null
		mockStore.fetchHistory.mockRejectedValueOnce(new Error('offline'))
		const wrapper = await mountHistory()

		await wrapper.get('[data-testid="task-history-error"] button').trigger('click')
		await flushPromises()

		expect(mockStore.fetchHistory).toHaveBeenCalledTimes(2)
		expect(wrapper.find('[data-testid="task-history-error"]').exists()).toBe(false)
	})
})
