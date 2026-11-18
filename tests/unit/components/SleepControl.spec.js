/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { beforeEach, describe, expect, it, jest } from '@jest/globals'
import { flushPromises, mount } from '@vue/test-utils'
import SleepControl from '../../../src/components/Tasks/SleepControl.vue'
import { showServerRefusal } from '../../../src/utils/errorToast.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => Object.entries(vars).reduce((out, [key, value]) => out.replace(`{${key}}`, String(value)), text),
}))
jest.mock('@/utils/logger', () => ({
	logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() },
}))
jest.mock('../../../src/utils/errorToast.js', () => ({ showServerRefusal: jest.fn() }))

let mockStore
jest.mock('../../../src/store/taskCycle.js', () => ({ useTaskCycleStore: () => mockStore }))
jest.mock('../../../src/store/house.js', () => ({ useHouseStore: () => ({ timezone: 'UTC' }) }))

const open = { cycleKey: 'c88', expectedVersion: 4, state: 'open' }
const mountControl = (cycle = open) => mount(SleepControl, { props: { roomTaskId: 7, cycle } })
const tomorrow = () => new Date(Date.now() + 86400000).toISOString().slice(0, 10)

/**
 * The sleep control of an opened task row (CS-PLAN-091 WP-B4, ADR-021
 * decision 7): put a task to sleep, optionally until a wake date, and wake
 * it again.
 */
describe('SleepControl', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		mockStore = { setSleeping: jest.fn().mockResolvedValue({}) }
	})

	it('asks for an optional wake date first, then puts the task to sleep', async () => {
		const wrapper = mountControl()
		expect(wrapper.find('[data-testid="sleep-form"]').exists()).toBe(false)

		await wrapper.get('[data-testid="sleep-open"]').trigger('click')
		expect(wrapper.get('[data-testid="sleep-wake-on"]').attributes('min')).toBe(tomorrow())
		expect(wrapper.get('[data-testid="sleep-form"]').text()).toContain('Completing it wakes it up')
		await wrapper.get('[data-testid="sleep-form"]').trigger('submit')
		await flushPromises()

		expect(mockStore.setSleeping).toHaveBeenCalledWith(7, true, null)
		expect(wrapper.find('[data-testid="sleep-form"]').exists()).toBe(false)
	})

	it('sends the wake date that was picked', async () => {
		const wrapper = mountControl()
		await wrapper.get('[data-testid="sleep-open"]').trigger('click')
		await wrapper.get('[data-testid="sleep-wake-on"]').setValue('2099-03-01')
		await wrapper.get('[data-testid="sleep-form"]').trigger('submit')

		expect(mockStore.setSleeping).toHaveBeenCalledWith(7, true, '2099-03-01')
	})

	it('can be left without a request', async () => {
		const wrapper = mountControl()
		await wrapper.get('[data-testid="sleep-open"]').trigger('click')
		await wrapper.get('[data-testid="sleep-cancel"]').trigger('click')

		expect(wrapper.find('[data-testid="sleep-form"]').exists()).toBe(false)
		expect(mockStore.setSleeping).not.toHaveBeenCalled()
	})

	it.each([
		[{ state: 'asleep', expectedVersion: 5, wakeOn: null }, 'Asleep'],
		[{ state: 'asleep', expectedVersion: 5, wakeOn: '2099-03-01' }, 'Asleep until '],
	])('says that a task sleeps and wakes it: %j', async (cycle, label) => {
		const wrapper = mountControl(cycle)

		expect(wrapper.get('[data-testid="sleep-state"]').text()).toContain(label)
		expect(wrapper.find('[data-testid="sleep-open"]').exists()).toBe(false)
		await wrapper.get('[data-testid="sleep-wake"]').trigger('click')

		expect(mockStore.setSleeping).toHaveBeenCalledWith(7, false, null)
	})

	it('offers nothing for a task without a cycle block', () => {
		expect(mountControl(null).find('[data-testid="sleep-control"]').exists()).toBe(false)
	})

	it('shows why the server refused and keeps the form open', async () => {
		const refusal = Object.assign(new Error('The wake date must lie after today and within one year'), { status: 400, code: 'VALIDATION_ERROR' })
		mockStore.setSleeping.mockRejectedValue(refusal)
		const wrapper = mountControl()
		await wrapper.get('[data-testid="sleep-open"]').trigger('click')
		await wrapper.get('[data-testid="sleep-form"]').trigger('submit')
		await flushPromises()

		expect(showServerRefusal).toHaveBeenCalledWith(refusal, 'Could not put the task to sleep')
		expect(wrapper.find('[data-testid="sleep-form"]').exists()).toBe(true)
		expect(wrapper.get('[data-testid="sleep-confirm"]').element.disabled).toBe(false)
	})

	it('blocks a second press while the command is in flight', async () => {
		let release
		mockStore.setSleeping.mockReturnValue(new Promise((resolve) => {
			release = resolve
		}))
		const wrapper = mountControl({ state: 'asleep', expectedVersion: 5, wakeOn: null })

		await wrapper.get('[data-testid="sleep-wake"]').trigger('click')
		expect(wrapper.get('[data-testid="sleep-wake"]').element.disabled).toBe(true)
		release({})
		await flushPromises()

		expect(wrapper.get('[data-testid="sleep-wake"]').element.disabled).toBe(false)
	})
})
