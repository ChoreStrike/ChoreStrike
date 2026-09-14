/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * HouseStatsCards component suite (CR-053-22, CS-PLAN-068 §3.6).
 */

import { describe, it, expect } from '@jest/globals'
import { mount } from '@vue/test-utils'

import HouseStatsCards from '../../../src/components/Insights/HouseStatsCards.vue'

function mountCards(stats) {
	return mount(HouseStatsCards, { props: { stats } })
}

describe('HouseStatsCards', () => {
	it('renders all five stat cards with the provided values', () => {
		const wrapper = mountCards({
			totalRooms: 6,
			cleanRooms: 4,
			dirtyRooms: 1,
			roomsNeedingCleaning: 2,
			houseHealth: 82,
		})
		expect(wrapper.find('[data-testid="stat-total-rooms"] .stats-value').text()).toBe('6')
		expect(wrapper.find('[data-testid="stat-clean-rooms"] .stats-value').text()).toBe('4')
		expect(wrapper.find('[data-testid="stat-dirty-rooms"] .stats-value').text()).toBe('1')
		expect(wrapper.find('[data-testid="stat-needing-cleaning"] .stats-value').text()).toBe('2')
		expect(wrapper.find('[data-testid="stat-house-health"] .stats-value').text()).toBe('82%')
	})

	it('falls back to zero for missing counters', () => {
		const wrapper = mountCards({})
		expect(wrapper.find('[data-testid="stat-total-rooms"] .stats-value').text()).toBe('0')
		expect(wrapper.find('[data-testid="stat-clean-rooms"] .stats-value').text()).toBe('0')
		expect(wrapper.find('[data-testid="stat-dirty-rooms"] .stats-value').text()).toBe('0')
		expect(wrapper.find('[data-testid="stat-needing-cleaning"] .stats-value').text()).toBe('0')
		// Health keeps its documented 100% default.
		expect(wrapper.find('[data-testid="stat-house-health"] .stats-value').text()).toBe('100%')
	})

	it('keeps the semantic card classes for clean/dirty/warning/health', () => {
		const wrapper = mountCards({ totalRooms: 1, cleanRooms: 1, dirtyRooms: 1, roomsNeedingCleaning: 1, houseHealth: 50 })
		expect(wrapper.find('[data-testid="stat-clean-rooms"]').classes()).toContain('clean')
		expect(wrapper.find('[data-testid="stat-dirty-rooms"]').classes()).toContain('dirty')
		expect(wrapper.find('[data-testid="stat-needing-cleaning"]').classes()).toContain('warning')
		expect(wrapper.find('[data-testid="stat-house-health"]').classes()).toContain('health')
	})
})
