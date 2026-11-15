/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import {
	applyColorScheme,
	initialColorScheme,
	isHighContrast,
	normalizeColorScheme,
} from '../../../src/utils/colorSchemes.js'

/**
 * CS-PLAN-090 WP-K (D-090-67): the scheme list, the page's initial state,
 * the high-contrast rule and the body attribute.
 */
describe('colorSchemes', () => {
	const body = (attributes = []) => {
		const element = document.createElement('body')
		for (const name of attributes) {
			element.setAttribute(name, '')
		}
		return element
	}
	const media = (matches) => jest.fn(() => ({ matches }))

	afterEach(() => {
		document.querySelectorAll('input[id^="initial-state-"]').forEach((input) => input.remove())
		delete window._nc_initial_state
	})

	it('keeps a listed scheme and reads anything else as Standard', () => {
		expect(normalizeColorScheme('forest')).toBe('forest')
		for (const value of ['Forest', 'neon', '', null, undefined, 3]) {
			expect(normalizeColorScheme(value)).toBe('standard')
		}
	})

	it('reads the scheme the page was delivered with', () => {
		const input = document.createElement('input')
		input.type = 'hidden'
		input.id = 'initial-state-chorestrike-color-scheme'
		input.value = btoa(JSON.stringify('berry'))
		document.body.appendChild(input)

		expect(initialColorScheme()).toBe('berry')
	})

	it('falls back to Standard without an initial state or with an unknown one', () => {
		expect(initialColorScheme()).toBe('standard')

		window._nc_initial_state = new Map([['#initial-state-chorestrike-color-scheme', 'neon']])
		expect(initialColorScheme()).toBe('standard')
	})

	it('treats a chosen high-contrast theme as high contrast, in light and dark', () => {
		expect(isHighContrast(body(['data-theme-light-highcontrast']), media(false))).toBe(true)
		expect(isHighContrast(body(['data-theme-dark-highcontrast']), media(false))).toBe(true)
	})

	it('follows the system\'s contrast preference only under the default theme', () => {
		expect(isHighContrast(body(['data-theme-default']), media(true))).toBe(true)
		expect(isHighContrast(body(['data-theme-default']), media(false))).toBe(false)
		// An explicit light or dark theme overrides Nextcloud's media themes.
		expect(isHighContrast(body(['data-theme-light']), media(true))).toBe(false)
		expect(isHighContrast(body(['data-theme-dark']), media(true))).toBe(false)
		expect(isHighContrast(body(['data-theme-default']), undefined)).toBe(false)
	})

	it('puts a scheme on the body and takes it off for Standard', () => {
		const element = body(['data-theme-light'])

		expect(applyColorScheme('ocean', element, media(false))).toBe('ocean')
		expect(element.getAttribute('data-cs-scheme')).toBe('ocean')

		expect(applyColorScheme('standard', element, media(false))).toBe('standard')
		expect(element.hasAttribute('data-cs-scheme')).toBe(false)

		applyColorScheme('slate', element, media(false))
		expect(applyColorScheme('neon', element, media(false))).toBe('standard')
		expect(element.hasAttribute('data-cs-scheme')).toBe(false)
	})

	it('yields to Standard under a high-contrast theme', () => {
		const element = body(['data-theme-light-highcontrast'])

		expect(applyColorScheme('sunset', element, media(false))).toBe('standard')
		expect(element.hasAttribute('data-cs-scheme')).toBe(false)
	})
})
