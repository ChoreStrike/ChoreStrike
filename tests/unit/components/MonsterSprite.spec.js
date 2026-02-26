import { describe, it, expect, vi } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import MonsterSprite from '../../../src/components/Common/MonsterSprite.vue'

// Mock nextcloud l10n
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, text) => text,
}))

describe('MonsterSprite.vue', () => {
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
	})

	it('renders with monster type', () => {
		const wrapper = mount(MonsterSprite, {
			props: { monsterType: 'dust-bunny' },
			global: { plugins: [pinia] },
		})

		expect(wrapper.find('.monster-sprite').exists()).toBe(true)
		expect(wrapper.find('.monster-sprite').classes()).toContain('dust-bunny')
	})

	it('displays correct emoji for each monster type', () => {
		const testCases = [
			{ type: 'dust-bunny', emoji: '🐰' },
			{ type: 'slime-blob', emoji: '🟢' },
			{ type: 'pixel-spider', emoji: '🕷️' },
			{ type: 'dust-demon', emoji: '👹' },
		]

		testCases.forEach(({ type, emoji }) => {
			const wrapper = mount(MonsterSprite, {
				props: { monsterType: type },
				global: { plugins: [pinia] },
			})

			expect(wrapper.find('.sprite-emoji').text()).toBe(emoji)
		})
	})

	it('applies size classes', () => {
		const sizes = ['small', 'medium', 'large', 'xl']

		sizes.forEach((size) => {
			const wrapper = mount(MonsterSprite, {
				props: { monsterType: 'dust-bunny', size },
				global: { plugins: [pinia] },
			})

			expect(wrapper.find('.monster-sprite').classes()).toContain(size)
		})
	})

	it('positions sprite correctly', () => {
		const wrapper = mount(MonsterSprite, {
			props: {
				monsterType: 'dust-bunny',
				position: { x: 25, y: 75 },
			},
			global: { plugins: [pinia] },
		})

		const style = wrapper.find('.monster-sprite').attributes('style')
		expect(style).toContain('left: 25%')
		expect(style).toContain('top: 75%')
	})

	it('applies animated class when animated is true', () => {
		const wrapper = mount(MonsterSprite, {
			props: { monsterType: 'dust-bunny', animated: true },
			global: { plugins: [pinia] },
		})

		expect(wrapper.find('.monster-sprite').classes()).toContain('animated')
	})

	it('shows label on hover when showLabel is true', () => {
		const wrapper = mount(MonsterSprite, {
			props: { monsterType: 'dust-bunny', showLabel: true },
			global: { plugins: [pinia] },
		})

		expect(wrapper.find('.sprite-label').exists()).toBe(true)
		expect(wrapper.find('.sprite-label').text()).toBe('Dust Bunny')
	})

	it('shows effects when showEffects is true', () => {
		const wrapper = mount(MonsterSprite, {
			props: { monsterType: 'dust-bunny', showEffects: true },
			global: { plugins: [pinia] },
		})

		expect(wrapper.find('.sprite-effects').exists()).toBe(true)
	})

	it('applies glow intensity classes', () => {
		const intensities = ['low', 'medium', 'high']

		intensities.forEach((intensity) => {
			const wrapper = mount(MonsterSprite, {
				props: {
					monsterType: 'dust-bunny',
					showEffects: true,
					glowIntensity: intensity,
				},
				global: { plugins: [pinia] },
			})

			expect(wrapper.find('.effect-glow').classes()).toContain(intensity)
		})
	})

	it('emits click event when clickable and clicked', () => {
		const wrapper = mount(MonsterSprite, {
			props: { monsterType: 'dust-bunny', clickable: true },
			global: { plugins: [pinia] },
		})

		wrapper.find('.monster-sprite').trigger('click')
		expect(wrapper.emitted('click')).toBeTruthy()
	})

	it('does not emit click when not clickable', () => {
		const wrapper = mount(MonsterSprite, {
			props: { monsterType: 'dust-bunny', clickable: false },
			global: { plugins: [pinia] },
		})

		wrapper.find('.monster-sprite').trigger('click')
		// Event is emitted but handler does nothing
		expect(wrapper.emitted('click')).toBeFalsy()
	})

	it('applies clickable class when clickable is true', () => {
		const wrapper = mount(MonsterSprite, {
			props: { monsterType: 'dust-bunny', clickable: true },
			global: { plugins: [pinia] },
		})

		expect(wrapper.find('.monster-sprite').classes()).toContain('clickable')
	})

	it('includes monster data in click event', () => {
		const wrapper = mount(MonsterSprite, {
			props: { monsterType: 'dust-bunny', clickable: true },
			global: { plugins: [pinia] },
		})

		wrapper.find('.monster-sprite').trigger('click')
		const emitted = wrapper.emitted('click')
		expect(emitted[0][0]).toMatchObject({
			type: 'dust-bunny',
			label: 'Dust Bunny',
		})
	})
})
