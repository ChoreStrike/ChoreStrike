import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import Leaderboard from '../../../src/views/Leaderboard.vue'

/**
 * Unit tests for the consent-filtered Leaderboard (CS-PLAN-070 WP4,
 * C5i/C5l/D10b): the server ranking view is the sole authority — no
 * client-side member ranking, freeze indicators or contribution columns.
 */

// Mock vue-router
jest.mock('vue-router', () => ({
	useRouter: () => ({
		push: jest.fn(),
	}),
	useRoute: () => ({
		params: {},
		query: {},
	}),
}))

// Mock dialogs
jest.mock('@nextcloud/dialogs', () => ({
	showSuccess: jest.fn(),
	showError: jest.fn(),
}))

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => {
		if (!vars) return text
		return Object.keys(vars).reduce((out, k) => out.replace(`{${k}}`, String(vars[k])), text)
	},
}))

jest.mock('@/utils/logger', () => ({
	logger: { info: jest.fn(), error: jest.fn(), warn: jest.fn() },
}))

// Store mocks ------------------------------------------------------------
const mockUserStore = {
	currentUser: { id: 1, nextcloudUserId: 'user1', displayName: 'Current User' },
	initializeCurrentUser: jest.fn().mockResolvedValue(),
	fetchHouseUsers: jest.fn().mockResolvedValue(),
}

const mockCheckMedals = jest.fn().mockResolvedValue()
const mockFetchMedals = jest.fn().mockResolvedValue()
const mockGameStore = {
	medals: [
		{ id: 11, userId: 1, userName: 'Alice', medalType: 'rhythm_6', value: 7 },
		{ id: 12, userId: 2, userName: 'Current User', medalType: 'weekly_milestone', value: 10 },
	],
	checkMedals: (...args) => mockCheckMedals(...args),
	fetchMedals: (...args) => mockFetchMedals(...args),
}

const mockFetchRanking = jest.fn().mockResolvedValue()
const mockGiveConsent = jest.fn().mockResolvedValue()
const mockWithdrawConsent = jest.fn().mockResolvedValue()

const rankingState = {
	rankingEnabled: true,
	week: { weekKey: '2026-09-21', weekStart: '', weekEnd: '', provisional: true },
	me: { state: 'none', generation: null, effectiveMonday: null },
	entries: [],
	pending: [],
}

jest.mock('../../../src/store/user.js', () => ({
	useUserStore: () => mockUserStore,
}))

jest.mock('../../../src/store/game.js', () => ({
	useGameStore: () => mockGameStore,
}))

jest.mock('../../../src/store/ranking.js', () => ({
	useRankingStore: () => ({
		rankingEnabled: rankingState.rankingEnabled,
		week: rankingState.week,
		me: rankingState.me,
		entries: rankingState.entries,
		pending: rankingState.pending,
		fetchRanking: (...args) => mockFetchRanking(...args),
		giveConsent: (...args) => mockGiveConsent(...args),
		withdrawConsent: (...args) => mockWithdrawConsent(...args),
	}),
}))

// Mock child components
jest.mock('../../../src/components/Common/Icon.vue', () => ({
	name: 'Icon',
	props: ['name', 'size'],
	template: '<span class="icon">{{ name }}</span>',
}))

jest.mock('../../../src/components/Common/MedalBadge.vue', () => ({
	__esModule: true,
	MEDAL_LABELS: {
		rhythm_3: 'Rhythm 3',
		rhythm_6: 'Rhythm 6',
		rhythm_12: 'Rhythm 12',
		weekly_milestone: 'Weekly Milestone',
	},
	default: {
		name: 'MedalBadge',
		props: ['type', 'size'],
		template: '<div class="medal-badge">{{ type }}</div>',
	},
}))

