import { describe, it, expect, vi, beforeEach } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import Leaderboard from '../../../src/views/Leaderboard.vue'

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

// Mock stores
jest.mock('../../../src/store/user.js', () => ({
	useUserStore: () => ({
		currentUser: { id: 1, nextcloudUserId: 'user1', displayName: 'Current User', contributionScore: 150 },
		userId: 'user1',
		houseUsers: [
			{ id: 1, nextcloudUserId: 'user2', displayName: 'Alice', contributionScore: 200, currentStreak: 5, totalTasksCompleted: 42 },
			{ id: 2, nextcloudUserId: 'user1', displayName: 'Current User', contributionScore: 150, currentStreak: 3, totalTasksCompleted: 35 },
			{ id: 3, nextcloudUserId: 'user3', displayName: 'Bob', contributionScore: 100, currentStreak: 2, totalTasksCompleted: 28 },
		],
		getLeaderboard: [
			{ id: 1, nextcloudUserId: 'user2', displayName: 'Alice', contributionScore: 200, currentStreak: 5, totalTasksCompleted: 42 },
			{ id: 2, nextcloudUserId: 'user1', displayName: 'Current User', contributionScore: 150, currentStreak: 3, totalTasksCompleted: 35 },
			{ id: 3, nextcloudUserId: 'user3', displayName: 'Bob', contributionScore: 100, currentStreak: 2, totalTasksCompleted: 28 },
		],
		initializeCurrentUser: jest.fn(),
		fetchHouseUsers: jest.fn(),
	}),
}))

// Mock child components
jest.mock('../../../src/components/Common/Icon.vue', () => ({
	name: 'Icon',
	props: ['name', 'size'],
	template: '<span class="icon">{{ name }}</span>',
}))

jest.mock('../../../src/components/Common/MedalBadge.vue', () => ({
	name: 'MedalBadge',
	props: ['type', 'size'],
	template: '<div class="medal-badge">{{ type }}</div>',
}))

describe('Leaderboard.vue', () => {
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
	})

	it('renders leaderboard component', () => {
		const wrapper = mount(Leaderboard, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.leaderboard').exists()).toBe(true)
	})

	it('displays header with title', () => {
		const wrapper = mount(Leaderboard, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.leaderboard-header h1').text()).toContain('Leaderboard')
	})

	it('displays subtitle', () => {
		const wrapper = mount(Leaderboard, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.subtitle').exists()).toBe(true)
	})

	it('has time period filters', () => {
		const wrapper = mount(Leaderboard, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.time-filter').exists()).toBe(true)
	})

	it('renders filter buttons for each period', () => {
		const wrapper = mount(Leaderboard, {
			global: {
				plugins: [pinia],
			},
		})

		const filterButtons = wrapper.findAll('.filter-button')
		expect(filterButtons.length).toBe(3)
	})

	it('has active period filter by default', () => {
		const wrapper = mount(Leaderboard, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.vm.selectedPeriod).toBe('week')
	})

	it('renders podium section when 3+ users', () => {
		const wrapper = mount(Leaderboard, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.podium').exists()).toBe(true)
	})

	it('renders 3 podium items for top 3 users', () => {
		const wrapper = mount(Leaderboard, {
			global: {
				plugins: [pinia],
			},
		})

		const podiumItems = wrapper.findAll('.podium-item')
		expect(podiumItems.length).toBe(3)
	})

	it('renders leaderboard list', () => {
		const wrapper = mount(Leaderboard, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.leaderboard-list').exists()).toBe(true)
	})

	it('renders list header columns', () => {
		const wrapper = mount(Leaderboard, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.list-header').exists()).toBe(true)
	})

	it('renders leaderboard rows for each user', () => {
		const wrapper = mount(Leaderboard, {
			global: {
				plugins: [pinia],
			},
		})

		const rows = wrapper.findAll('.leaderboard-row')
		expect(rows.length).toBe(3)
	})

	it('marks current user row', () => {
		const wrapper = mount(Leaderboard, {
			global: {
				plugins: [pinia],
			},
		})

		const currentUserRow = wrapper.find('.current-user')
		expect(currentUserRow.exists()).toBe(true)
	})

	it('displays "You" badge for current user', () => {
		const wrapper = mount(Leaderboard, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.you-badge').exists()).toBe(true)
	})

	it('displays user scores', () => {
		const wrapper = mount(Leaderboard, {
			global: {
				plugins: [pinia],
			},
		})

		const scores = wrapper.findAll('.score-col')
		expect(scores.length).toBeGreaterThan(0)
	})

	it('displays streak badges', () => {
		const wrapper = mount(Leaderboard, {
			global: {
				plugins: [pinia],
			},
		})

		const streaks = wrapper.findAll('.streak-badge')
		expect(streaks.length).toBeGreaterThan(0)
	})

	it('calculates initials from display name correctly', () => {
		const wrapper = mount(Leaderboard, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.vm.getInitials('John Doe')).toBe('JD')
		expect(wrapper.vm.getInitials('Alice')).toBe('A')
		expect(wrapper.vm.getInitials('')).toBe('?')
	})

	it('formats score with thousands separator', () => {
		const wrapper = mount(Leaderboard, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.vm.formatScore(1234)).toBe('1,234')
		expect(wrapper.vm.formatScore(null)).toBe('0')
	})

	it('identifies current user correctly', () => {
		const wrapper = mount(Leaderboard, {
			global: {
				plugins: [pinia],
			},
		})

		const currentUser = { nextcloudUserId: 'user1' }
		expect(wrapper.vm.isCurrentUser(currentUser)).toBe(true)
	})

	it('changes active filter when clicked', async () => {
		const wrapper = mount(Leaderboard, {
			global: {
				plugins: [pinia],
			},
		})

		const filters = wrapper.findAll('.filter-button')
		await filters.at(1).trigger('click')

		expect(wrapper.vm.selectedPeriod).toBe('month')
	})

	it('computes podium heights correctly', () => {
		const wrapper = mount(Leaderboard, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.vm.getPodiumHeight(1)).toBe(120)
		expect(wrapper.vm.getPodiumHeight(2)).toBe(80)
		expect(wrapper.vm.getPodiumHeight(3)).toBe(60)
	})
})
