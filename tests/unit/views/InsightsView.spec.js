/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import InsightsView from '../../../src/views/InsightsView.vue'

jest.mock('../../../src/services/api.js', () => ({
	api: {
		get: jest.fn(),
		put: jest.fn(),
		post: jest.fn(),
		delete: jest.fn(),
	},
}))

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

import { api } from '../../../src/services/api.js'

const STATS_PAYLOAD = {
	house: { id: 1, name: 'Test House', houseHealth: 82 },
	stats: {
		totalRooms: 6,
		cleanRooms: 4,
		dirtyRooms: 2,
		roomsNeedingCleaning: 1,
		houseHealth: 82,
	},
}

const USERS = [
	{
		id: 7,
		nextcloudUserId: 'alice',
		displayName: 'Alice',
		contributionScore: 1200,
		coins: 340,
		currentStreak: 5,
		streakFreezes: 2,
		lastFreezeDate: null,
		schedulingPreferences: {
			energyBudget: { monday: 10, tuesday: 10, wednesday: 10, thursday: 10, friday: 10, saturday: 15, sunday: 15 },
			weekdayAvailability: { monday: true, tuesday: true, wednesday: true, thursday: true, friday: true, saturday: true, sunday: false },
		},
	},
	{
		id: 8,
		nextcloudUserId: 'bob',
		displayName: 'Bob',
		contributionScore: 300,
		coins: 90,
		currentStreak: 0,
		streakFreezes: 0,
		lastFreezeDate: null,
		schedulingPreferences: {},
	},
]

const ACTIVITY = [
	{ id: 1, completedAt: new Date().toISOString(), wasCombo: true, coinsEarned: 20, houseUserId: 7, displayName: 'Alice', taskId: 3, taskTitle: 'Vacuum', energyCost: 4, roomId: 2, roomName: 'Living Room' },
	{ id: 2, completedAt: new Date().toISOString(), wasCombo: false, coinsEarned: 8, houseUserId: 8, displayName: 'Bob', taskTitle: null, energyCost: null, roomId: null, roomName: null },
]

