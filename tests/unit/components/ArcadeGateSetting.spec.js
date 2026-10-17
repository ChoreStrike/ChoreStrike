/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach, afterEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ArcadeGateSetting from '@/components/Minigames/ArcadeGateSetting.vue'
import { useHouseStore } from '@/store/house.js'

jest.mock('@/services/api.js', () => ({
	api: { get: jest.fn(), put: jest.fn(), post: jest.fn() },
	setCatalogEnabled: jest.fn(),
}))

jest.mock('@/utils/logger', () => ({
	logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() },
}))

const { api } = require('@/services/api.js')

/**
 * CS-PLAN-086 D-086-02: the owner switch of the arcade unlock gate. It
 * mirrors the loaded house flag, saves only a changed value and reports
 * the outcome without touching anyone's personal arcade preference.
 */
describe('ArcadeGateSetting', () => {
	let wrapper
	let houseStore

	const mountSetting = async (arcadeGateEnabled = true) => {
		houseStore = useHouseStore()
		houseStore.house = { id: 1, name: 'Home', arcadeGateEnabled }
		wrapper = mount(ArcadeGateSetting)
		await flushPromises()
		return wrapper
	}

	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
	})

	afterEach(() => {
		wrapper?.unmount()
	})

	it('mirrors the house flag and saves nothing while unchanged', async () => {
		await mountSetting(true)

		expect(wrapper.get('[data-testid="arcade-gate-toggle"]').element.checked).toBe(true)
		expect(wrapper.get('[data-testid="arcade-gate-save"]').attributes('disabled')).toBeDefined()
		expect(wrapper.get('label').attributes('for')).toBe('arcade-gate-enabled')
	})

	it('switches the gate off and confirms the save', async () => {
		await mountSetting(true)
		api.put.mockResolvedValue({ id: 1, name: 'Home', arcadeGateEnabled: false })

		await wrapper.get('[data-testid="arcade-gate-toggle"]').setValue(false)
		await wrapper.get('[data-testid="arcade-gate-save"]').trigger('click')
		await flushPromises()

		expect(api.put).toHaveBeenCalledWith('/api/v1/house/arcade-gate', { arcadeGateEnabled: false })
		expect(wrapper.get('[data-testid="arcade-gate-saved"]').attributes('role')).toBe('status')
		expect(wrapper.get('[data-testid="arcade-gate-save"]').attributes('disabled')).toBeDefined()
	})

	it('reports a failed save and keeps the draft', async () => {
		await mountSetting(false)
		api.put.mockRejectedValue(new Error('Owner role required'))

		await wrapper.get('[data-testid="arcade-gate-toggle"]').setValue(true)
		await wrapper.get('[data-testid="arcade-gate-save"]').trigger('click')
		await flushPromises()

		expect(wrapper.get('[data-testid="arcade-gate-error"]').attributes('role')).toBe('alert')
		expect(wrapper.get('[data-testid="arcade-gate-toggle"]').element.checked).toBe(true)
		expect(houseStore.arcadeGateEnabled).toBe(false)
	})

	it('follows a house reload', async () => {
		await mountSetting(true)

		houseStore.house = { id: 1, name: 'Home', arcadeGateEnabled: false }
		await flushPromises()

		expect(wrapper.get('[data-testid="arcade-gate-toggle"]').element.checked).toBe(false)
	})
})
