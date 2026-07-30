import { mount } from '@vue/test-utils'
import SvgIcon from '@/components/Common/SvgIcon.vue'

describe('SvgIcon', () => {
	const shapes = [
		{ type: 'circle', cx: 12, cy: 12, r: 10 },
		{ type: 'line', x1: 12, y1: 8, x2: 12, y2: 12 },
	]

	it('renders an svg element', () => {
		const wrapper = mount(SvgIcon, {
			props: { name: 'test', shapes },
		})
		expect(wrapper.find('svg').exists()).toBe(true)
	})

	it('applies size prop', () => {
		const wrapper = mount(SvgIcon, {
			props: { name: 'test', shapes, size: 32 },
		})
		const svg = wrapper.find('svg')
		expect(svg.attributes('width')).toBe('32')
		expect(svg.attributes('height')).toBe('32')
	})

	it('applies viewBox prop', () => {
		const wrapper = mount(SvgIcon, {
			props: { name: 'test', shapes, viewBox: '0 0 48 48' },
		})
		expect(wrapper.find('svg').attributes('viewBox')).toBe('0 0 48 48')
	})

	it('renders circle shapes', () => {
		const wrapper = mount(SvgIcon, {
			props: { name: 'test', shapes: [{ type: 'circle', cx: 12, cy: 12, r: 5 }] },
		})
		expect(wrapper.find('circle').exists()).toBe(true)
	})

	it('renders path shapes', () => {
		const wrapper = mount(SvgIcon, {
			props: { name: 'test', shapes: [{ type: 'path', d: 'M0 0 L24 24' }] },
		})
		expect(wrapper.find('path').exists()).toBe(true)
	})

	it('renders rect shapes', () => {
		const wrapper = mount(SvgIcon, {
			props: { name: 'test', shapes: [{ type: 'rect', x: 0, y: 0, width: 24, height: 24 }] },
		})
		expect(wrapper.find('rect').exists()).toBe(true)
	})

	it('renders multiple shapes', () => {
		const wrapper = mount(SvgIcon, {
			props: { name: 'test', shapes },
		})
		expect(wrapper.find('circle').exists()).toBe(true)
		expect(wrapper.find('line').exists()).toBe(true)
	})

	it('applies strokeWidth prop', () => {
		const wrapper = mount(SvgIcon, {
			props: { name: 'test', shapes, strokeWidth: 2.5 },
		})
		expect(wrapper.find('svg').attributes('stroke-width')).toBe('2.5')
	})
})