describe('InsightsView.vue', () => {
	let pinia

	beforeEach(() => {
		setActivePinia(createPinia())
		pinia = createPinia()
		setActivePinia(pinia)
		jest.clearAllMocks()

		api.get.mockImplementation((path) => {
			if (path === '/api/v1/house/stats') return Promise.resolve(STATS_PAYLOAD)
			if (path === '/api/v1/users') return Promise.resolve(USERS)
			if (path === '/api/v1/house/activity') return Promise.resolve({ completions: ACTIVITY, total: 2 })
			return Promise.reject(new Error(`unexpected GET ${path}`))
		})
	})

	const mountView = async () => {
		const wrapper = mount(InsightsView)
		await flushPromises()
		return wrapper
	}

	it('renders loading state while fetching', async () => {
		let resolveStats
		api.get.mockImplementation((path) => {
			if (path === '/api/v1/house/stats') {
				return new Promise((resolve) => { resolveStats = resolve })
			}
			return Promise.resolve([])
		})

		const wrapper = mount(InsightsView)
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="insights-loading"]').exists()).toBe(true)

		resolveStats(STATS_PAYLOAD)
		await flushPromises()
		expect(wrapper.find('[data-testid="insights-loading"]').exists()).toBe(false)
	})

	it('renders error state when stats fail', async () => {
		api.get.mockRejectedValue(new Error('Offline'))

		const wrapper = mount(InsightsView)
		await flushPromises()

		expect(wrapper.find('[data-testid="insights-error"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="error-retry"]').exists()).toBe(true)
	})

	it('retries the insights fetch and renders stats once it succeeds (CS-PLAN-051)', async () => {
		api.get.mockImplementation((path) => {
			if (path === '/api/v1/house/stats') return Promise.reject(new Error('Offline'))
			if (path === '/api/v1/users') return Promise.resolve(USERS)
			if (path === '/api/v1/house/activity') return Promise.resolve({ completions: ACTIVITY, total: 2 })
			return Promise.reject(new Error(`unexpected GET ${path}`))
		})

		const wrapper = await mountView()
		expect(wrapper.find('[data-testid="insights-error"]').exists()).toBe(true)

		api.get.mockImplementation((path) => {
			if (path === '/api/v1/house/stats') return Promise.resolve(STATS_PAYLOAD)
			if (path === '/api/v1/users') return Promise.resolve(USERS)
			if (path === '/api/v1/house/activity') return Promise.resolve({ completions: ACTIVITY, total: 2 })
			return Promise.reject(new Error(`unexpected GET ${path}`))
		})

		await wrapper.find('[data-testid="error-retry"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="insights-error"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="house-stats-cards"]').exists()).toBe(true)
	})

	it('renders house stats cards with values', async () => {
		const wrapper = await mountView()

		expect(wrapper.find('[data-testid="stat-total-rooms"]').text()).toContain('6')
		expect(wrapper.find('[data-testid="stat-clean-rooms"]').text()).toContain('4')
		expect(wrapper.find('[data-testid="stat-dirty-rooms"]').text()).toContain('2')
		expect(wrapper.find('[data-testid="stat-needing-cleaning"]').text()).toContain('1')
		expect(wrapper.find('[data-testid="stat-house-health"]').text()).toContain('82%')
	})

	it('renders fairness rows with contribution and coins as separate columns', async () => {
		const wrapper = await mountView()

		const rows = wrapper.findAll('[data-testid="fairness-row"]')
		expect(rows).toHaveLength(2)

		expect(wrapper.find('[data-testid="header-contribution"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="header-coins"]').exists()).toBe(true)

		// Alice: separate columns never merge the two metrics.
		expect(wrapper.find('[data-testid="contribution-7"]').text()).toBe('1,200')
		expect(wrapper.find('[data-testid="coins-7"]').text()).toBe('340')
	})

	it('normalizes load against weekly capacity from scheduling preferences', async () => {
		const wrapper = await mountView()

		// Alice: available Mon–Sat → 10*5 + 15 = 65 weekly capacity; activity
		// gives her 4 energy → load bar shows 4/65.
		const aliceLoad = wrapper.find('[data-testid="load-7"]')
		expect(aliceLoad.text()).toContain('4 / 65')

		// Bob has no preferences → fallback budgets (10/15 all week) and his
		// completion is dangling (null energyCost) → 0 energy counted.
		const bobLoad = wrapper.find('[data-testid="load-8"]')
		expect(bobLoad.text()).toContain('0 /')
	})

	it('renders activity feed rows with actor, task, and combo flag', async () => {
		const wrapper = await mountView()

		const items = wrapper.findAll('[data-testid="activity-item"]')
		expect(items).toHaveLength(2)
		expect(items[0].text()).toContain('Alice')
		expect(items[0].text()).toContain('Vacuum')
		expect(items[0].text()).toContain('Living Room')
		// Combo flag renders as fire icon.
		expect(items[0].find('.activity-icon.combo').exists()).toBe(true)
	})

	it('renders fallback labels for dangling activity references', async () => {
		const wrapper = await mountView()

		const items = wrapper.findAll('[data-testid="activity-item"]')
		expect(items[1].text()).toContain('Unknown task')
	})

	it('shows empty hint when there are no members', async () => {
		api.get.mockImplementation((path) => {
			if (path === '/api/v1/users') return Promise.resolve([])
			if (path === '/api/v1/house/activity') return Promise.resolve({ completions: [], total: 0 })
			return Promise.resolve(STATS_PAYLOAD)
		})

		const wrapper = await mountView()

		expect(wrapper.find('[data-testid="fairness-empty"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="activity-empty"]').exists()).toBe(true)
	})

	it('renders a retryable error state instead of the empty hint when the members fetch fails (CS-PLAN-051)', async () => {
		api.get.mockImplementation((path) => {
			if (path === '/api/v1/users') return Promise.reject(new Error('Members fetch failed'))
			if (path === '/api/v1/house/activity') return Promise.resolve({ completions: ACTIVITY, total: 2 })
			return Promise.resolve(STATS_PAYLOAD)
		})

		const wrapper = await mountView()

		// A failed members fetch must never surface as "No members yet".
		const fairnessError = wrapper.find('[data-testid="error-state"]')
		expect(fairnessError.exists()).toBe(true)
		expect(fairnessError.find('[data-testid="error-retry"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="fairness-empty"]').exists()).toBe(false)
	})

	it('retries the members fetch and renders the fairness table once it succeeds', async () => {
		api.get.mockImplementation((path) => {
			if (path === '/api/v1/users') return Promise.reject(new Error('Members fetch failed'))
			if (path === '/api/v1/house/activity') return Promise.resolve({ completions: ACTIVITY, total: 2 })
			return Promise.resolve(STATS_PAYLOAD)
		})

		const wrapper = await mountView()
		expect(wrapper.find('[data-testid="error-state"]').exists()).toBe(true)

		api.get.mockImplementation((path) => {
			if (path === '/api/v1/users') return Promise.resolve(USERS)
			return Promise.resolve(path === '/api/v1/house/activity'
				? { completions: ACTIVITY, total: 2 }
				: STATS_PAYLOAD)
		})

		await wrapper.find('[data-testid="error-retry"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="member-fairness-table"]').exists()).toBe(true)
		expect(wrapper.findAll('[data-testid="fairness-row"]')).toHaveLength(2)
	})

	it('keeps the insights error block when both stats and members fetches fail', async () => {
		api.get.mockImplementation((path) => {
			if (path === '/api/v1/users') return Promise.reject(new Error('Members fetch failed'))
			if (path === '/api/v1/house/activity') return Promise.resolve({ completions: ACTIVITY, total: 2 })
			if (path === '/api/v1/house/stats') return Promise.reject(new Error('Stats fetch failed'))
			return Promise.reject(new Error(`unexpected GET ${path}`))
		})

		const wrapper = await mountView()

		// The house-level error branch still wins over the section bodies.
		expect(wrapper.find('[data-testid="insights-error"]').exists()).toBe(true)
	})
})
