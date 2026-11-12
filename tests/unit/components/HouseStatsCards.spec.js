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
	// CS-PLAN-090 WP-E: four key figures in one row; the room total stands
	// with the clean rooms instead of taking a tile of its own.
	it('renders the four key figures, house health first', () => {
		const wrapper = mountCards({
			totalRooms: 6,
			cleanRooms: 4,
			dirtyRooms: 1,
			roomsNeedingCleaning: 2,
			houseHealth: 82,
		})

		expect(wrapper.findAll('.stats-card').map((card) => card.attributes('data-testid')))
			.toEqual(['stat-house-health', 'stat-clean-rooms', 'stat-dirty-rooms', 'stat-needing-cleaning'])
		expect(wrapper.find('[data-testid="stat-house-health"] .stats-value').text()).toBe('82%')
		expect(wrapper.find('[data-testid="stat-clean-rooms"] .stats-value').text()).toBe('4 / 6')
		expect(wrapper.find('[data-testid="stat-dirty-rooms"] .stats-value').text()).toBe('1')
		expect(wrapper.find('[data-testid="stat-needing-cleaning"] .stats-value').text()).toBe('2')
		expect(wrapper.find('[data-testid="stat-total-rooms"]').exists()).toBe(false)
	})

	it('falls back to neutral values while the stats are unknown', () => {
		const wrapper = mountCards({})

		expect(wrapper.find('[data-testid="stat-clean-rooms"] .stats-value').text()).toBe('0 / 0')
		expect(wrapper.find('[data-testid="stat-dirty-rooms"] .stats-value').text()).toBe('0')
		expect(wrapper.find('[data-testid="stat-needing-cleaning"] .stats-value').text()).toBe('0')
		// An unknown house is healthy by default, never alarming.
		expect(wrapper.find('[data-testid="stat-house-health"] .stats-value').text()).toBe('100%')
	})

	it('keeps the tone of each figure', () => {
		const wrapper = mountCards({})

		expect(wrapper.find('[data-testid="stat-clean-rooms"]').classes()).toContain('clean')
		expect(wrapper.find('[data-testid="stat-dirty-rooms"]').classes()).toContain('dirty')
		expect(wrapper.find('[data-testid="stat-needing-cleaning"]').classes()).toContain('attention')
		expect(wrapper.find('[data-testid="stat-house-health"]').classes()).toContain('health')
	})
})
