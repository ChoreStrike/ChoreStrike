import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import MedalBadge, { MEDAL_LABELS } from '../../../src/components/Common/MedalBadge.vue'

/**
 * Unit tests for MedalBadge component (WP4 label cutover: only the
 * retained rhythm tiers and the weekly milestone remain).
 */

describe('MedalBadge', () => {
	const createWrapper = (props = {}) => {
		const pinia = createPinia()
		setActivePinia(pinia)

		return mount(MedalBadge, {
			props: {
				type: 'rhythm_3',
				...props,
			},
			global: {
				plugins: [pinia],
			},
		})
	}

	describe('Props Validation', () => {
		it('renders with required type prop', () => {
			const wrapper = createWrapper({ type: 'rhythm_3' })
			expect(wrapper.exists()).toBe(true)
		})

		it('accepts only the retained medal types', () => {
			const validTypes = ['rhythm_3', 'rhythm_6', 'rhythm_12', 'weekly_milestone']

			validTypes.forEach((type) => {
				const wrapper = createWrapper({ type })
				expect(wrapper.exists()).toBe(true)
			})
		})

		it('exposes exactly the retained label mapping', () => {
			expect(Object.keys(MEDAL_LABELS).sort()).toEqual([
				'rhythm_12',
				'rhythm_3',
				'rhythm_6',
				'weekly_milestone',
			])
		})

		it('has correct default size', () => {
			const wrapper = createWrapper()
			expect(wrapper.classes()).toContain('medal-medium')
		})

		it('applies size prop correctly', () => {
			const wrapper = createWrapper({ size: 'small' })
			expect(wrapper.classes()).toContain('medal-small')
		})

		it('accepts all valid size values', () => {
			const sizes = ['small', 'medium', 'large']
			sizes.forEach((size) => {
				const wrapper = createWrapper({ size })
				expect(wrapper.classes()).toContain(`medal-${size}`)
			})
		})
	})

	describe('Rendering', () => {
		it('displays the rhythm icon for the retained tiers', () => {
			;['rhythm_3', 'rhythm_6', 'rhythm_12'].forEach((type) => {
				const wrapper = createWrapper({ type })
				expect(wrapper.find('.medal-icon').text()).toBe('🔥')
			})
		})

		it('shows medal type class', () => {
			const wrapper = createWrapper({ type: 'rhythm_6' })
			expect(wrapper.classes()).toContain('medal-rhythm_6')
		})

		it('has medal-badge base class', () => {
			const wrapper = createWrapper()
			expect(wrapper.classes()).toContain('medal-badge')
		})
	})

	describe('Label Display', () => {
		it('hides label by default', () => {
			const wrapper = createWrapper()
			expect(wrapper.find('.medal-label').exists()).toBe(false)
		})

		it('shows label when showLabel is true', () => {
			const wrapper = createWrapper({ showLabel: true })
			expect(wrapper.find('.medal-label').exists()).toBe(true)
		})

		it.each([
			['rhythm_3', 'Rhythm 3'],
			['rhythm_6', 'Rhythm 6'],
			['rhythm_12', 'Rhythm 12'],
			['weekly_milestone', 'Weekly Milestone'],
		])('renders a localized label for %s', (type, label) => {
			const wrapper = createWrapper({ type, showLabel: true })
			expect(wrapper.find('.medal-label').text()).toBe(label)
			expect(wrapper.attributes('title')).toBe(label)
			expect(wrapper.find('.medal-icon').text()).not.toBe('')
		})
	})

	describe('Accessibility', () => {
		it('has title attribute with label', () => {
			const wrapper = createWrapper({ type: 'rhythm_3' })
			expect(wrapper.attributes('title')).toBeDefined()
		})
	})
})
