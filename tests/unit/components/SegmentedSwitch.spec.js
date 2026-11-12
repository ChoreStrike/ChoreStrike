/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { mount } from '@vue/test-utils'
import SegmentedSwitch from '../../../src/components/Common/SegmentedSwitch.vue'

const options = [
	{ value: 'due', label: 'By due date' },
	{ value: 'room', label: 'By room' },
	{ value: 'person', label: 'By person' },
]
const mountSwitch = (modelValue = 'room') => mount(SegmentedSwitch, {
	props: { modelValue, options, label: 'Grouping', testidPrefix: 'tasks-group' },
})

describe('SegmentedSwitch', () => {
	it('names the group and shows the choices in their order', () => {
		const wrapper = mountSwitch()

		expect(wrapper.get('[role="group"]').attributes('aria-label')).toBe('Grouping')
		expect(wrapper.findAll('button').map((button) => button.text())).toEqual(['By due date', 'By room', 'By person'])
	})

	it('marks the current choice and reports another one', async () => {
		const wrapper = mountSwitch()

		expect(wrapper.get('[data-testid="tasks-group-room"]').attributes('aria-pressed')).toBe('true')
		expect(wrapper.get('[data-testid="tasks-group-due"]').attributes('aria-pressed')).toBe('false')

		await wrapper.get('[data-testid="tasks-group-person"]').trigger('click')
		expect(wrapper.emitted('update:modelValue')).toEqual([['person']])
	})
})
