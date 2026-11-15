/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach, afterEach, jest } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'
import AppearanceSettings from '../../../src/components/Settings/AppearanceSettings.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@/utils/logger', () => ({ logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() } }))
jest.mock('../../../src/components/Common/Icon.vue', () => ({ name: 'Icon', props: ['name', 'size'], template: '<span class="icon">{{ name }}</span>' }))

const mockPreferences = reactive({ colorScheme: 'standard', saveColorScheme: jest.fn() })
jest.mock('../../../src/store/preferences.js', () => ({ usePreferencesStore: () => mockPreferences }))

const option = (wrapper, key) => wrapper.get(`[data-testid="color-scheme-${key}"]`)

/** CS-PLAN-090 WP-K (FEAT-UX-057): the personal Appearance category. */
describe('AppearanceSettings', () => {
	beforeEach(() => {
		mockPreferences.colorScheme = 'standard'
		mockPreferences.saveColorScheme.mockReset().mockImplementation(async (scheme) => {
			mockPreferences.colorScheme = scheme
		})
	})

	afterEach(() => {
		document.body.removeAttribute('data-theme-light-highcontrast')
		delete window.matchMedia
	})

	it('offers Standard and the five schemes as one radio group, each swatch keyed to its palette', () => {
		const wrapper = mount(AppearanceSettings)

		const labels = wrapper.findAll('.scheme-option').map((label) => label.get('.scheme-name').text())
		expect(labels).toEqual(['Standard', 'Ocean', 'Forest', 'Sunset', 'Berry', 'Slate'])
		const radios = wrapper.findAll('input[type="radio"]')
		expect(radios).toHaveLength(6)
		expect(new Set(radios.map((radio) => radio.attributes('name')))).toEqual(new Set(['color-scheme']))
		expect(wrapper.get('fieldset legend').text()).toContain('accent colour')
		expect(option(wrapper, 'berry').get('.scheme-swatch').attributes('data-cs-swatch')).toBe('berry')
	})

	it('marks the stored scheme as chosen', () => {
		mockPreferences.colorScheme = 'forest'
		const wrapper = mount(AppearanceSettings)

		expect(option(wrapper, 'forest').get('input').element.checked).toBe(true)
		expect(option(wrapper, 'forest').classes()).toContain('selected')
		expect(option(wrapper, 'forest').find('.icon').exists()).toBe(true)
		expect(option(wrapper, 'standard').get('input').element.checked).toBe(false)
	})

	it('saves a choice and moves the mark', async () => {
		const wrapper = mount(AppearanceSettings)

		await option(wrapper, 'sunset').get('input').trigger('change')
		await flushPromises()

		expect(mockPreferences.saveColorScheme).toHaveBeenCalledWith('sunset')
		expect(option(wrapper, 'sunset').classes()).toContain('selected')
		expect(wrapper.find('[data-testid="color-scheme-error"]').exists()).toBe(false)
	})

	it('reports a failed save at the form and clears the report on the next try', async () => {
		mockPreferences.saveColorScheme.mockRejectedValueOnce(new Error('offline'))
		const wrapper = mount(AppearanceSettings)

		await option(wrapper, 'ocean').get('input').trigger('change')
		await flushPromises()
		expect(wrapper.get('[data-testid="color-scheme-error"]').attributes('role')).toBe('alert')

		await option(wrapper, 'slate').get('input').trigger('change')
		await flushPromises()
		expect(wrapper.find('[data-testid="color-scheme-error"]').exists()).toBe(false)
	})

	it('explains under a high-contrast theme that the app keeps the standard colours', () => {
		expect(mount(AppearanceSettings).find('[data-testid="color-scheme-high-contrast"]').exists()).toBe(false)

		document.body.setAttribute('data-theme-light-highcontrast', '')
		window.matchMedia = jest.fn(() => ({ matches: false }))

		expect(mount(AppearanceSettings).find('[data-testid="color-scheme-high-contrast"]').exists()).toBe(true)
	})
})
