/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, expect, it, jest } from '@jest/globals'
import { mount } from '@vue/test-utils'
import TaskChecklist from '../../../src/components/Tasks/TaskChecklist.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

/**
 * The sub-tasks of a task's current cycle, to tick (ADR-021 decision 3):
 * execute-only — the one form edits them (CS-PLAN-091 WP-B6).
 */
describe('TaskChecklist', () => {
	const items = [
		{ id: 1, label: 'Wipe table', sortOrder: 2 },
		{ id: 2, label: 'Clear plates', sortOrder: 1 },
		{ id: 3, label: 'Sweep', sortOrder: 3 },
	]
	const mountList = (props = {}) => mount(TaskChecklist, { props: { items, progress: [], roomTaskId: 10, ...props } })
	const box = (wrapper, id) => wrapper.get(`[data-testid="checklist-item-${id}"] input`)

	it('lists the sub-tasks in their order with the ticks of the cycle', () => {
		const wrapper = mountList({ progress: [{ checklistItemId: 3, isChecked: true }, { checklistItemId: 1, isChecked: false }] })

		expect(wrapper.findAll('.item-title').map((title) => title.text())).toEqual(['Clear plates', 'Wipe table', 'Sweep'])
		expect(box(wrapper, 3).element.checked).toBe(true)
		expect(box(wrapper, 1).element.checked).toBe(false)
		expect(wrapper.get('.progress-badge').text()).toBe('1/3')
	})

	it('counts no tick of a sub-task the form removed', () => {
		const wrapper = mountList({ items: items.slice(0, 2), progress: [{ checklistItemId: 3, isChecked: true }, { checklistItemId: 2, isChecked: true }] })

		expect(wrapper.get('.progress-badge').text()).toBe('1/2')
	})

	it('reports a tick with the item and the room task', async () => {
		const wrapper = mountList()

		await box(wrapper, 2).setValue(true)

		expect(wrapper.emitted('toggle')).toEqual([[{ itemId: 2, roomTaskId: 10 }]])
	})

	it('shows done work without taking ticks', () => {
		const wrapper = mountList({ readonly: true })

		expect(wrapper.findAll('input').every((input) => input.element.disabled)).toBe(true)
	})

	it('offers no editing of the sub-tasks themselves', () => {
		const wrapper = mountList()

		expect(wrapper.findAll('button')).toHaveLength(0)
		expect(wrapper.find('[data-testid="checklist-add-item-button"]').exists()).toBe(false)
		expect(wrapper.find('[draggable="true"]').exists()).toBe(false)
	})
})
