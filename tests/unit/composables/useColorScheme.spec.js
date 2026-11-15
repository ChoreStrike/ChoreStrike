/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'
import { useColorScheme } from '../../../src/composables/useColorScheme.js'
import { usePreferencesStore } from '../../../src/store/preferences.js'

jest.mock('@/utils/logger', () => ({
	logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() },
}))

/**
 * CS-PLAN-090 WP-K: the app root keeps the body's scheme attribute in step
 * with the store, before the first render and after every change, and a
 * change of the system's contrast preference applies at once.
 */
describe('useColorScheme', () => {
	let listeners
	let prefersContrast

	const mountHost = () => mount({
		setup() {
			useColorScheme()
		},
		template: '<div />',
	})

	beforeEach(() => {
		setActivePinia(createPinia())
		listeners = []
		prefersContrast = false
		window.matchMedia = jest.fn(() => ({
			get matches() {
				return prefersContrast
			},
			addEventListener: (type, listener) => listeners.push(listener),
			removeEventListener: (type, listener) => {
				listeners = listeners.filter((other) => other !== listener)
			},
		}))
		document.body.setAttribute('data-theme-default', '')
	})

	afterEach(() => {
		document.body.removeAttribute('data-theme-default')
		document.body.removeAttribute('data-cs-scheme')
		delete window.matchMedia
	})

	it('applies the stored scheme before the first render and follows a change', async () => {
		usePreferencesStore().colorScheme = 'forest'

		const wrapper = mountHost()
		expect(document.body.getAttribute('data-cs-scheme')).toBe('forest')

		usePreferencesStore().colorScheme = 'standard'
		await nextTick()
		expect(document.body.hasAttribute('data-cs-scheme')).toBe(false)
		wrapper.unmount()
	})

	it('yields to Standard while the system asks for more contrast, and returns after', () => {
		usePreferencesStore().colorScheme = 'berry'
		const wrapper = mountHost()

		prefersContrast = true
		listeners.forEach((listener) => listener())
		expect(document.body.hasAttribute('data-cs-scheme')).toBe(false)

		prefersContrast = false
		listeners.forEach((listener) => listener())
		expect(document.body.getAttribute('data-cs-scheme')).toBe('berry')
		wrapper.unmount()
	})

	it('removes the attribute and its listener when the app unmounts', () => {
		usePreferencesStore().colorScheme = 'ocean'
		const wrapper = mountHost()
		expect(listeners).toHaveLength(1)

		wrapper.unmount()

		expect(document.body.hasAttribute('data-cs-scheme')).toBe(false)
		expect(listeners).toHaveLength(0)
	})
})
