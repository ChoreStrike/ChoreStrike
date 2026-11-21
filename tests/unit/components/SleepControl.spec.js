/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { afterEach, beforeEach, describe, expect, it, jest } from '@jest/globals'
import { flushPromises, mount } from '@vue/test-utils'
import SleepControl from '../../../src/components/Tasks/SleepControl.vue'
import { showRefusal } from '../../../src/utils/errorToast.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => Object.entries(vars).reduce((out, [key, value]) => out.replace(`{${key}}`, String(value)), text),
}))
jest.mock('@/utils/logger', () => ({
	logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() },
}))
jest.mock('../../../src/utils/errorToast.js', () => ({ showRefusal: jest.fn() }))

let mockStore
jest.mock('../../../src/store/taskCycle.js', () => ({ useTaskCycleStore: () => mockStore }))

const open = { cycleKey: 'c88', expectedVersion: 4, state: 'open' }
const asleep = { state: 'asleep', expectedVersion: 5, wakeOn: null }
const mountControl = (cycle = asleep) => mount(SleepControl, { props: { roomTaskId: 7, cycle } })

/**
 * The wake-up of an opened task row (CS-PLAN-091 WP-B4, ADR-021 decision 7).
 * A task is put to sleep in the one form (the operator's GUI review of
 * WP-B6), so the row offers nothing for a task that is awake.
 */
describe('SleepControl', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		mockStore = { setSleeping: jest.fn().mockResolvedValue({}) }
	})

	it.each([
		['an awake task', open],
		['a bonus task', { state: 'bonus', expectedVersion: 2 }],
		['a task without a cycle block', null],
	])('offers nothing for %s: the form puts a task to sleep', (_label, cycle) => {
		const wrapper = mountControl(cycle)

		expect(wrapper.find('[data-testid="sleep-control"]').exists()).toBe(false)
		expect(wrapper.find('button').exists()).toBe(false)
	})

	it.each([
		[asleep, 'Asleep'],
		[{ state: 'asleep', expectedVersion: 5, wakeOn: '2099-03-01' }, 'Asleep until '],
	])('says that a task sleeps and wakes it: %j', async (cycle, label) => {
		const wrapper = mountControl(cycle)

		expect(wrapper.get('[data-testid="sleep-state"]').text()).toContain(label)
		expect(wrapper.get('[data-testid="sleep-state"]').attributes('role')).toBe('status')
		expect(wrapper.findAll('button')).toHaveLength(1)
		await wrapper.get('[data-testid="sleep-wake"]').trigger('click')

		expect(mockStore.setSleeping).toHaveBeenCalledWith(7, false, null)
	})

	it('names the wake-up when the server refuses it, and keeps the button', async () => {
		const refusal = Object.assign(new Error('boom'), { status: 500 })
		mockStore.setSleeping.mockRejectedValue(refusal)
		const wrapper = mountControl()

		await wrapper.get('[data-testid="sleep-wake"]').trigger('click')
		await flushPromises()

		expect(showRefusal).toHaveBeenCalledWith(refusal, 'Could not wake the task up')
		expect(wrapper.get('[data-testid="sleep-wake"]').element.disabled).toBe(false)
		expect(wrapper.get('[data-testid="sleep-state"]').text()).toContain('Asleep')
	})

	describe('after waking (RF-091-09)', () => {
		let wrapper

		afterEach(() => wrapper?.unmount())

		it('says that the task is awake again and keeps the focus on that line when the button goes', async () => {
			wrapper = mount(SleepControl, { props: { roomTaskId: 7, cycle: asleep }, attachTo: document.body })
			// The store answers with the fresh cycle of the woken task.
			mockStore.setSleeping.mockImplementation(async () => {
				await wrapper.setProps({ cycle: open })
				return {}
			})

			await wrapper.get('[data-testid="sleep-wake"]').trigger('click')
			await flushPromises()

			expect(wrapper.find('[data-testid="sleep-wake"]').exists()).toBe(false)
			const state = wrapper.get('[data-testid="sleep-state"]')
			expect(state.text()).toBe('Awake again')
			expect(state.attributes('role')).toBe('status')
			expect(state.attributes('tabindex')).toBe('-1')
			expect(document.activeElement).toBe(state.element)
		})

		it('says nothing of the kind for a task that was awake all along', () => {
			wrapper = mountControl(open)

			expect(wrapper.text()).toBe('')
		})
	})

	it('blocks a second press while the command is in flight', async () => {
		let release
		mockStore.setSleeping.mockReturnValue(new Promise((resolve) => {
			release = resolve
		}))
		const wrapper = mountControl()

		await wrapper.get('[data-testid="sleep-wake"]').trigger('click')
		expect(wrapper.get('[data-testid="sleep-wake"]').element.disabled).toBe(true)
		release({})
		await flushPromises()

		// The mocked store leaves the cycle asleep: the button is back.
		expect(wrapper.get('[data-testid="sleep-wake"]').element.disabled).toBe(false)
	})
})
