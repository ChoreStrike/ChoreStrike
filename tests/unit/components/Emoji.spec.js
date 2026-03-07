import { describe, it, expect } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import Emoji from '../../../src/components/Common/Emoji.vue'

describe('Emoji.vue', () => {
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
	})

	it('renders with required name prop', () => {
		const wrapper = mount(Emoji, {
			props: { name: 'living-room' },
			global: { plugins: [pinia] },
		})

		const img = wrapper.find('img')
		expect(img.exists()).toBe(true)
		expect(img.classes()).toContain('emoji-image')
	})

	it('applies correct size', () => {
		const wrapper = mount(Emoji, {
			props: { name: 'monster', size: 128 },
			global: { plugins: [pinia] },
		})

		const img = wrapper.find('img')
		expect(img.attributes('width')).toBe('128')
		expect(img.attributes('height')).toBe('128')
	})

	it('handles string size prop', () => {
		const wrapper = mount(Emoji, {
			props: { name: 'monster', size: '64' },
			global: { plugins: [pinia] },
		})

		const img = wrapper.find('img')
		expect(img.attributes('width')).toBe('64')
		expect(img.attributes('height')).toBe('64')
	})

	it('applies default size of 64', () => {
		const wrapper = mount(Emoji, {
			props: { name: 'bedroom' },
			global: { plugins: [pinia] },
		})

		const img = wrapper.find('img')
		expect(img.attributes('width')).toBe('64')
		expect(img.attributes('height')).toBe('64')
	})

	it('uses alt prop when provided', () => {
		const wrapper = mount(Emoji, {
			props: { name: 'monster', alt: 'Chore Monster' },
			global: { plugins: [pinia] },
		})

		const img = wrapper.find('img')
		expect(img.attributes('alt')).toBe('Chore Monster')
	})

	it('uses name as alt when alt not provided', () => {
		const wrapper = mount(Emoji, {
			props: { name: 'kitchen' },
			global: { plugins: [pinia] },
		})

		const img = wrapper.find('img')
		expect(img.attributes('alt')).toBe('kitchen')
	})

	it('applies style variant classes', () => {
		const wrapper = mount(Emoji, {
			props: { name: 'trophy', emojiStyle: 'flat' },
			global: { plugins: [pinia] },
		})

		const img = wrapper.find('img')
		const src = img.attributes('src')
		expect(src).toContain('flat')
	})

	it('applies default 3d style', () => {
		const wrapper = mount(Emoji, {
			props: { name: 'trophy' },
			global: { plugins: [pinia] },
		})

		const img = wrapper.find('img')
		const src = img.attributes('src')
		expect(src).toContain('3d')
	})

	it('applies custom className', () => {
		const wrapper = mount(Emoji, {
			props: { name: 'star', className: 'reward-emoji' },
			global: { plugins: [pinia] },
		})

		const span = wrapper.find('span')
		expect(span.classes()).toContain('reward-emoji')
	})

	it('uses local URL by default', () => {
		const wrapper = mount(Emoji, {
			props: { name: 'fox', emojiStyle: '3d' },
			global: { plugins: [pinia] },
		})

		const img = wrapper.find('img')
		const src = img.attributes('src')
		expect(src).toContain('/apps/chorestrike/img/emojis/')
		expect(src).toContain('fox')
		expect(src).toContain('3d')
	})

	it('always uses local URL (local prop is deprecated)', () => {
		const wrapper = mount(Emoji, {
			props: { name: 'fox', local: false },
			global: { plugins: [pinia] },
		})

		const img = wrapper.find('img')
		const src = img.attributes('src')
		expect(src).toContain('/apps/chorestrike/img/emojis/')
		expect(src).toContain('fox')
	})

	it('maps emoji names correctly', () => {
		const wrapper = mount(Emoji, {
			props: { name: 'living-room' },
			global: { plugins: [pinia] },
		})

		const img = wrapper.find('img')
		const src = img.attributes('src')
		expect(src).toContain('couch_and_lamp')
	})

	it('falls back to direct name if not in map', () => {
		const wrapper = mount(Emoji, {
			props: { name: 'custom_emoji' },
			global: { plugins: [pinia] },
		})

		const img = wrapper.find('img')
		const src = img.attributes('src')
		expect(src).toContain('custom_emoji')
	})
})
