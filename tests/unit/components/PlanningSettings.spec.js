/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { flushPromises, mount } from '@vue/test-utils'
import PlanningSettings from '../../../src/components/Settings/PlanningSettings.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: jest.fn((app, text) => text) }))

jest.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span class="loading-stub" />' },
}))

const mockSchedulingStore = { fetchUserPreferences: jest.fn() }
jest.mock('../../../src/store/scheduling.js', () => ({
	useSchedulingStore: () => mockSchedulingStore,
}))

jest.mock('../../../src/components/Scheduling/SelfAvailabilitySettings.vue', () => ({
	name: 'SelfAvailabilitySettings',
	template: '<div data-testid="self-availability-stub" />',
}))
jest.mock('../../../src/components/Scheduling/EnergyBudgetSettings.vue', () => ({
	name: 'EnergyBudgetSettings',
	template: '<div data-testid="energy-budget-stub" />',
}))

const forms = (wrapper) => ['self-availability-stub', 'energy-budget-stub'].map((id) => wrapper.find(`[data-testid="${id}"]`).exists())

describe('PlanningSettings (CS-PLAN-090 WP-D)', () => {
	beforeEach(() => {
		mockSchedulingStore.fetchUserPreferences.mockReset().mockResolvedValue()
	})

	it('reads the own preferences once when it opens and then shows both forms', async () => {
		let release
		mockSchedulingStore.fetchUserPreferences.mockImplementationOnce(() => new Promise((resolve) => { release = resolve }))
		const wrapper = mount(PlanningSettings)

		// No form before the read answered: it would show defaults as if stored.
		expect(wrapper.find('[data-testid="planning-settings-loading"]').exists()).toBe(true)
		expect(forms(wrapper)).toEqual([false, false])

		release()
		await flushPromises()
		expect(mockSchedulingStore.fetchUserPreferences).toHaveBeenCalledTimes(1)
		expect(wrapper.find('[data-testid="planning-settings-loading"]').exists()).toBe(false)
		expect(forms(wrapper)).toEqual([true, true])
	})

	it('offers a retry in place after a failed read, without any form', async () => {
		mockSchedulingStore.fetchUserPreferences.mockRejectedValueOnce(new Error('down'))
		const wrapper = mount(PlanningSettings)
		await flushPromises()

		expect(wrapper.get('[data-testid="planning-settings-error"]').text()).toContain('Could not load your planning settings')
		expect(forms(wrapper)).toEqual([false, false])

		await wrapper.get('[data-testid="error-retry"]').trigger('click')
		await flushPromises()
		expect(mockSchedulingStore.fetchUserPreferences).toHaveBeenCalledTimes(2)
		expect(wrapper.find('[data-testid="planning-settings-error"]').exists()).toBe(false)
		expect(forms(wrapper)).toEqual([true, true])
	})
})
