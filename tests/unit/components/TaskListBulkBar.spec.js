/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { mount } from '@vue/test-utils'
import TaskListBulkBar from '../../../src/components/Tasks/TaskListBulkBar.vue'

const members = [
	{ nextcloudUserId: 'u1', displayName: 'Alice' },
	{ nextcloudUserId: 'u2', displayName: 'Bob' },
]

const mountBar = (propsOver = {}) => mount(TaskListBulkBar, {
	props: {
		selectedCount: 2,
		members,
		...propsOver,
	},
})

describe('TaskListBulkBar (CS-PLAN-067 §3.4)', () => {
	it('shows the selection count and the member target options', () => {
		const wrapper = mountBar()
		expect(wrapper.find('.bulk-selection-count').text()).toContain('2 selected')
		const options = wrapper.findAll('option').map(o => o.text())
		expect(options).toContain('Alice')
		expect(options).toContain('Bob')
	})

	it('disables the assign button until a target is picked', async () => {
		const wrapper = mountBar()
		expect(wrapper.find('[data-testid="bulk-assign-button"]').attributes('disabled')).toBeDefined()

		await wrapper.find('[data-testid="bulk-assign-target"]').setValue('u1')
		expect(wrapper.find('[data-testid="bulk-assign-button"]').attributes('disabled')).toBeUndefined()
	})

	it('emits assign with the picked target and clear on the clear button', async () => {
		const wrapper = mountBar()
		await wrapper.find('[data-testid="bulk-assign-target"]').setValue('u2')
		await wrapper.find('[data-testid="bulk-assign-button"]').trigger('click')
		expect(wrapper.emitted('assign')).toEqual([['u2']])

		await wrapper.find('.bulk-clear-button').trigger('click')
		expect(wrapper.emitted('clear')).toBeTruthy()
	})

	it('resets the local target draft when the clear signal bumps', async () => {
		const wrapper = mountBar()
		await wrapper.find('[data-testid="bulk-assign-target"]').setValue('u1')

		await wrapper.setProps({ clearSignal: 1 })
		expect(wrapper.vm.targetUserId).toBe('')
	})
})
