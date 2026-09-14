/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * SnakeLeaderboard component suite (CR-053-22, CS-PLAN-068 §3.6): tabs,
 * medal ordering, current-user highlighting and the empty state.
 */

import { describe, it, expect, beforeEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import axios from '@nextcloud/axios'
import SnakeLeaderboard from '../../../src/components/Minigames/SnakeLeaderboard.vue'
import { useMinigameStore } from '../../../src/store/minigame.js'
import { useUserStore } from '../../../src/store/user.js'

const OK = (data) => ({ data: { success: true, data } })

const ALL_TIME = [
	{ id: 1, userId: 'mom', displayName: 'Mom', score: 300, boostsUsed: [] },
	{ id: 2, userId: 'dad', displayName: 'Dad', score: 210, boostsUsed: ['magnet'] },
	{ id: 3, userId: 'teen', displayName: 'Teen', score: 90, boostsUsed: [] },
	{ id: 4, userId: 'kid', displayName: 'Kid', score: 40, boostsUsed: [] },
]
const WEEKLY = [
	{ id: 9, userId: 'kid', displayName: 'Kid', score: 40, boostsUsed: [] },
]

describe('SnakeLeaderboard', () => {
	let pinia
	let store

	beforeEach(async () => {
		pinia = createPinia()
		setActivePinia(pinia)
		store = useMinigameStore()
		jest.clearAllMocks()
		axios.mockImplementation(async ({ url }) => {
			if (url.includes('/leaderboard/weekly')) {
				return OK(WEEKLY)
			}
			if (url.includes('/personal-best')) {
				return OK({ score: 300 })
			}
			if (url.includes('/rank')) {
				return OK({ rank: 4 })
			}
			if (url.includes('/recent')) {
				return OK([{ id: 5, score: 88, createdAt: new Date().toISOString() }])
			}
			return OK(ALL_TIME)
		})
	})

	it('loads all-time and weekly boards plus personal stats on mount', async () => {
		store.personalBest = { score: 300 }
		store.userRank = 4
		const userStore = useUserStore()
		userStore.currentUser = { id: 7, nextcloudUserId: 'dad' }
		const wrapper = mount(SnakeLeaderboard, { global: { plugins: [pinia] } })
		await flushPromises()

		const entries = wrapper.findAll('.leaderboard-entry')
		expect(entries).toHaveLength(4)
		// Medal ordering: gold/silver/bronze then a plain rank number.
		expect(entries[0].find('.medal.gold').exists()).toBe(true)
		expect(entries[1].find('.medal.silver').exists()).toBe(true)
		expect(entries[2].find('.medal.bronze').exists()).toBe(true)
		expect(entries[3].find('.rank-number').text()).toBe('4')
		// The scores render in board order (already sorted by the backend).
		expect(entries[0].text()).toContain('300')
		expect(entries[0].text()).toContain('Mom')
		// Boost usage is surfaced per entry.
		expect(entries[1].text()).toContain('Boosts used')
		// The current user row is highlighted.
		expect(entries[1].classes()).toContain('current-user')
		// Personal stats block.
		expect(wrapper.find('.personal-stats').text()).toContain('300')
		expect(wrapper.find('.personal-stats').text()).toContain('#4')
	})

	it('switches between all-time and weekly tabs', async () => {
		const wrapper = mount(SnakeLeaderboard, { global: { plugins: [pinia] } })
		await flushPromises()
		expect(wrapper.findAll('.leaderboard-entry')).toHaveLength(4)

		const tabs = wrapper.findAll('.tab-btn')
		await tabs[1].trigger('click')
		await wrapper.vm.$nextTick()
		expect(wrapper.findAll('.leaderboard-entry')).toHaveLength(1)
		expect(wrapper.findAll('.leaderboard-entry')[0].text()).toContain('Kid')
	})

	it('renders the empty state when no scores exist', async () => {
		axios.mockResolvedValue(OK([]))
		const wrapper = mount(SnakeLeaderboard, { global: { plugins: [pinia] } })
		await flushPromises()
		expect(wrapper.find('.empty-state').exists()).toBe(true)
		expect(wrapper.find('.empty-state').text()).toContain('No scores yet')
	})

	it('lists recent games with a relative date', async () => {
		const wrapper = mount(SnakeLeaderboard, { global: { plugins: [pinia] } })
		await flushPromises()
		expect(wrapper.find('.recent-scores').exists()).toBe(true)
		expect(wrapper.findAll('.recent-entry')).toHaveLength(1)
		expect(wrapper.find('.recent-score').text()).toBe('88')
		expect(wrapper.find('.recent-date').text()).toContain('Just now')
	})

	it('renders loading, empty personal stats and the close event', async () => {
		store.loading = true
		const wrapper = mount(SnakeLeaderboard, { global: { plugins: [pinia] } })
		await flushPromises()
		expect(wrapper.find('.loading').exists()).toBe(true)
		expect(wrapper.find('.personal-stats').text()).toContain('#-')
		await wrapper.get('.leaderboard-footer button').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
		wrapper.unmount()
	})

	it('formats recent scores at fixed minute/hour/day boundaries', async () => {
		jest.useFakeTimers().setSystemTime(new Date('2026-09-14T12:00:00Z'))
		const wrapper = mount(SnakeLeaderboard, { global: { plugins: [pinia] } })
		try {
			await flushPromises()
			store.recentScores = [30, 3600, 86400].map((seconds, id) => ({ id, score: 1, createdAt: new Date(Date.now() - seconds * 1000).toISOString() }))
			await wrapper.vm.$nextTick()
			expect(wrapper.findAll('.recent-date').map(row => row.text())).toEqual(['Just now', '1h ago', '1d ago'])
		} finally { wrapper.unmount(); jest.useRealTimers() }
	})
})
