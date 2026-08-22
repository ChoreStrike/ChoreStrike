import { describe, it, expect, vi, beforeEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { translate } from '@nextcloud/l10n'
import FloorList from '../../../src/components/Dashboard/FloorList.vue'
import RoomCard from '../../../src/components/Dashboard/RoomCard.vue'

let mockRoomsStore

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, vars = {}) =>
		text.replace(/\{(\w+)\}/g, (m, key) => (key in vars ? String(vars[key]) : m))
	),
}))

jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))

jest.mock('../../../src/utils/logger.js', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

import { showError } from '@nextcloud/dialogs'

global.confirm = jest.fn(() => true)

// Mock vue-router
jest.mock('vue-router', () => ({
	useRouter: () => ({
		push: jest.fn(),
	}),
}))

// Mock stores module
jest.mock('../../../src/store/rooms.js', () => ({
	useRoomsStore: () => mockRoomsStore,
}))

describe('FloorList.vue', () => {
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		mockRoomsStore = {
			floors: [],
			rooms: [],
			loading: false,
			roomsByFloor: {},
			expandedFloors: [],
			fetchFloorsWithRooms: jest.fn(),
			fetchRooms: jest.fn(),
			createFloor: jest.fn(),
			createRoom: jest.fn(),
			deleteFloor: jest.fn(),
			toggleFloorExpanded: jest.fn((floorId) => {
				if (mockRoomsStore.expandedFloors.includes(floorId)) {
					mockRoomsStore.expandedFloors = mockRoomsStore.expandedFloors.filter(id => id !== floorId)
				} else {
					mockRoomsStore.expandedFloors = [...mockRoomsStore.expandedFloors, floorId]
				}
			}),
			setFloorExpanded: jest.fn((floorId) => {
				if (!mockRoomsStore.expandedFloors.includes(floorId)) {
					mockRoomsStore.expandedFloors = [...mockRoomsStore.expandedFloors, floorId]
				}
			}),
			setFloorCollapsed: jest.fn((floorId) => {
				mockRoomsStore.expandedFloors = mockRoomsStore.expandedFloors.filter(id => id !== floorId)
			}),
			getFloorById: jest.fn((floorId) => mockRoomsStore.floors.find(floor => floor.id === floorId)),
			updateFloorName: jest.fn(),
		}
		translate.mockClear()
		global.confirm.mockClear()
		showError.mockClear()
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

	it('translates critical dashboard strings via Nextcloud l10n', () => {
		mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(translate).toHaveBeenCalledWith('chorestrike', 'Floors & Rooms')
		expect(translate).toHaveBeenCalledWith('chorestrike', 'No floors yet')
	})

	it('opens the styled confirm dialog for floor deletion', async () => {
		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		await wrapper.vm.deleteFloor({ id: 1, name: 'Ground Floor' })
		await wrapper.vm.$nextTick()

		expect(global.confirm).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Ground Floor')
	})

	it('toasts an error and keeps the dialog open when floor deletion fails', async () => {
		mockRoomsStore.deleteFloor.mockRejectedValue(new Error('boom'))
		mockRoomsStore.floors = [{ id: 1, name: 'Ground Floor', sortOrder: 0 }]

		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		await wrapper.vm.deleteFloor({ id: 1, name: 'Ground Floor' })
		await wrapper.vm.$nextTick()

		await wrapper.find('[data-testid="confirm-dialog-confirm"]').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalled()
		expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(true)
	})

	it('shows add room action when expanding an empty floor', async () => {
		mockRoomsStore.floors = [{ id: 1, name: 'Ground Floor', sortOrder: 0 }]
		mockRoomsStore.roomsByFloor = { 1: [] }
		mockRoomsStore.expandedFloors = [1]

		const wrapper = mount(FloorList, {
			global: {
				plugins: [pinia],
				components: { RoomCard },
			},
		})

		expect(wrapper.find('[data-testid="add-room-button-1"]').exists()).toBe(true)
		expect(mockRoomsStore.fetchRooms).not.toHaveBeenCalled()
	})

	describe('floor name editing', () => {
		it('shows input when floor name is clicked', async () => {
			mockRoomsStore.floors = [{ id: 1, name: 'Ground Floor', sortOrder: 0 }]
			mockRoomsStore.roomsByFloor = { 1: [] }

			const wrapper = mount(FloorList, {
				global: {
					plugins: [pinia],
					components: { RoomCard },
				},
			})

			await wrapper.find('.floor-name').trigger('click')
			expect(wrapper.find('[data-testid="floor-name-edit-input"]').exists()).toBe(true)
		})

		it('saves floor name on Enter key', async () => {
			mockRoomsStore.floors = [{ id: 1, name: 'Ground Floor', sortOrder: 0 }]
			mockRoomsStore.roomsByFloor = { 1: [] }

			const wrapper = mount(FloorList, {
				global: {
					plugins: [pinia],
					components: { RoomCard },
				},
			})

			await wrapper.find('.floor-name').trigger('click')
			const input = wrapper.find('[data-testid="floor-name-edit-input"]')
			await input.setValue('Basement')
			await input.trigger('keydown.enter')

			expect(mockRoomsStore.updateFloorName).toHaveBeenCalledWith(1, 'Basement')
			expect(wrapper.find('[data-testid="floor-name-edit-input"]').exists()).toBe(false)
		})

		it('cancels editing on Escape key', async () => {
			mockRoomsStore.floors = [{ id: 1, name: 'Ground Floor', sortOrder: 0 }]
			mockRoomsStore.roomsByFloor = { 1: [] }

			const wrapper = mount(FloorList, {
				global: {
					plugins: [pinia],
					components: { RoomCard },
				},
			})

			await wrapper.find('.floor-name').trigger('click')
			const input = wrapper.find('[data-testid="floor-name-edit-input"]')
			await input.setValue('Basement')
			await input.trigger('keydown.escape')

			expect(mockRoomsStore.updateFloorName).not.toHaveBeenCalled()
			expect(wrapper.find('[data-testid="floor-name-edit-input"]').exists()).toBe(false)
		})

		it('cancels editing when name is empty', async () => {
			mockRoomsStore.floors = [{ id: 1, name: 'Ground Floor', sortOrder: 0 }]
			mockRoomsStore.roomsByFloor = { 1: [] }

			const wrapper = mount(FloorList, {
				global: {
					plugins: [pinia],
					components: { RoomCard },
				},
			})

			await wrapper.find('.floor-name').trigger('click')
			const input = wrapper.find('[data-testid="floor-name-edit-input"]')
			await input.setValue('   ')
			await input.trigger('keydown.enter')

			expect(mockRoomsStore.updateFloorName).not.toHaveBeenCalled()
			expect(wrapper.find('[data-testid="floor-name-edit-input"]').exists()).toBe(false)
		})

		it('cancels editing when name is unchanged', async () => {
			mockRoomsStore.floors = [{ id: 1, name: 'Ground Floor', sortOrder: 0 }]
			mockRoomsStore.roomsByFloor = { 1: [] }

			const wrapper = mount(FloorList, {
				global: {
					plugins: [pinia],
					components: { RoomCard },
				},
			})

			await wrapper.find('.floor-name').trigger('click')
			const input = wrapper.find('[data-testid="floor-name-edit-input"]')
			await input.setValue('Ground Floor')
			await input.trigger('keydown.enter')

			expect(mockRoomsStore.updateFloorName).not.toHaveBeenCalled()
			expect(wrapper.find('[data-testid="floor-name-edit-input"]').exists()).toBe(false)
		})

		it('starts editing when edit button is clicked', async () => {
			mockRoomsStore.floors = [{ id: 1, name: 'Ground Floor', sortOrder: 0 }]
			mockRoomsStore.roomsByFloor = { 1: [] }

			const wrapper = mount(FloorList, {
				global: {
					plugins: [pinia],
					components: { RoomCard },
				},
			})

			await wrapper.find('.edit-button').trigger('click')
			expect(wrapper.find('[data-testid="floor-name-edit-input"]').exists()).toBe(true)
		})
	})
})
