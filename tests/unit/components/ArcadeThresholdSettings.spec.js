/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach, afterEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ArcadeThresholdSettings from '@/components/Minigames/ArcadeThresholdSettings.vue'
import { useMinigameStore } from '@/store/minigame.js'

jest.mock('@/services/api.js', () => ({
	api: { get: jest.fn(), put: jest.fn(), post: jest.fn() },
}))

jest.mock('@/utils/logger', () => ({
	logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() },
}))

const { api } = require('@/services/api.js')

const catalog = (thresholds) => ({
	gateEnabled: true,
	finishedChores: 0,
	games: Object.entries(thresholds).map(([gameId, threshold]) => ({ gameId, threshold, unlocked: threshold === 0 })),
})

const DEFAULTS = { spots: 0, socks: 3, snake: 10, tiles: 25, dust: 50 }

/**
 * CS-PLAN-090 WP-J (D-090-66): one whole number from 0 to 500 per game,
 * saved together; a catalog arriving during an edit never overwrites it.
 */
describe('ArcadeThresholdSettings', () => {
	let wrapper
	let store

	const field = (gameId) => wrapper.get(`[data-testid="arcade-threshold-${gameId}"]`)
	const saveButton = () => wrapper.get('[data-testid="arcade-thresholds-save"]')

	const mountWith = async (thresholds = DEFAULTS) => {
		store = useMinigameStore()
		store.catalog = thresholds === null ? null : catalog(thresholds)
		wrapper = mount(ArcadeThresholdSettings)
		await flushPromises()
	}

	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
	})

	afterEach(() => {
		wrapper?.unmount()
	})

	it('shows each game with its name and current threshold, and saves nothing unchanged', async () => {
		await mountWith()

		expect(field('socks').element.value).toBe('3')
		expect(wrapper.get('label[for="arcade-threshold-socks"]').text()).toBe('Sock Match')
		expect(field('dust').attributes('max')).toBe('500')
		expect(saveButton().attributes('disabled')).toBeDefined()
		expect(api.get).not.toHaveBeenCalled()
	})

	it('saves every game together and confirms', async () => {
		await mountWith()
		api.put.mockResolvedValue(catalog({ ...DEFAULTS, socks: 1 }))

		await field('socks').setValue('1')
		await wrapper.get('form').trigger('submit')
		await flushPromises()

		expect(api.put).toHaveBeenCalledWith('/api/v1/house/arcade-thresholds', { thresholds: { ...DEFAULTS, socks: 1 } })
		expect(wrapper.get('[data-testid="arcade-thresholds-saved"]').attributes('role')).toBe('status')
		expect(saveButton().attributes('disabled')).toBeDefined()
	})

	it.each([['501'], ['-1'], ['2.5'], ['']])('refuses %p before any request', async (value) => {
		await mountWith()

		await field('snake').setValue(value)

		expect(wrapper.get('[data-testid="arcade-thresholds-invalid"]').attributes('role')).toBe('alert')
		expect(field('snake').attributes('aria-invalid')).toBe('true')
		expect(saveButton().attributes('disabled')).toBeDefined()
		await wrapper.get('form').trigger('submit')
		expect(api.put).not.toHaveBeenCalled()
	})

	it('reports a failed save and keeps the edit', async () => {
		await mountWith()
		api.put.mockRejectedValue(new Error('Owner role required'))

		await field('tiles').setValue('20')
		await wrapper.get('form').trigger('submit')
		await flushPromises()

		expect(wrapper.get('[data-testid="arcade-thresholds-error"]').attributes('role')).toBe('alert')
		expect(field('tiles').element.value).toBe('20')
	})

	it('keeps an edited field when a new catalog arrives, and moves the untouched ones', async () => {
		await mountWith()

		await field('snake').setValue('8')
		store.catalog = catalog({ ...DEFAULTS, snake: 12, dust: 40 })
		await flushPromises()
		expect(field('snake').element.value).toBe('8')
		expect(field('dust').element.value).toBe('40')

		await field('snake').setValue('12')
		store.catalog = catalog({ ...DEFAULTS, snake: 12, dust: 30 })
		await flushPromises()
		expect(field('dust').element.value).toBe('30')
	})

	it('loads the catalog when it is missing, and offers a retry when that fails', async () => {
		api.get.mockRejectedValueOnce(new Error('Network down'))
		await mountWith(null)

		expect(api.get).toHaveBeenCalledWith('/api/v1/minigames/catalog')
		expect(wrapper.get('[data-testid="arcade-thresholds-load-error"]').attributes('role')).toBe('alert')

		api.get.mockResolvedValueOnce(catalog(DEFAULTS))
		await wrapper.get('[data-testid="arcade-thresholds-load-error"] button').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="arcade-thresholds-load-error"]').exists()).toBe(false)
		expect(field('spots').element.value).toBe('0')
	})
})