describe('Leaderboard.vue (consent-filtered ranking)', () => {
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		jest.clearAllMocks()
		rankingState.rankingEnabled = true
		rankingState.week = { weekKey: '2026-09-21', weekStart: '', weekEnd: '', provisional: true }
		rankingState.me = { state: 'none', generation: null, effectiveMonday: null }
		rankingState.entries = []
		rankingState.pending = []
	})

	const mountView = async () => {
		const wrapper = mount(Leaderboard, {
			global: { plugins: [pinia] },
		})
		await flushPromises()
		return wrapper
	}

	it('renders the leaderboard shell', async () => {
		const wrapper = await mountView()
		expect(wrapper.find('.leaderboard').exists()).toBe(true)
		expect(wrapper.find('[data-testid="leaderboard-title"]').text()).toContain('Leaderboard')
	})

	it('fetches the server ranking view and evaluates medals on mount', async () => {
		await mountView()
		expect(mockUserStore.initializeCurrentUser).toHaveBeenCalled()
		expect(mockFetchRanking).toHaveBeenCalled()
		expect(mockCheckMedals).toHaveBeenCalled()
		expect(mockFetchMedals).toHaveBeenCalled()
	})

	it('shows the opt-in/closed state when ranking is disabled', async () => {
		rankingState.rankingEnabled = false
		const wrapper = await mountView()

		expect(wrapper.find('[data-testid="ranking-disabled"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="ranking-consent-button"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="leaderboard-list"]').exists()).toBe(false)
	})

	it('offers the join-next-week action to an unconsented member', async () => {
		const wrapper = await mountView()

		expect(wrapper.find('[data-testid="ranking-optin-hint"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="ranking-consent-button"]').exists()).toBe(true)
	})

	it('renders the consent-filtered entries with rank, ratio and the You badge', async () => {
		rankingState.me = { state: 'effective', generation: 2, effectiveMonday: '2026-09-14' }
		rankingState.entries = [
			{ houseUserId: 2, displayName: 'Alice', eligible: 3, onTime: 2, neutral: false, rank: 1, tied: false, isMe: false },
			{ houseUserId: 1, displayName: 'Current User', eligible: 3, onTime: 2, neutral: false, rank: 1, tied: true, isMe: true },
			{ houseUserId: 3, displayName: 'Bob', eligible: 0, onTime: 0, neutral: true, rank: null, tied: false, isMe: false },
		]
		const wrapper = await mountView()

		const rows = wrapper.findAll('[data-testid="leaderboard-entry"]')
		expect(rows.length).toBe(3)

		const ranks = wrapper.findAll('[data-testid="user-rank"]')
		expect(ranks[0].text()).toContain('1')
		expect(ranks[1].text()).toContain('1')
		expect(ranks[1].text()).toContain('*')
		expect(ranks[2].text()).toContain('Neutral')

		const ratios = wrapper.findAll('[data-testid="ranking-ratio"]')
		expect(ratios[0].text()).toBe('2/3')
		expect(ratios[2].text()).toContain('—')

		expect(wrapper.find('.current-user').exists()).toBe(true)
		expect(wrapper.find('.you-badge').exists()).toBe(true)
	})

	it('labels the week provisional while days are open', async () => {
		const wrapper = await mountView()
		expect(wrapper.find('[data-testid="ranking-provisional"]').exists()).toBe(true)
	})

	it('lists pending consents without a rank (C5l)', async () => {
		rankingState.pending = [
			{ houseUserId: 7, displayName: 'Zoe', effectiveMonday: '2026-09-28' },
		]
		const wrapper = await mountView()

		expect(wrapper.find('[data-testid="ranking-pending"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="ranking-pending-7"]').text()).toContain('Zoe')
		expect(wrapper.find('[data-testid="ranking-pending-7"]').text()).toContain('2026-09-28')
	})

	it('shows the empty state when nobody is ranked yet', async () => {
		const wrapper = await mountView()
		expect(wrapper.find('[data-testid="empty-leaderboard"]').exists()).toBe(true)
	})

	it('offers withdrawal to an effective participant and shows the pending start for a fresh consent', async () => {
		rankingState.me = { state: 'effective', generation: 1, effectiveMonday: '2026-09-14' }
		let wrapper = await mountView()
		expect(wrapper.findAll('[data-testid="ranking-withdraw-button"]').length).toBe(1)

		rankingState.me = { state: 'pending', generation: 3, effectiveMonday: '2026-09-28' }
		wrapper = await mountView()
		expect(wrapper.find('[data-testid="ranking-pending-state"]').text()).toContain('2026-09-28')
	})

	it('gives consent through the ranking store', async () => {
		const wrapper = await mountView()
		await wrapper.find('[data-testid="ranking-consent-button"]').trigger('click')
		expect(mockGiveConsent).toHaveBeenCalledTimes(1)
	})

	it('withdraws through the ranking store', async () => {
		rankingState.me = { state: 'effective', generation: 1, effectiveMonday: '2026-09-14' }
		const wrapper = await mountView()
		await wrapper.find('[data-testid="ranking-withdraw-button"]').trigger('click')
		expect(mockWithdrawConsent).toHaveBeenCalledTimes(1)
	})

	it('renders the weekly medals section from the game store with retained labels', async () => {
		const wrapper = await mountView()

		const section = wrapper.find('[data-testid="medal-summary"]')
		expect(section.exists()).toBe(true)

		const badges = wrapper.findAll('[data-testid="medal-badge"]')
		expect(badges.length).toBe(2)
		expect(badges[0].attributes('data-medal-type')).toBe('rhythm_6')
		expect(badges[0].text()).toContain('Alice')
	})

	it('has no unconditional contribution ranking surfaces (WP4 pin)', async () => {
		const wrapper = await mountView()

		expect(wrapper.find('.podium').exists()).toBe(false)
		expect(wrapper.find('.score-col').exists()).toBe(false)
		expect(wrapper.find('.streak-badge').exists()).toBe(false)
		expect(wrapper.find('.time-filter').exists()).toBe(false)
		// No freeze indicator anywhere.
		const html = wrapper.html()
		expect(html).not.toContain('freeze')
	})

	it('calculates initials from display name correctly', async () => {
		const wrapper = await mountView()
		expect(wrapper.vm.getInitials('John Doe')).toBe('JD')
		expect(wrapper.vm.getInitials('Alice')).toBe('A')
		expect(wrapper.vm.getInitials('')).toBe('?')
	})
})
