/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { beforeEach, describe, expect, it, jest } from '@jest/globals'
import { flushPromises, mount } from '@vue/test-utils'
import PostponeButton from '../../../src/components/Tasks/PostponeButton.vue'
import { showPostponeError } from '../../../src/utils/errorToast.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => Object.entries(vars).reduce((out, [key, value]) => out.replace(`{${key}}`, String(value)), text),
}))
jest.mock('@/utils/logger', () => ({
	logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() },
}))
jest.mock('../../../src/utils/errorToast.js', () => ({ showPostponeError: jest.fn() }))

let mockStore
jest.mock('../../../src/store/taskCycle.js', () => ({
	useTaskCycleStore: () => mockStore,
}))

const cycle = (over = {}) => ({ cycleKey: 'c88', expectedVersion: 4, state: 'open', postponeCount: 0, postponeLimit: 3, canPostpone: true, ...over })
const mountButton = (props = {}) => mount(PostponeButton, { props: { roomTaskId: 7, cycle: cycle(), flexDays: 2, ...props } })
const button = (wrapper) => wrapper.get('[data-testid="postpone-button"]')

/**
 * The postpone button of an opened task row (CS-PLAN-091 WP-B3): open to
 * every member while the row's cycle block allows it, and saying why not
 * otherwise.
 */
describe('PostponeButton', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		mockStore = { postpone: jest.fn().mockResolvedValue({}), isPostponing: jest.fn(() => false) }
	})

	it('postpones the task and says how often it is still possible', async () => {
		const wrapper = mountButton({ cycle: cycle({ postponeCount: 1 }) })

		expect(wrapper.get('[data-testid="postpone-left"]').text()).toBe('2× left')
		expect(wrapper.find('[data-testid="postpone-reason"]').exists()).toBe(false)
		expect(button(wrapper).attributes('aria-describedby')).toBeUndefined()
		await button(wrapper).trigger('click')

		expect(mockStore.postpone).toHaveBeenCalledWith(7)
	})

	it.each([
		['a task without flex days', cycle({ canPostpone: false }), 0, 'A task without flex days cannot be postponed'],
		['three postponements', cycle({ canPostpone: false, postponeCount: 3 }), 2, 'This task was already postponed 3 times'],
		['a red task', cycle({ canPostpone: false, state: 'overdue' }), 2, 'An overdue task cannot be postponed'],
	])('is off for %s and says why in visible text the button points to (RF-091-09)', async (label, block, flexDays, reason) => {
		const wrapper = mountButton({ cycle: block, flexDays })
		const text = wrapper.get('[data-testid="postpone-reason"]')

		// Off but focusable: a keyboard reaches the button and hears its reason.
		expect(button(wrapper).attributes('aria-disabled')).toBe('true')
		expect(button(wrapper).element.disabled).toBe(false)
		await button(wrapper).trigger('click')
		expect(mockStore.postpone).not.toHaveBeenCalled()
		expect(text.text()).toBe(reason)
		expect(button(wrapper).attributes('aria-describedby')).toBe(text.attributes('id'))
		expect(text.attributes('id')).toBe('postpone-reason-7')
		expect(wrapper.find('[data-testid="postpone-left"]').exists()).toBe(false)
	})

	it('counts what is left against the limit the server names', () => {
		expect(mountButton({ cycle: cycle({ postponeLimit: 5, postponeCount: 1 }) }).get('[data-testid="postpone-left"]').text()).toBe('4× left')
		expect(mountButton({ cycle: cycle({ postponeLimit: 1, postponeCount: 2 }) }).get('[data-testid="postpone-left"]').text()).toBe('0× left')
	})

	it.each([[null], [{ state: 'bonus', expectedVersion: 1 }], [{ state: 'asleep' }]])('has no button for a task without a cycle: %j', (block) => {
		expect(mountButton({ cycle: block }).find('[data-testid="postpone-button"]').exists()).toBe(false)
	})

	it('is off while a postponement is in flight, without dropping the focus of the press', async () => {
		mockStore.isPostponing.mockReturnValue(true)
		const wrapper = mountButton()

		expect(button(wrapper).attributes('aria-disabled')).toBe('true')
		expect(button(wrapper).element.disabled).toBe(false)
		await button(wrapper).trigger('click')
		expect(mockStore.postpone).not.toHaveBeenCalled()
	})

	it('says that it can be pressed', () => {
		expect(button(mountButton()).attributes('aria-disabled')).toBe('false')
	})

	it('shows why the server refused', async () => {
		const refusal = Object.assign(new Error('no'), { status: 409, code: 'WINDOW_ENDED' })
		mockStore.postpone.mockRejectedValue(refusal)
		const wrapper = mountButton()

		await button(wrapper).trigger('click')
		await flushPromises()

		expect(showPostponeError).toHaveBeenCalledWith(refusal)
	})
})
