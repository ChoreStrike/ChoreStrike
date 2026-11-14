/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach, afterEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ArcadeResetList from '@/components/Minigames/ArcadeResetList.vue'
import { useHouseStore } from '@/store/house.js'
import { formatDayKey } from '@/utils/dateKeys.js'

jest.mock('@/services/api.js', () => ({
	api: { get: jest.fn(), put: jest.fn(), post: jest.fn() },
}))

jest.mock('@/utils/logger', () => ({
	logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() },
}))

jest.mock('../../../src/components/Common/Modal.vue', () => ({
	name: 'Modal',
	props: { ariaLabel: { type: String, default: '' } },
	emits: ['close'],
	template: '<div class="modal-overlay" role="dialog" :aria-label="ariaLabel"><slot /><button class="close-btn" @click="$emit(\'close\')">X</button></div>',
}))

const { api } = require('@/services/api.js')

const PROGRESS = [
	{ userId: 'anna', displayName: 'Anna', finishedChores: 1, resetAt: null },
	{ userId: 'kid', displayName: 'Kid', finishedChores: 4, resetAt: '2026-11-13T23:30:00+00:00' },
	{ userId: 'ben', displayName: 'Ben', finishedChores: 0, resetAt: null },
]

/**
 * CS-PLAN-090 WP-J (D-090-63): the owner sees every member's finished
 * chores since their last reset and resets one person after a
 * confirmation; the highscores are never mentioned as lost.
 */
describe('ArcadeResetList', () => {
	let wrapper

	const rows = () => wrapper.findAll('[data-testid="arcade-reset-row"]')

	const mountList = async () => {
		const houseStore = useHouseStore()
		houseStore.house = { id: 1, name: 'Home', timezone: 'Europe/Berlin' }
		wrapper = mount(ArcadeResetList)
		await flushPromises()
	}

	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
		api.get.mockImplementation(async (url) => (url === '/api/v1/house/arcade-progress'
			? PROGRESS
			: { gateEnabled: true, finishedChores: 0, games: [] }))
	})

	afterEach(() => {
		wrapper?.unmount()
	})

	it('lists every member with a count, the reset day in the house zone, and a named button', async () => {
		await mountList()

		expect(rows()).toHaveLength(3)
		expect(rows()[0].text()).toContain('Anna')
		expect(rows()[0].text()).toContain('1 finished chore')
		expect(rows()[1].text()).toContain(`4 finished chores since the reset on ${formatDayKey('2026-11-14')}`)
		expect(rows()[2].text()).toContain('0 finished chores')
		expect(rows()[1].get('[data-testid="arcade-reset-button"]').attributes('aria-label')).toBe('Reset the arcade progress of Kid')
	})

	it('asks first, and cancelling resets nobody', async () => {
		await mountList()

		await rows()[1].get('[data-testid="arcade-reset-button"]').trigger('click')
		expect(wrapper.get('[data-testid="arcade-reset-dialog"]').text()).toContain('Kid starts over')
		expect(wrapper.get('[data-testid="arcade-reset-dialog"]').text()).toContain('Highscores stay.')

		await wrapper.get('.secondary-button').trigger('click')

		expect(wrapper.find('[data-testid="arcade-reset-dialog"]').exists()).toBe(false)
		expect(api.post).not.toHaveBeenCalled()
	})

	it('resets the confirmed member and shows the new list', async () => {
		await mountList()
		api.post.mockResolvedValue([PROGRESS[0], { ...PROGRESS[1], finishedChores: 0, resetAt: '2026-11-14T10:00:00+00:00' }, PROGRESS[2]])

		await rows()[1].get('[data-testid="arcade-reset-button"]').trigger('click')
		await wrapper.get('[data-testid="arcade-reset-confirm"]').trigger('click')
		await flushPromises()

		expect(api.post).toHaveBeenCalledWith('/api/v1/house/members/kid/arcade-reset')
		expect(wrapper.get('[data-testid="arcade-reset-done"]').text()).toBe('The arcade progress of Kid was reset.')
		expect(rows()[1].text()).toContain('0 finished chores since the reset on')
		expect(wrapper.find('[data-testid="arcade-reset-dialog"]').exists()).toBe(false)
	})

	it('reports a failed reset', async () => {
		await mountList()
		api.post.mockRejectedValue(new Error('Owner role required'))

		await rows()[0].get('[data-testid="arcade-reset-button"]').trigger('click')
		await wrapper.get('[data-testid="arcade-reset-confirm"]').trigger('click')
		await flushPromises()

		expect(wrapper.get('[data-testid="arcade-reset-error"]').attributes('role')).toBe('alert')
		expect(wrapper.find('[data-testid="arcade-reset-done"]').exists()).toBe(false)
		expect(rows()[0].text()).toContain('1 finished chore')
	})

	it('offers a retry when the list cannot be read', async () => {
		api.get.mockRejectedValueOnce(new Error('Network down'))
		await mountList()

		expect(wrapper.get('[data-testid="arcade-reset-load-error"]').attributes('role')).toBe('alert')
		await wrapper.get('[data-testid="arcade-reset-load-error"] button').trigger('click')
		await flushPromises()

		expect(rows()).toHaveLength(3)
	})
})
