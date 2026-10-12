import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent } from 'vue'
import { useHouseMemberNames } from '../../../src/composables/useHouseMemberNames.js'

describe('useHouseMemberNames', () => {
	const mountHost = (store) => mount(defineComponent({
		name: 'Host',
		setup: () => ({ ...useHouseMemberNames(store) }),
		template: '<div />',
	}))

	it('fetches members on mount when none are loaded', async () => {
		const store = { houseUsers: [], fetchHouseUsers: jest.fn().mockResolvedValue([]) }
		const wrapper = mountHost(store)
		await flushPromises()
		expect(store.fetchHouseUsers).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.membersError).toBe(false)
		expect(wrapper.vm.membersLoading).toBe(false)
	})

	it('skips the fetch when members are already loaded', () => {
		const store = { houseUsers: [{ nextcloudUserId: 'mom', displayName: 'Mom' }], fetchHouseUsers: jest.fn() }
		mountHost(store)
		expect(store.fetchHouseUsers).not.toHaveBeenCalled()
	})

	it('exposes a non-blocking error and retries the member read', async () => {
		const store = {
			houseUsers: [],
			fetchHouseUsers: jest.fn()
				.mockRejectedValueOnce(new Error('offline'))
				.mockResolvedValue([]),
		}
		const wrapper = mountHost(store)
		await flushPromises()
		expect(wrapper.vm.membersError).toBe(true)

		await wrapper.vm.loadMembers()
		expect(store.fetchHouseUsers).toHaveBeenCalledTimes(2)
		expect(wrapper.vm.membersError).toBe(false)
	})

	it('ignores a concurrent second invocation while the first is loading', async () => {
		let resolveFetch
		const store = {
			houseUsers: [],
			fetchHouseUsers: jest.fn(() => new Promise((resolve) => { resolveFetch = resolve })),
		}
		const wrapper = mountHost(store)
		await wrapper.vm.loadMembers()
		expect(store.fetchHouseUsers).toHaveBeenCalledTimes(1)

		resolveFetch([{ nextcloudUserId: 'mom', displayName: 'Mom' }])
		await flushPromises()
		expect(wrapper.vm.membersLoading).toBe(false)
	})
})
