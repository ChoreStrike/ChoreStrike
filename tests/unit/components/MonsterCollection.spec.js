import { describe, it, expect, vi } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import MonsterCollection from '../../../src/components/Common/MonsterCollection.vue'
import MonsterSprite from '../../../src/components/Common/MonsterSprite.vue'

// Mock nextcloud l10n
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, text) => text,
}))

describe('MonsterCollection.vue', () => {
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
	})

	it('renders with header and count', () => {
		const monsters = [
			{ id: 1, type: 'dust-bunny', position: { x: 10, y: 20 } },
		]

		const wrapper = mount(MonsterCollection, {
			props: { monsters },
			global: {
				plugins: [pinia],
				components: { MonsterSprite },
			},
		})

		expect(wrapper.find('.monster-collection').exists()).toBe(true)
		expect(wrapper.find('.collection-header').exists()).toBe(true)
		expect(wrapper.find('.monster-count').text()).toBe('1')
	})

	it('renders MonsterSprite for each monster', () => {
		const monsters = [
			{ id: 1, type: 'dust-bunny', position: { x: 10, y: 20 } },
			{ id: 2, type: 'slime-blob', position: { x: 30, y: 40 } },
		]

		const wrapper = mount(MonsterCollection, {
			props: { monsters },
			global: {
				plugins: [pinia],
				components: { MonsterSprite },
			},
		})

		const sprites = wrapper.findAllComponents(MonsterSprite)
		expect(sprites).toHaveLength(2)
	})

	it('passes correct props to MonsterSprite', () => {
		const monsters = [
			{ id: 1, type: 'dust-bunny', position: { x: 10, y: 20 } },
		]

		const wrapper = mount(MonsterCollection, {
			props: { monsters, spriteSize: 'large', animated: true, showEffects: true },
			global: {
				plugins: [pinia],
				components: { MonsterSprite },
			},
		})

		const sprite = wrapper.findComponent(MonsterSprite)
		expect(sprite.props('monsterType')).toBe('dust-bunny')
		expect(sprite.props('size')).toBe('large')
		expect(sprite.props('animated')).toBe(true)
		expect(sprite.props('showEffects')).toBe(true)
	})

	it('displays threat level based on monster count', () => {
		const testCases = [
			{ monsters: [], expectedClass: 'none', expectedText: 'All Clear' },
			{ monsters: [{ id: 1, type: 'dust-bunny' }], expectedClass: 'low', expectedText: 'Minor Threat' },
			{ monsters: [{ id: 1, type: 'dust-bunny' }, { id: 2, type: 'slime-blob' }], expectedClass: 'medium', expectedText: 'Moderate Threat' },
			{ monsters: [{ id: 1 }, { id: 2 }, { id: 3 }], expectedClass: 'high', expectedText: 'High Threat' },
			{ monsters: [{ id: 1 }, { id: 2 }, { id: 3 }, { id: 4 }], expectedClass: 'critical', expectedText: 'Critical Infestation' },
		]

		testCases.forEach(({ monsters, expectedClass, expectedText }) => {
			const wrapper = mount(MonsterCollection, {
				props: { monsters, showSummary: true },
				global: {
					plugins: [pinia],
					components: { MonsterSprite },
				},
			})

			const threatLevel = wrapper.find('.threat-level')
			expect(threatLevel.classes()).toContain(expectedClass)
			expect(threatLevel.text()).toBe(expectedText)
		})
	})

	it('applies interactive class when interactive prop is true', () => {
		const wrapper = mount(MonsterCollection, {
			props: { monsters: [], interactive: true },
			global: {
				plugins: [pinia],
				components: { MonsterSprite },
			},
		})

		expect(wrapper.find('.monster-collection').classes()).toContain('interactive')
	})

	it('emits monster-click event when monster clicked', () => {
		const monsters = [
			{ id: 1, type: 'dust-bunny', position: { x: 10, y: 20 } },
		]

		const wrapper = mount(MonsterCollection, {
			props: { monsters, interactive: true },
			global: {
				plugins: [pinia],
				components: { MonsterSprite },
			},
		})

		const sprite = wrapper.findComponent(MonsterSprite)
		sprite.vm.$emit('click', { type: 'dust-bunny' })

		expect(wrapper.emitted('monster-click')).toBeTruthy()
	})

	it('applies stage dimensions style', () => {
		const wrapper = mount(MonsterCollection, {
			props: {
				monsters: [],
				stageDimensions: { width: 300, height: 200 },
			},
			global: {
				plugins: [pinia],
				components: { MonsterSprite },
			},
		})

		const stage = wrapper.find('.monsters-stage')
		expect(stage.attributes('style')).toContain('width: 300px')
		expect(stage.attributes('style')).toContain('height: 200px')
	})

	it('hides summary when showSummary is false', () => {
		const wrapper = mount(MonsterCollection, {
			props: { monsters: [], showSummary: false },
			global: {
				plugins: [pinia],
				components: { MonsterSprite },
			},
		})

		expect(wrapper.find('.collection-summary').exists()).toBe(false)
	})

	it('displays correct monster emoji in header', () => {
		const wrapper = mount(MonsterCollection, {
			props: { monsters: [] },
			global: {
				plugins: [pinia],
				components: { MonsterSprite },
			},
		})

		expect(wrapper.find('.header-icon').text()).toBe('👾')
	})
})
