import { describe, it, expect, jest } from '@jest/globals'
import { mount } from '@vue/test-utils'
import TaskEnergyField from '../../../src/components/Tasks/TaskEnergyField.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, params) => Object.entries(params ?? {})
		.reduce((result, [key, value]) => result.split(`{${key}}`).join(String(value)), text)),
}))

const mountField = (modelValue) => {
	const wrapper = mount(TaskEnergyField, {
		props: {
			modelValue,
			'onUpdate:modelValue': (value) => wrapper.setProps({ modelValue: value }),
		},
		global: { stubs: { CsIcon: true } },
	})
	return wrapper
}

const select = (wrapper) => wrapper.get('[data-testid="task-energy-select"]')
const custom = (wrapper) => wrapper.find('[data-testid="task-energy-custom"]')

describe('TaskEnergyField', () => {
	it('lists 1 to 5 as bolts with their approximate time', () => {
		const labels = mountField(2).findAll('option').map((option) => option.text())

		expect(labels.slice(0, 5)).toEqual([
			'⚡ about 10 min',
			'⚡⚡ about 20 min',
			'⚡⚡⚡ about 30 min',
			'⚡⚡⚡⚡ about 40 min',
			'⚡⚡⚡⚡⚡ about 50 min',
		])
		expect(labels[5]).toBe('Longer task (6 to 20)')
	})

	it('keeps the custom value and the hint away from a listed energy', async () => {
		const wrapper = mountField(2)

		await select(wrapper).setValue('4')

		expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([4])
		expect(custom(wrapper).exists()).toBe(false)
		expect(wrapper.find('[data-testid="task-energy-hint"]').exists()).toBe(false)
	})

	it('opens the custom value with the budget hint for a longer task', async () => {
		const wrapper = mountField(2)

		await select(wrapper).setValue('longer')

		expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([6])
		expect(custom(wrapper).element.value).toBe('6')
		expect(wrapper.get('[data-testid="task-energy-hint"]').text()).toContain('10 energy on weekdays and 15 at weekends')
	})

	it('shows a stored energy above the list as a longer task', () => {
		const wrapper = mountField(12)

		expect(select(wrapper).element.value).toBe('longer')
		expect(custom(wrapper).element.value).toBe('12')
		expect(wrapper.text()).toContain('about 120 min')
	})

	it('keeps the input open on the way to a two-digit value', async () => {
		const wrapper = mountField(6)

		await custom(wrapper).setValue('1')

		expect(wrapper.emitted('update:modelValue')).toBeUndefined()
		expect(custom(wrapper).exists()).toBe(true)

		await custom(wrapper).setValue('12')

		expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([12])
	})

	it('passes on nothing above the limit and leaves the input to refuse it', async () => {
		const wrapper = mountField(6)

		await custom(wrapper).setValue('21')

		expect(wrapper.emitted('update:modelValue')).toBeUndefined()
		expect(custom(wrapper).attributes('max')).toBe('20')
		expect(custom(wrapper).attributes('min')).toBe('6')
	})

	it('goes back to the list from a longer task', async () => {
		const wrapper = mountField(12)

		await select(wrapper).setValue('3')

		expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([3])
		expect(custom(wrapper).exists()).toBe(false)
	})

	it('follows a value from outside, such as an applied template', async () => {
		const wrapper = mountField(12)

		await wrapper.setProps({ modelValue: 2 })

		expect(select(wrapper).element.value).toBe('2')
		expect(custom(wrapper).exists()).toBe(false)
	})
})
