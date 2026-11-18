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

const cycle = (over = {}) => ({ cycleKey: 'c88', expectedVersion: 4, state: 'open', postponeCount: 0, canPostpone: true, ...over })
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
		await button(wrapper).trigger('click')

		expect(mockStore.postpone).toHaveBeenCalledWith(7)
	})

	it.each([
		['a task without flex days', cycle({ canPostpone: false }), 0, 'A task without flex days cannot be postponed'],
		['three postponements', cycle({ canPostpone: false, postponeCount: 3 }), 2, 'This task was already postponed three times'],
		['a red task', cycle({ canPostpone: false, state: 'overdue' }), 2, 'An overdue task cannot be postponed'],
	])('is off for %s and says why', (label, block, flexDays, reason) => {
		const wrapper = mountButton({ cycle: block, flexDays })

		expect(button(wrapper).element.disabled).toBe(true)
		expect(button(wrapper).attributes('title')).toBe(reason)
		expect(wrapper.find('[data-testid="postpone-left"]').exists()).toBe(false)
	})

	it.each([[null], [{ state: 'bonus', expectedVersion: 1 }], [{ state: 'asleep' }]])('has no button for a task without a cycle: %j', (block) => {
		expect(mountButton({ cycle: block }).find('[data-testid="postpone-button"]').exists()).toBe(false)
	})

	it('is off while a postponement is in flight', () => {
		mockStore.isPostponing.mockReturnValue(true)

		expect(button(mountButton()).element.disabled).toBe(true)
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
