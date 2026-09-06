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

	it('highlights the preset id passed via the prop (CS-PLAN-063 D4)', async () => {
		const wrapper = mount(RecurrencePresetSelector, {
			props: { selectedTemplate: 'weekly' },
		})
		const buttons = wrapper.findAll('button')

		// The 'weekly' preset is the second button.
		expect(buttons[1].classes()).toContain('active')
		expect(buttons[0].classes()).not.toContain('active')

		// Clicking does NOT select locally — the parent owns the state.
		await buttons[0].trigger('click')
		expect(wrapper.emitted('select')[0][0].id).toBe('daily')
		expect(buttons[0].classes()).not.toContain('active')
	})
})
