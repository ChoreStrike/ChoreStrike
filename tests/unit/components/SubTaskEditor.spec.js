/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, expect, it, jest } from '@jest/globals'
import { mount } from '@vue/test-utils'
import SubTaskEditor from '../../../src/components/Tasks/SubTaskEditor.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, params) => Object.entries(params ?? {})
		.reduce((label, [key, value]) => label.replaceAll(`{${key}}`, String(value)), text)),
}))

function entries() {
	return [
		{ id: 4, label: 'Rinse', key: 'item-4' },
		{ id: 5, label: 'Load', key: 'item-5' },
		{ label: 'Dry', key: 'new-0' },
	]
}

/**
 * The sub-tasks of the one form (CS-PLAN-091 WP-B6): edited in the form,
 * saved with it; an entry keeps its id while it is the same sub-task.
 */
describe('SubTaskEditor', () => {
	const mountEditor = (value = entries()) => {
		const wrapper = mount(SubTaskEditor, {
			props: {
				modelValue: value,
				'onUpdate:modelValue': (next) => wrapper.setProps({ modelValue: next }),
			},
		})
		return wrapper
	}
	const last = (wrapper) => wrapper.emitted('update:modelValue').at(-1)[0]
	const labels = (wrapper) => wrapper.findAll('[data-testid="task-subtask-label"]').map((input) => input.element.value)

	it('lists the sub-tasks with numbered labels and offers no up for the first', () => {
		const wrapper = mountEditor()

		expect(labels(wrapper)).toEqual(['Rinse', 'Load', 'Dry'])
		expect(wrapper.findAll('[data-testid="task-subtask-label"]')[1].attributes('aria-label')).toBe('Sub-task 2')
		expect(wrapper.findAll('[data-testid="task-subtask-up"]')).toHaveLength(2)
		expect(wrapper.findAll('[data-testid="task-subtask-remove"]')[0].attributes('aria-label')).toBe('Remove “Rinse”')
	})

	it('renames a sub-task in place and keeps its id', async () => {
		const wrapper = mountEditor()

		await wrapper.findAll('[data-testid="task-subtask-label"]')[0].setValue('Rinse well')

		expect(last(wrapper)[0]).toEqual({ id: 4, label: 'Rinse well', key: 'item-4' })
	})

	it('moves a sub-task up', async () => {
		const wrapper = mountEditor()

		await wrapper.findAll('[data-testid="task-subtask-up"]')[1].trigger('click')

		expect(last(wrapper).map((entry) => entry.key)).toEqual(['item-4', 'new-0', 'item-5'])
		expect(wrapper.findAll('[data-testid="task-subtask-up"]')[0].attributes('aria-label')).toBe('Move “Dry” up')
	})

	it('removes a sub-task', async () => {
		const wrapper = mountEditor()

		await wrapper.findAll('[data-testid="task-subtask-remove"]')[0].trigger('click')

		expect(labels(wrapper)).toEqual(['Load', 'Dry'])
	})

	it('adds a trimmed sub-task without an id and with a key of its own, then clears the field', async () => {
		const wrapper = mountEditor([])
		const add = wrapper.get('[data-testid="task-subtask-add"]')
		expect(add.element.disabled).toBe(true)

		await wrapper.get('[data-testid="task-subtask-new"]').setValue('  Wipe  ')
		expect(add.element.disabled).toBe(false)
		await add.trigger('click')
		// Enter in the field adds too.
		await wrapper.get('[data-testid="task-subtask-new"]').setValue('Sweep')
		await wrapper.get('[data-testid="task-subtask-new"]').trigger('keydown', { key: 'Enter' })

		const [first, second] = last(wrapper)
		expect(first).toEqual({ label: 'Wipe', key: expect.stringMatching(/^new-/) })
		expect(second.key).not.toBe(first.key)
		expect(wrapper.get('[data-testid="task-subtask-new"]').element.value).toBe('')
	})

	describe('keeps the keyboard where the change happened', () => {
		const attached = () => {
			const wrapper = mount(SubTaskEditor, {
				attachTo: document.body,
				props: {
					modelValue: entries(),
					'onUpdate:modelValue': (next) => wrapper.setProps({ modelValue: next }),
				},
			})
			return wrapper
		}
		const settle = async (wrapper) => {
			await wrapper.vm.$nextTick()
			await wrapper.vm.$nextTick()
		}

		it('follows a moved sub-task with its Up button, and takes its label once it is first', async () => {
			const wrapper = attached()

			await wrapper.findAll('[data-testid="task-subtask-up"]')[1].trigger('click')
			await settle(wrapper)
			expect(labels(wrapper)).toEqual(['Rinse', 'Dry', 'Load'])
			expect(document.activeElement).toBe(wrapper.findAll('[data-testid="task-subtask-up"]')[0].element)

			await wrapper.findAll('[data-testid="task-subtask-up"]')[0].trigger('click')
			await settle(wrapper)
			expect(labels(wrapper)).toEqual(['Dry', 'Rinse', 'Load'])
			expect(document.activeElement).toBe(wrapper.findAll('[data-testid="task-subtask-label"]')[0].element)
			wrapper.unmount()
		})

		it('goes to the next sub-task after a removal, then the one before, then the new field', async () => {
			const wrapper = attached()
			const remove = async (index) => {
				await wrapper.findAll('[data-testid="task-subtask-remove"]')[index].trigger('click')
				await settle(wrapper)
			}

			await remove(0)
			expect(document.activeElement).toBe(wrapper.findAll('[data-testid="task-subtask-label"]')[0].element)
			expect(document.activeElement.value).toBe('Load')

			await remove(1)
			expect(document.activeElement.value).toBe('Load')

			await remove(0)
			expect(document.activeElement).toBe(wrapper.get('[data-testid="task-subtask-new"]').element)
			wrapper.unmount()
		})
	})

	it('has no form of its own: it sits inside the one form', () => {
		expect(mountEditor().find('form').exists()).toBe(false)
		expect(mountEditor().get('[data-testid="task-subtask-add"]').attributes('type')).toBe('button')
	})

	it('adds nothing blank', async () => {
		const wrapper = mountEditor([])

		await wrapper.get('[data-testid="task-subtask-new"]').setValue('   ')
		await wrapper.get('[data-testid="task-subtask-new"]').trigger('keydown', { key: 'Enter' })

		expect(wrapper.emitted('update:modelValue')).toBeUndefined()
	})
})
