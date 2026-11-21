/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, expect, it, jest } from '@jest/globals'
import { mount } from '@vue/test-utils'
import TaskRhythmField from '../../../src/components/Tasks/TaskRhythmField.vue'
import { rhythmOf } from '../../../src/utils/taskFormModel.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, params) => Object.entries(params ?? {})
		.reduce((label, [key, value]) => label.replaceAll(`{${key}}`, String(value)), text)),
}))

const rhythm = (over = {}) => ({ ...rhythmOf(null), ...over })

/**
 * The rhythm of the one form (CS-PLAN-091 WP-B6, FEAT-UX-039): after
 * completion, a fixed day or once, how often, and the flex days.
 */
describe('TaskRhythmField', () => {
	const mountField = (value = rhythm(), stored = null) => {
		const wrapper = mount(TaskRhythmField, {
			props: {
				modelValue: value,
				stored,
				'onUpdate:modelValue': (next) => wrapper.setProps({ modelValue: next }),
			},
		})
		return wrapper
	}
	const last = (wrapper) => wrapper.emitted('update:modelValue').at(-1)[0]
	const units = (wrapper) => wrapper.get('[data-testid="task-frequency-unit"]').findAll('option').map((option) => option.element.value)

	it('switches between the three kinds of repetition and marks the chosen one', async () => {
		const wrapper = mountField()
		expect(wrapper.get('[data-testid="task-rhythm-after"]').attributes('aria-pressed')).toBe('true')

		await wrapper.get('[data-testid="task-rhythm-fixed"]').trigger('click')
		expect(last(wrapper).mode).toBe('fixed')
		expect(wrapper.get('[data-testid="task-rhythm-fixed"]').attributes('aria-pressed')).toBe('true')

		await wrapper.get('[data-testid="task-rhythm-once"]').trigger('click')
		expect(last(wrapper).mode).toBe('once')
		expect(wrapper.find('[data-testid="task-frequency-value"]').exists()).toBe(false)
	})

	it('sets how often in days, weeks or months, and never below one', async () => {
		const wrapper = mountField()
		expect(units(wrapper)).toEqual(['day', 'week', 'month'])

		await wrapper.get('[data-testid="task-frequency-value"]').setValue('3')
		expect(last(wrapper).every).toBe(3)
		await wrapper.get('[data-testid="task-frequency-unit"]').setValue('month')
		expect(last(wrapper).unit).toBe('month')
		await wrapper.get('[data-testid="task-frequency-value"]').setValue('')
		expect(last(wrapper).every).toBe(1)
	})

	it('names the unit in the singular for one and in the plural otherwise', async () => {
		const option = (wrapper) => wrapper.get('[data-testid="task-frequency-unit"] option[value="week"]').text()

		expect(option(mountField())).toBe('week')
		expect(option(mountField(rhythm({ every: 2 })))).toBe('weeks')
	})

	it('moves a daily rule to weeks when it becomes fixed, and offers no days then', async () => {
		const wrapper = mountField(rhythm({ unit: 'day' }))

		await wrapper.get('[data-testid="task-rhythm-fixed"]').trigger('click')

		expect(last(wrapper)).toMatchObject({ mode: 'fixed', unit: 'week' })
		expect(units(wrapper)).toEqual(['week', 'month'])
	})

	it('picks the weekdays of a fixed weekly rule and asks for at least one', async () => {
		const wrapper = mountField(rhythm({ mode: 'fixed' }))
		expect(wrapper.get('[data-testid="task-rhythm-problem"]').text()).toBe('Please select at least one day')
		expect(wrapper.get('[data-testid="task-rhythm-problem"]').attributes('role')).toBe('alert')

		await wrapper.get('[data-testid="task-weekday-monday"]').trigger('click')
		await wrapper.get('[data-testid="task-weekday-thursday"]').trigger('click')
		expect(last(wrapper).weekdays).toEqual(['monday', 'thursday'])
		expect(wrapper.get('[data-testid="task-weekday-monday"]').attributes('aria-pressed')).toBe('true')
		expect(wrapper.find('[data-testid="task-rhythm-problem"]').exists()).toBe(false)

		await wrapper.get('[data-testid="task-weekday-monday"]').trigger('click')
		expect(last(wrapper).weekdays).toEqual(['thursday'])
		expect(wrapper.find('[data-testid="task-month-day"]').exists()).toBe(false)
	})

	it('takes the day of a fixed monthly rule and checks it', async () => {
		const wrapper = mountField(rhythm({ mode: 'fixed', unit: 'month' }))
		expect(wrapper.find('[data-testid="task-weekday-monday"]').exists()).toBe(false)
		expect(wrapper.get('[data-testid="task-rhythm-problem"]').text()).toBe('The day of the month must be between 1 and 31')

		await wrapper.get('[data-testid="task-month-day"]').setValue('15')

		expect(last(wrapper).monthDay).toBe(15)
		expect(wrapper.find('[data-testid="task-rhythm-problem"]').exists()).toBe(false)
	})

	describe('flex days', () => {
		it('steps the flex days within the cap of the rhythm and explains them', async () => {
			const wrapper = mountField(rhythm({ flexDays: 0 }))
			const value = () => wrapper.get('[data-testid="task-flex-days"]').text()
			expect(value()).toBe('± 0')
			expect(wrapper.text()).toContain('Only the planned day counts as on time.')
			expect(wrapper.get('[data-testid="task-flex-less"]').element.disabled).toBe(true)

			await wrapper.get('[data-testid="task-flex-more"]').trigger('click')
			expect(value()).toBe('± 1')
			expect(wrapper.text()).toContain('One day earlier or later counts as on time.')

			for (let step = 0; step < 3; step++) {
				await wrapper.get('[data-testid="task-flex-more"]').trigger('click')
			}
			expect(value()).toBe('± 4')
			expect(wrapper.text()).toContain('4 days earlier or later count as on time.')
			// Half a week is the cap of a weekly rule.
			expect(wrapper.get('[data-testid="task-flex-more"]').element.disabled).toBe(true)

			await wrapper.get('[data-testid="task-flex-less"]').trigger('click')
			expect(value()).toBe('± 3')
		})

		it('names the cap when a shorter rhythm leaves the flex days above it', () => {
			const wrapper = mountField(rhythm({ unit: 'day', flexDays: 3 }))

			expect(wrapper.get('[data-testid="task-rhythm-problem"]').text()).toBe('Up to 1 flex days for this rhythm')
			expect(wrapper.get('[data-testid="task-flex-more"]').element.disabled).toBe(true)
		})

		it('keeps a stored value above the cap while nothing changes', () => {
			const stored = rhythm({ unit: 'day', flexDays: 3 })

			expect(mountField(stored, stored).find('[data-testid="task-rhythm-problem"]').exists()).toBe(false)
		})

		it('labels the steppers and the value', () => {
			const wrapper = mountField()
			const labelId = wrapper.get('[data-testid="task-flex-days"]').attributes('aria-labelledby')

			expect(wrapper.get('[data-testid="task-flex-less"]').attributes('aria-label')).toBe('Fewer flex days')
			expect(wrapper.get('[data-testid="task-flex-more"]').attributes('aria-label')).toBe('More flex days')
			expect(wrapper.get(`#${labelId}`).text()).toBe('Flex days')
		})
	})
})
