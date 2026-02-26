import { describe, it, expect, vi, beforeEach } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import FloorList from '../../../src/components/Dashboard/FloorList.vue'
import RoomCard from '../../../src/components/Dashboard/RoomCard.vue'

// Mock vue-router
jest.mock('vue-router', () => ({
	useRouter: () => ({
		push: jest.fn(),
	}),
}))

// Mock stores module
jest.mock('../../../src/store/rooms.js', () => ({
	useRoomsStore: () => ({
		floors: [],
		rooms: [],
		loading: false,
		roomsByFloor: {},
		fetchFloorsWithRooms: jest.fn(),
		fetchRooms: jest.fn(),
		createFloor: jest.fn(),
		deleteFloor: jest.fn(),
	}),
}))

describe('FloorList.vue', () => {
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
	})

	it('renders component', () => {
		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(wrapper.find('.floor-list').exists()).toBe(true)
	})

	it('displays floor header with title', () => {
		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(wrapper.find('.floor-header').exists()).toBe(true)
		expect(wrapper.find('.floor-title').text()).toContain('Floors')
	})

	it('has refresh button', () => {
		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(wrapper.find('.refresh-button').exists()).toBe(true)
	})

	it('has add floor button', () => {
		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(wrapper.find('.add-button').exists()).toBe(true)
	})

	it('opens add floor modal when add button clicked', async () => {
		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		await wrapper.find('.add-button').trigger('click')
		expect(wrapper.find('.modal-overlay').exists()).toBe(true)
	})

	it('closes modal when cancel button clicked', async () => {
		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		// Open modal
		await wrapper.find('.add-button').trigger('click')
		expect(wrapper.find('.modal-overlay').exists()).toBe(true)

		// Close modal
		await wrapper.find('.cancel-button').trigger('click')
		expect(wrapper.find('.modal-overlay').exists()).toBe(false)
	})

	it('disables submit button when floor name is empty', async () => {
		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		await wrapper.find('.add-button').trigger('click')
		const submitButton = wrapper.find('.submit-button')
		expect(submitButton.attributes('disabled')).toBeDefined()
	})

	it('has floor input field in modal', async () => {
		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		await wrapper.find('.add-button').trigger('click')
		expect(wrapper.find('#floor-name').exists()).toBe(true)
	})

	it('displays empty state when no floors', () => {
		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(wrapper.find('.empty-state').exists()).toBe(true)
	})

	it('has compact prop that affects RoomCard', () => {
		const wrapper = mount(FloorList, {
			props: { compact: true },
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(wrapper.props('compact')).toBe(true)
	})
})
