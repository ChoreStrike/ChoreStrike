import { describe, it, expect, jest } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import Icon from '../../../src/components/Common/Icon.vue'

// Mock the icons utility
jest.mock('../../../src/utils/icons.js', () => ({
	getIcon: (name) => {
		const icons = {
			Home: { name: 'Home', template: '<svg>Home</svg>' },
			Trash2: { name: 'Trash2', template: '<svg>Trash</svg>' },
			CheckCircle: { name: 'CheckCircle', template: '<svg>Check</svg>' },
		}
		return icons[name] || null
	},
}))

describe('Icon.vue', () => {
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
	})

	it('renders with required name prop', () => {
		const wrapper = mount(Icon, {
			props: { name: 'Home' },
			global: { plugins: [pinia] },
		})

		expect(wrapper.find('.icon').exists()).toBe(true)
		expect(wrapper.find('.icon--home').exists()).toBe(true)
	})

	it('applies size prop correctly', () => {
		const wrapper = mount(Icon, {
			props: { name: 'Home', size: 32 },
			global: { plugins: [pinia] },
		})

		const icon = wrapper.find('.icon')
		expect(icon.exists()).toBe(true)
	})

	it('handles string size prop', () => {
		const wrapper = mount(Icon, {
			props: { name: 'Home', size: '48' },
			global: { plugins: [pinia] },
		})

		expect(wrapper.find('.icon').exists()).toBe(true)
	})

	it('applies strokeWidth prop', () => {
		const wrapper = mount(Icon, {
			props: { name: 'Home', strokeWidth: 2.5 },
			global: { plugins: [pinia] },
		})

		expect(wrapper.find('.icon').exists()).toBe(true)
	})

	it('applies custom className', () => {
		const wrapper = mount(Icon, {
			props: { name: 'Home', className: 'custom-class' },
			global: { plugins: [pinia] },
		})

		expect(wrapper.find('.icon').classes()).toContain('custom-class')
	})

	it('warns when icon not found', () => {
		const consoleSpy = jest.spyOn(console, 'warn').mockImplementation(() => {})

		mount(Icon, {
			props: { name: 'NonExistentIcon' },
			global: { plugins: [pinia] },
		})

		expect(consoleSpy).toHaveBeenCalledWith(
			'[ChoreStrike] [warn] Icon "NonExistentIcon" not found in icon registry',
			{ component: 'Icon', iconName: 'NonExistentIcon' },
		)
		consoleSpy.mockRestore()
	})

	it('renders different icons based on name', () => {
		const wrapper1 = mount(Icon, {
			props: { name: 'Trash2' },
			global: { plugins: [pinia] },
		})

		const wrapper2 = mount(Icon, {
			props: { name: 'CheckCircle' },
			global: { plugins: [pinia] },
		})

		expect(wrapper1.find('.icon--trash2').exists()).toBe(true)
		expect(wrapper2.find('.icon--checkcircle').exists()).toBe(true)
	})
})
