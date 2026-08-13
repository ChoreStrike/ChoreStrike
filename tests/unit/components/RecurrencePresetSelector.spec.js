import { mount } from '@vue/test-utils'
import RecurrencePresetSelector from '@/components/Tasks/RecurrencePresetSelector.vue'

describe('RecurrencePresetSelector', () => {
	it('renders template options', () => {
		const wrapper = mount(RecurrencePresetSelector)
		const buttons = wrapper.findAll('button')
		expect(buttons.length).toBeGreaterThanOrEqual(4)
	})

	it('emits select with template data on click', async () => {
		const wrapper = mount(RecurrencePresetSelector)
		const buttons = wrapper.findAll('button')
		await buttons[0].trigger('click')

		expect(wrapper.emitted('select')).toBeTruthy()
		const emitted = wrapper.emitted('select')[0][0]
		expect(emitted).toHaveProperty('id')
		expect(emitted).toHaveProperty('name')
		expect(emitted).toHaveProperty('recurrence')
	})

	it('highlights selected template', async () => {
		const wrapper = mount(RecurrencePresetSelector)
		const buttons = wrapper.findAll('button')
		await buttons[0].trigger('click')

		expect(buttons[0].classes()).toContain('active')
	})
})
