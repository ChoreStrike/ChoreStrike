import { describe, it, expect, vi } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ComboNotification from '../../../src/components/Common/ComboNotification.vue'

// Mock nextcloud l10n
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, text) => text,
}))

describe('ComboNotification.vue', () => {
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		jest.useFakeTimers()
	})

	afterEach(() => {
		jest.useRealTimers()
	})

	it('renders combo notifications', () => {
		const combos = [
			{ id: 1, type: 'speed', description: '3 tasks in 10 minutes', multiplier: 2 },
		]

		const wrapper = mount(ComboNotification, {
			props: { combos },
			global: { plugins: [pinia] },
		})

		expect(wrapper.find('.combo-notifications').exists()).toBe(true)
		expect(wrapper.find('.combo-notification').exists()).toBe(true)
	})

	it('displays combo icon based on type', () => {
		const combos = [
			{ id: 1, type: 'speed', description: 'Fast!', multiplier: 2 },
		]

		const wrapper = mount(ComboNotification, {
			props: { combos },
			global: { plugins: [pinia] },
		})

		expect(wrapper.find('.combo-icon').text()).toBe('⚡')
	})

	it('displays title based on combo type', () => {
		const combos = [
			{ id: 1, type: 'streak', description: 'On fire!', multiplier: 3 },
		]

		const wrapper = mount(ComboNotification, {
			props: { combos },
			global: { plugins: [pinia] },
		})

		expect(wrapper.find('.combo-title').text()).toContain('Streak Combo!')
	})

	it('displays multiplier', () => {
		const combos = [
			{ id: 1, type: 'team', description: 'Working together!', multiplier: 1.5 },
		]

		const wrapper = mount(ComboNotification, {
			props: { combos },
			global: { plugins: [pinia] },
		})

		expect(wrapper.find('.combo-multiplier').text()).toContain('×1.5')
	})

	it('displays description', () => {
		const combos = [
			{ id: 1, type: 'room', description: 'Cleaned all rooms!', multiplier: 2 },
		]

		const wrapper = mount(ComboNotification, {
			props: { combos },
			global: { plugins: [pinia] },
		})

		expect(wrapper.find('.combo-description').text()).toBe('Cleaned all rooms!')
	})

	it('applies type-specific class', () => {
		const combos = [
			{ id: 1, type: 'speed', description: 'Fast!', multiplier: 2 },
		]

		const wrapper = mount(ComboNotification, {
			props: { combos },
			global: { plugins: [pinia] },
		})

		expect(wrapper.find('.combo-notification').classes()).toContain('speed')
	})

	it('renders multiple combos', () => {
		const combos = [
			{ id: 1, type: 'speed', description: 'Fast!', multiplier: 2 },
			{ id: 2, type: 'streak', description: 'On fire!', multiplier: 3 },
		]

		const wrapper = mount(ComboNotification, {
			props: { combos },
			global: { plugins: [pinia] },
		})

		const notifications = wrapper.findAll('.combo-notification')
		expect(notifications).toHaveLength(2)
	})

	it('emits combo-expired event after duration', () => {
		const combos = [
			{ id: 1, type: 'speed', description: 'Fast!', multiplier: 2 },
		]

		const wrapper = mount(ComboNotification, {
			props: { combos, duration: 5000 },
			global: { plugins: [pinia] },
		})

		// Fast-forward past duration
		vi.advanceTimersByTime(5100)

		expect(wrapper.emitted('combo-expired')).toBeTruthy()
		expect(wrapper.emitted('combo-expired')[0]).toEqual([1])
	})

	it('displays progress bar', () => {
		const combos = [
			{ id: 1, type: 'speed', description: 'Fast!', multiplier: 2 },
		]

		const wrapper = mount(ComboNotification, {
			props: { combos },
			global: { plugins: [pinia] },
		})

		expect(wrapper.find('.combo-progress').exists()).toBe(true)
		expect(wrapper.find('.progress-bar').exists()).toBe(true)
	})

	it('defaults to mystery combo for unknown type', () => {
		const combos = [
			{ id: 1, type: 'unknown', description: 'Mystery!', multiplier: 1.5 },
		]

		const wrapper = mount(ComboNotification, {
			props: { combos },
			global: { plugins: [pinia] },
		})

		expect(wrapper.find('.combo-title').text()).toContain('Mystery Combo!')
		expect(wrapper.find('.combo-icon').text()).toBe('✨')
	})

	it('sets aria-live attribute for accessibility', () => {
		const combos = []

		const wrapper = mount(ComboNotification, {
			props: { combos, announce: true },
			global: { plugins: [pinia] },
		})

		expect(wrapper.find('.combo-notifications').attributes('aria-live')).toBe('polite')
	})

	it('has correct aria-atomic attribute', () => {
		const combos = []

		const wrapper = mount(ComboNotification, {
			props: { combos },
			global: { plugins: [pinia] },
		})

		expect(wrapper.find('.combo-notifications').attributes('aria-atomic')).toBe('true')
	})
})
