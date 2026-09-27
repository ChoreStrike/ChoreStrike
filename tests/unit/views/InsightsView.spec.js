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

jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
	showWarning: jest.fn(),
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
import { showError } from '@nextcloud/dialogs'

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
		contributionMinor: 12000,
		currentStreak: 5,
		schedulingPreferences: {
			energyBudget: { monday: 10, tuesday: 10, wednesday: 10, thursday: 10, friday: 10, saturday: 15, sunday: 15 },
			weekdayAvailability: { monday: true, tuesday: true, wednesday: true, thursday: true, friday: true, saturday: true, sunday: false },
		},
	},
	{
		id: 8,
		nextcloudUserId: 'bob',
		displayName: 'Bob',
		contributionMinor: 3000,
		currentStreak: 0,
		schedulingPreferences: {},
	},
]

const ACTIVITY = [
	{ id: 1, completedAt: new Date().toISOString(), totalEnergy: 4, houseUserId: 7, displayName: 'Alice', taskId: 3, taskTitle: 'Vacuum', energyCost: 4, roomId: 2, roomName: 'Living Room', canCorrect: true, version: 1 },
	{ id: 2, completedAt: new Date().toISOString(), totalEnergy: 2, houseUserId: 8, displayName: 'Bob', taskTitle: null, energyCost: null, roomId: null, roomName: null, canCorrect: false },
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

	it('renders fairness rows with the exact contributionMinor column (WP3)', async () => {
		const wrapper = await mountView()

		const rows = wrapper.findAll('[data-testid="fairness-row"]')
		expect(rows).toHaveLength(2)

		expect(wrapper.find('[data-testid="header-contribution"]').exists()).toBe(true)
		// WP3: exact minor units (100 = 1 energy) — no Coin column anymore.
		expect(wrapper.find('[data-testid="contribution-7"]').text()).toBe('120')
		expect(wrapper.find('[data-testid="header-coins"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="coins-7"]').exists()).toBe(false)
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

	it('renders activity feed rows with actor, task, and frozen effort (WP3)', async () => {
		const wrapper = await mountView()

		const items = wrapper.findAll('[data-testid="activity-item"]')
		expect(items).toHaveLength(2)
		expect(items[0].text()).toContain('Alice')
		expect(items[0].text()).toContain('Vacuum')
		expect(items[0].text()).toContain('Living Room')
		// WP3: the frozen total effort renders — never a combo flag.
		expect(items[0].find('.activity-icon.combo').exists()).toBe(false)
		expect(items[0].text()).toContain('4')
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

	describe('WP3 §3.6 history correction (owner attribution)', () => {
		const COMPLETION_READ_MODEL = {
			id: 1,
			version: 1,
			allocationRevision: 2,
			totalEnergy: 4,
			canCorrect: true,
			allocation: [
				{ userId: 'alice', displayName: 'Alice', sharePct: 60, energyMinor: 240, coinMinor: 2400 },
				{ userId: 'bob', displayName: 'Bob', sharePct: 40, energyMinor: 160, coinMinor: 1600 },
			],
			history: [],
		}

		const mockActivityBackend = () => {
			api.get.mockImplementation((path) => {
				if (path === '/api/v1/completions/1') return Promise.resolve(COMPLETION_READ_MODEL)
				if (path === '/api/v1/house/stats') return Promise.resolve(STATS_PAYLOAD)
				if (path === '/api/v1/users') return Promise.resolve(USERS)
				if (path === '/api/v1/house/activity') return Promise.resolve({ completions: ACTIVITY, total: 2 })
				return Promise.reject(new Error(`unexpected GET ${path}`))
			})
		}

		it('opens the reused participation dialog with the recorded split and saves the correction', async () => {
			mockActivityBackend()
			api.put.mockResolvedValue({
				completionId: 1,
				roomTask: { id: 5, isActive: true },
				rewards: { completion: { id: 1, version: 2, allocationRevision: 3, totalEnergy: 4 } },
			})

			const wrapper = await mountView()
			await wrapper.find('[data-testid="activity-correct-1"]').trigger('click')
			await flushPromises()

			const dialog = wrapper.find('[data-testid="participation-dialog"]')
			expect(dialog.exists()).toBe(true)
			expect(wrapper.vm.correction.completion.version).toBe(1)
			// The recorded split pre-fills the reused form (member order).
			expect(wrapper.vm.correctionDraft).toEqual([
				{ userId: 'alice', sharePct: 60 },
				{ userId: 'bob', sharePct: 40 },
			])
			expect(dialog.find('h2').text()).toBe('Correct the work split')

			await wrapper.find('[data-testid="participation-confirm"]').trigger('click')
			await flushPromises()

			expect(api.put).toHaveBeenCalledWith('/api/v1/completions/1/attribution', expect.objectContaining({
				expectedVersion: 1,
				participants: [{ userId: 'alice', sharePct: 60 }, { userId: 'bob', sharePct: 40 }],
				allocationReviewed: true,
			}))
			// The dialog closes after the committed correction.
			expect(wrapper.find('[data-testid="participation-dialog"]').exists()).toBe(false)
		})

		it('keeps the submitted draft separate from the new allocation until explicit review after a 409', async () => {
			const latest = {
				...COMPLETION_READ_MODEL,
				version: 2,
				allocation: [{ userId: 'bob', displayName: 'Bob', sharePct: 100 }],
			}
			api.get.mockImplementation((path) => {
				if (path === '/api/v1/completions/1') {
					return Promise.resolve(api.put.mock.calls.length > 0 ? latest : COMPLETION_READ_MODEL)
				}
				if (path === '/api/v1/house/stats') return Promise.resolve(STATS_PAYLOAD)
				if (path === '/api/v1/users') return Promise.resolve(USERS)
				if (path === '/api/v1/house/activity') return Promise.resolve({ completions: ACTIVITY, total: 2 })
				return Promise.reject(new Error(`unexpected GET ${path}`))
			})
			const conflict = new Error('The completion changed since it was displayed')
			conflict.status = 409
			api.put.mockRejectedValueOnce(conflict)
			api.put.mockResolvedValueOnce({ completionId: 1 })

			const wrapper = await mountView()
			await wrapper.find('[data-testid="activity-correct-1"]').trigger('click')
			await flushPromises()

			await wrapper.find('[data-testid="participant-share-alice"]').setValue('70')
			await wrapper.find('[data-testid="participant-share-bob"]').setValue('30')
			await wrapper.find('[data-testid="participation-confirm"]').trigger('click')
			await flushPromises()

			expect(wrapper.find('[data-testid="participation-dialog"]').exists()).toBe(false)
			expect(wrapper.find('[data-testid="correction-review"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="correction-draft"]').text()).toContain('Alice: 70%')
			expect(wrapper.find('[data-testid="correction-current"]').text()).toContain('Bob: 100%')
			expect(wrapper.vm.correction.expectedVersion).toBe(1)
			await wrapper.vm.confirmCorrection({ participants: [{ userId: 'alice', sharePct: 100 }] })
			expect(api.put).toHaveBeenCalledTimes(1)

			await wrapper.find('[data-testid="correction-review-button"]').trigger('click')
			await flushPromises()
			expect(wrapper.find('[data-testid="participant-share-alice"]').element.value).toBe('70')
			expect(wrapper.find('[data-testid="participant-share-bob"]').element.value).toBe('30')
			await wrapper.find('[data-testid="participation-confirm"]').trigger('click')
			await flushPromises()
			expect(api.put.mock.calls[1][1]).toEqual(expect.objectContaining({
				expectedVersion: 2,
				participants: [{ userId: 'alice', sharePct: 70 }, { userId: 'bob', sharePct: 30 }],
			}))
			expect(wrapper.find('[data-testid="correction-review"]').exists()).toBe(false)
		})

		it('keeps an uncertain draft locked to its receipt across a completion refetch', async () => {
			mockActivityBackend()
			api.put.mockRejectedValueOnce(new Error('lost response'))
			api.put.mockResolvedValueOnce({ completionId: 1, replay: true })
			const wrapper = await mountView()
			await wrapper.find('[data-testid="activity-correct-1"]').trigger('click')
			await flushPromises()
			await wrapper.find('[data-testid="participation-confirm"]').trigger('click')
			await flushPromises()
			expect(wrapper.find('[data-testid="correction-retry"]').exists()).toBe(true)
			await wrapper.vm.roomTasksStore.fetchCompletion(1)
			await wrapper.find('[data-testid="correction-retry"]').trigger('click')
			await flushPromises()
			expect(api.put).toHaveBeenCalledTimes(2)
			expect(api.put.mock.calls[1][1]).toEqual(api.put.mock.calls[0][1])
			expect(wrapper.find('[data-testid="correction-review"]').exists()).toBe(false)
		})

		it('refreshes history after targeted undo and resolves a stale version conflict without repeating the write', async () => {
			mockActivityBackend()
			api.post.mockResolvedValueOnce({ completionId: 1, undone: true })
			api.post.mockRejectedValueOnce(Object.assign(new Error('stale'), { status: 409 }))
			const wrapper = await mountView()
			const before = api.get.mock.calls.filter(([path]) => path === '/api/v1/house/activity').length
			await wrapper.find('[data-testid="activity-undo-1"]').trigger('click')
			await flushPromises()
			expect(api.post.mock.calls[0][0]).toBe('/api/v1/completions/1/undo')
			expect(api.post.mock.calls[0][1].expectedVersion).toBe(1)
			expect(api.get.mock.calls.filter(([path]) => path === '/api/v1/house/activity')).toHaveLength(before + 1)
			await wrapper.find('[data-testid="activity-undo-1"]').trigger('click')
			await flushPromises()
			expect(showError).toHaveBeenCalledWith('The completion changed since it was displayed')
			expect(api.get.mock.calls.filter(([path]) => path === '/api/v1/house/activity')).toHaveLength(before + 2)
			expect(api.post).toHaveBeenCalledTimes(2)
		})

		it('offers a read-only refresh when the post-undo history fetch fails', async () => {
			let activityReads = 0
			api.get.mockImplementation((path) => {
				if (path === '/api/v1/house/stats') return Promise.resolve(STATS_PAYLOAD)
				if (path === '/api/v1/users') return Promise.resolve(USERS)
				if (path === '/api/v1/house/activity') {
					activityReads++
					return activityReads === 2
						? Promise.reject(new Error('offline'))
						: Promise.resolve({ completions: ACTIVITY, total: 2 })
				}
				return Promise.reject(new Error(`unexpected GET ${path}`))
			})
			api.post.mockRejectedValueOnce(Object.assign(new Error('stale'), { status: 409 }))
			const wrapper = await mountView()
			await wrapper.find('[data-testid="activity-undo-1"]').trigger('click')
			await flushPromises()
			expect(wrapper.find('[data-testid="activity-error"]').exists()).toBe(true)
			await wrapper.find('[data-testid="activity-retry"]').trigger('click')
			await flushPromises()
			expect(wrapper.find('[data-testid="activity-undo-1"]').exists()).toBe(true)
			expect(api.post).toHaveBeenCalledTimes(1)
		})

		it('skips the correction action for rows the viewer may not correct', async () => {
			mockActivityBackend()
			const wrapper = await mountView()

			expect(wrapper.find('[data-testid="activity-correct-1"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="activity-correct-2"]').exists()).toBe(false)
		})
	})
})
