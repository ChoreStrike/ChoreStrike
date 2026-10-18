/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * GameLeaderboard component suite (CR-053-22, CS-PLAN-068 §3.6; generic per
 * game since CS-PLAN-086): tabs, medal ordering, current-user highlighting,
 * own rank, the empty state and per-game isolation.
 */

import { describe, it, expect, beforeEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import axios from '@nextcloud/axios'
import GameLeaderboard from '../../../src/components/Minigames/GameLeaderboard.vue'
import { useMinigameStore } from '../../../src/store/minigame.js'
import { useUserStore } from '../../../src/store/user.js'

const OK = (data) => ({ data: { success: true, data } })

const ALL_TIME = [
	{ id: 1, userId: 'mom', displayName: 'Mom', score: 300 },
	{ id: 2, userId: 'dad', displayName: 'Dad', score: 210 },
	{ id: 3, userId: 'teen', displayName: 'Teen', score: 90 },
	{ id: 4, userId: 'kid', displayName: 'Kid', score: 40 },
]
const WEEKLY = [
	{ id: 9, userId: 'kid', displayName: 'Kid', score: 40 },
]

describe('GameLeaderboard', () => {
	let pinia
	let store
	let wrapper

	const mountBoard = async (gameId = 'snake') => {
		wrapper = mount(GameLeaderboard, { props: { gameId }, attachTo: document.body, global: { plugins: [pinia] } })
		await flushPromises()
		return wrapper
	}

	beforeEach(async () => {
		wrapper?.unmount()
		pinia = createPinia()
		setActivePinia(pinia)
		store = useMinigameStore()
		jest.clearAllMocks()
		axios.mockImplementation(async ({ url }) => {
			if (url.includes('/leaderboard/weekly')) {
				return OK(WEEKLY)
			}
			if (url.includes('/personal-best')) {
				return OK({ score: 210 })
			}
			if (url.includes('/rank')) {
				return OK({ rank: 2 })
			}
			if (url.includes('/recent')) {
				return OK([{ id: 5, score: 88, createdAt: new Date().toISOString() }])
			}
			return OK(ALL_TIME)
		})
	})

	it('loads the game\'s boards and personal stats on mount', async () => {
		useUserStore().currentUser = { id: 7, nextcloudUserId: 'dad' }
		await mountBoard()

		const urls = axios.mock.calls.map(([config]) => config.url)
		expect(urls.every((url) => url.includes('/minigames/snake/'))).toBe(true)
		expect(document.querySelector('[role="dialog"]').getAttribute('aria-label')).toBe('Leaderboard: Crumb Snake')

		const entries = wrapper.findAll('[data-testid="game-leaderboard-entry"]')
		expect(entries).toHaveLength(4)
		// Medals for the top three, a plain rank number afterwards; the rank
		// stays readable for assistive technology on every row.
		expect(entries[0].text()).toContain('🥇')
		expect(entries[2].text()).toContain('🥉')
		expect(entries[3].find('.rank').text()).toBe('4')
		expect(entries[0].text()).toContain('300')
		expect(entries[0].text()).toContain('Mom')
		expect(entries[1].classes()).toContain('current-user')
		const personal = wrapper.get('[data-testid="game-leaderboard-personal"]').text()
		expect(personal).toContain('210')
		expect(personal).toContain('#2')
	})

	it('switches between all-time and weekly tabs', async () => {
		await mountBoard()
		const tabs = wrapper.findAll('.tab-btn')
		expect(tabs[0].attributes('aria-pressed')).toBe('true')

		await tabs[1].trigger('click')

		// Query again: the stubbed teleport of Modal re-renders its content.
		const weekly = wrapper.findAll('.tab-btn')[1]
		expect(weekly.attributes('aria-pressed')).toBe('true')
		expect(weekly.classes()).toContain('active')
		const entries = wrapper.findAll('[data-testid="game-leaderboard-entry"]')
		expect(entries).toHaveLength(1)
		expect(entries[0].text()).toContain('Kid')
	})

	it('renders the empty state and an unranked member', async () => {
		axios.mockResolvedValue(OK([]))
		await mountBoard()
		expect(wrapper.get('[data-testid="game-leaderboard-empty"]').text()).toContain('No scores yet')
		store.ensureSlice('snake').userRank = 0
		await wrapper.vm.$nextTick()
		expect(wrapper.get('[data-testid="game-leaderboard-personal"]').text()).toContain('#-')
	})

	it('keeps another game\'s scores out of this board', async () => {
		store.ensureSlice('tiles').leaderboard = [{ id: 77, userId: 'kid', displayName: 'Kid', score: 9999 }]
		await mountBoard()
		expect(wrapper.text()).not.toContain('9999')
	})

	it('lists recent games with a relative date', async () => {
		await mountBoard()
		expect(wrapper.findAll('.recent-entry')).toHaveLength(1)
		expect(wrapper.find('.recent-score').text()).toBe('88')
		expect(wrapper.find('.recent-date').text()).toContain('Just now')
	})

	it('emits close from the dialog', async () => {
		await mountBoard()
		document.querySelector('[data-testid="modal-close"]').click()
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('formats recent scores at fixed minute/hour/day boundaries', async () => {
		jest.useFakeTimers().setSystemTime(new Date('2026-09-14T12:00:00Z'))
		try {
			await mountBoard()
			store.ensureSlice('snake').recentScores = [30, 3600, 86400].map((seconds, id) => ({ id, score: 1, createdAt: new Date(Date.now() - seconds * 1000).toISOString() }))
			await wrapper.vm.$nextTick()
			expect(wrapper.findAll('.recent-date').map(row => row.text())).toEqual(['Just now', '1h ago', '1d ago'])
		} finally {
			jest.useRealTimers()
		}
	})
})
