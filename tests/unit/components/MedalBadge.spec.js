import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createTestingPinia } from '@pinia/testing'
import { ref } from 'vue'
import MedalBadge from '../../../src/components/Common/MedalBadge.vue'

/**
 * Unit tests for MedalBadge component
 */

describe('MedalBadge', () => {
	const createWrapper = (props = {}) => {
		return mount(MedalBadge, {
			props: {
				type: 'team_anchor',
				...props,
			},
			global: {
				plugins: [createTestingPinia()],
				mocks: {
					t: (key) => key,
				},
			},
		})
	}

	describe('Props Validation', () => {
		it('renders with required type prop', () => {
			const wrapper = createWrapper({ type: 'team_anchor' })
			expect(wrapper.exists()).toBe(true)
		})

		it('accepts all valid medal types', () => {
			const validTypes = [
				'team_anchor',
				'flow_keeper',
				'room_guardian',
				'team_player',
				'streak_keeper',
			]

			validTypes.forEach((type) => {
				const wrapper = createWrapper({ type })
				expect(wrapper.exists()).toBe(true)
			})
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
		it('displays correct icon for team_anchor', () => {
			const wrapper = createWrapper({ type: 'team_anchor' })
			const icon = wrapper.find('.medal-icon')
			expect(icon.text()).toBe('🛟')
		})

		it('displays correct icon for flow_keeper', () => {
			const wrapper = createWrapper({ type: 'flow_keeper' })
			const icon = wrapper.find('.medal-icon')
			expect(icon.text()).toBe('🌊')
		})

		it('displays correct icon for room_guardian', () => {
			const wrapper = createWrapper({ type: 'room_guardian' })
			const icon = wrapper.find('.medal-icon')
			expect(icon.text()).toBe('🛡️')
		})

		it('displays correct icon for team_player', () => {
			const wrapper = createWrapper({ type: 'team_player' })
			const icon = wrapper.find('.medal-icon')
			expect(icon.text()).toBe('🤝')
		})

		it('displays correct icon for streak_keeper', () => {
			const wrapper = createWrapper({ type: 'streak_keeper' })
			const icon = wrapper.find('.medal-icon')
			expect(icon.text()).toBe('🔥')
		})

		it('shows medal type class', () => {
			const wrapper = createWrapper({ type: 'team_anchor' })
			expect(wrapper.classes()).toContain('medal-team_anchor')
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

		it('displays translated label text', () => {
			const wrapper = createWrapper({
				type: 'team_anchor',
				showLabel: true,
			})
			const label = wrapper.find('.medal-label')
			expect(label.exists()).toBe(true)
		})
	})

	describe('Accessibility', () => {
		it('has title attribute with label', () => {
			const wrapper = createWrapper({ type: 'team_anchor' })
			expect(wrapper.attributes('title')).toBeDefined()
		})
	})
})
