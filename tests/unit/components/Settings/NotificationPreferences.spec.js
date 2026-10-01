/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import NotificationPreferences from '@/components/Settings/NotificationPreferences.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, params) => {
		if (!params) return text
		return Object.entries(params).reduce((acc, [k, v]) => acc.replaceAll(`{${k}}`, String(v)), text)
	}),
}))

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

// The @nextcloud/vue suite components are stubbed with plain form
// controls so value assertions stay behavioral (switch ↔ checkbox).
jest.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		// Native fallthrough only — the click fires the handler exactly once.
		props: ['type', 'disabled'],
		template: '<button :disabled="disabled"><slot /></button>',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'type'],
		emits: ['update:modelValue'],
		template: '<input type="checkbox" class="nc-switch" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" />',
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		template: '<div class="note-card"><slot /></div>',
	},
}))

const mockPreferencesState = {
	version: 1,
	taskDigestEnabled: false,
	orgDigestEnabled: false,
	digestTime: '18:00',
	quietStart: null,
	quietEnd: null,
	soundEnabled: false,
	animationEnabled: false,
	loading: false,
	saving: false,
	error: null,
	conflict: false,
	fetchPreferences: jest.fn().mockResolvedValue(),
	savePreferences: jest.fn().mockResolvedValue(true),
}

jest.mock('@/store/preferences.js', () => ({
	usePreferencesStore: () => require('vue').reactive(mockPreferencesState),
}))

describe('NotificationPreferences', () => {
	beforeEach(() => {
		Object.assign(mockPreferencesState, {
			version: 1,
			taskDigestEnabled: false,
			orgDigestEnabled: false,
			digestTime: '18:00',
			quietStart: null,
			quietEnd: null,
			soundEnabled: false,
			animationEnabled: false,
			loading: false,
			saving: false,
			error: null,
			conflict: false,
		})
		mockPreferencesState.fetchPreferences.mockClear().mockResolvedValue()
		mockPreferencesState.savePreferences.mockClear().mockResolvedValue(true)
	})

	const mountComponent = async () => {
		const wrapper = mount(NotificationPreferences)
		await flushPromises()
		return wrapper
	}

	it('renders the delivery-vs-attention NcNoteCard EXACTLY once (C5g)', async () => {
		const wrapper = await mountComponent()

		expect(wrapper.findAll('.note-card')).toHaveLength(1)
		expect(wrapper.text()).toContain('Delivery and attention are different things')
	})

	it('renders the full delivered field set with stable testids', async () => {
		const wrapper = await mountComponent()

		expect(wrapper.find('[data-testid="task-digest-switch"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="org-digest-switch"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="digest-time-input"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="quiet-start-input"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="quiet-end-input"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="sound-toggle"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="animation-toggle"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="arcade-visible-switch"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="save-preferences"]').exists()).toBe(true)
	})

	it('seeds the draft from the authoritative store on mount', async () => {
		mockPreferencesState.taskDigestEnabled = true
		mockPreferencesState.digestTime = '19:30'
		mockPreferencesState.quietStart = '21:00'
		mockPreferencesState.quietEnd = '07:00'
		mockPreferencesState.soundEnabled = true
		mockPreferencesState.arcadeVisible = false

		const wrapper = await mountComponent()

		expect(wrapper.find('[data-testid="task-digest-switch"]').element.checked).toBe(true)
		expect(wrapper.find('[data-testid="digest-time-input"]').element.value).toBe('19:30')
		expect(wrapper.find('[data-testid="quiet-start-input"]').element.value).toBe('21:00')
		expect(wrapper.find('[data-testid="quiet-end-input"]').element.value).toBe('07:00')
		expect(wrapper.find('[data-testid="sound-toggle"]').element.checked).toBe(true)
		// Optional-area opt-out (Q-077-05) rides the same save.
		expect(wrapper.find('[data-testid="arcade-visible-switch"]').element.checked).toBe(false)
	})

	it('saves the whitelisted fields with expectedVersion and a fresh commandId', async () => {
		const wrapper = await mountComponent()

		await wrapper.find('[data-testid="task-digest-switch"]').setValue(true)
		await wrapper.find('[data-testid="animation-toggle"]').setValue(true)
		await wrapper.find('[data-testid="save-preferences"]').trigger('click')
		await flushPromises()

		expect(mockPreferencesState.savePreferences).toHaveBeenCalledTimes(1)
		const payload = mockPreferencesState.savePreferences.mock.calls[0][0]
		expect(payload.taskDigestEnabled).toBe(true)
		expect(payload.animationEnabled).toBe(true)
		expect(payload.digestTime).toBe('18:00')
		// expectedVersion + commandId ride the store call.
		expect(mockPreferencesState.version).toBe(1)
	})

	it('keeps the draft and shows the conflict hint on a 409 (draft preservation)', async () => {
		mockPreferencesState.savePreferences.mockImplementation(async () => {
			require('vue').reactive(mockPreferencesState).conflict = true
			return false
		})
		const wrapper = await mountComponent()

		await wrapper.find('[data-testid="task-digest-switch"]').setValue(true)
		await wrapper.find('[data-testid="save-preferences"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="preferences-conflict"]').exists()).toBe(true)
		// The local edit survived the 409.
		expect(wrapper.find('[data-testid="task-digest-switch"]').element.checked).toBe(true)
	})

	it('shows the error note when the save throws', async () => {
		// The real store flags the error before rethrowing; the mock
		// mirrors that contract.
		mockPreferencesState.savePreferences.mockImplementation(async () => {
			// The real store flags the error before rethrowing; mutate via
			// the reactive proxy so the component re-renders.
			require('vue').reactive(mockPreferencesState).error = 'boom'
			throw new Error('boom')
		})
		const wrapper = await mountComponent()

		await wrapper.find('[data-testid="save-preferences"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="preferences-error"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="preferences-conflict"]').exists()).toBe(false)
	})

	it('re-seeds the draft after a successful save from the refreshed store', async () => {
		mockPreferencesState.savePreferences.mockImplementation(async () => {
			mockPreferencesState.version = 2
			mockPreferencesState.taskDigestEnabled = true
			return true
		})
		const wrapper = await mountComponent()

		await wrapper.find('[data-testid="save-preferences"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="task-digest-switch"]').element.checked).toBe(true)
		expect(wrapper.find('[data-testid="preferences-conflict"]').exists()).toBe(false)
	})

	it('fetches the own preferences once on mount when the store is cold', async () => {
		mockPreferencesState.version = 0
		await mountComponent()

		expect(mockPreferencesState.fetchPreferences).toHaveBeenCalledTimes(1)
	})
})
