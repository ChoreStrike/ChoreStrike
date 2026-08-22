import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises } from '@vue/test-utils'
import { translate } from '@nextcloud/l10n'
import Settings from '../../../src/views/Settings.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

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

jest.mock('@nextcloud/dialogs', () => ({
	showSuccess: jest.fn(),
	showError: jest.fn(),
}))

import { showSuccess } from '@nextcloud/dialogs'

// Mutable so individual tests can assert store interactions.
const mockHouseState = {
	house: { id: 1, name: 'Test House' },
	houseName: 'Test House',
	loading: false,
	fetchHouse: jest.fn(),
	updateHouseName: jest.fn().mockResolvedValue(),
	resetHouse: jest.fn(),
}

jest.mock('../../../src/store/house.js', () => ({
	useHouseStore: () => mockHouseState,
}))

const mockRoomsState = {
	floors: [
		{ id: 1, name: 'Ground Floor' },
		{ id: 2, name: 'First Floor' },
	],
	rooms: [
		{ id: 1, name: 'Kitchen', floorId: 1 },
		{ id: 2, name: 'Living Room', floorId: 1 },
		{ id: 3, name: 'Bedroom', floorId: 2 },
	],
	roomsByFloor: {
		1: [{ id: 1, name: 'Kitchen' }, { id: 2, name: 'Living Room' }],
		2: [{ id: 3, name: 'Bedroom' }],
	},
	fetchFloorsWithRooms: jest.fn(),
	createFloor: jest.fn(),
	updateFloorName: jest.fn(),
	deleteFloor: jest.fn().mockResolvedValue(),
}

jest.mock('../../../src/store/rooms.js', () => ({
	useRoomsStore: () => mockRoomsState,
}))

// Mock child components
jest.mock('../../../src/components/Common/Icon.vue', () => ({
	name: 'Icon',
	props: ['name', 'size'],
	template: '<span class="icon">{{ name }}</span>',
}))

jest.mock('../../../src/components/Common/Modal.vue', () => ({
	name: 'Modal',
	emits: ['close'],
	template: '<div class="modal-overlay"><slot /><button class="close-btn" @click="$emit(\'close\')">X</button></div>',
}))

describe('Settings.vue', () => {
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		translate.mockClear()
		showSuccess.mockClear()
		mockHouseState.updateHouseName.mockClear().mockResolvedValue()
		mockRoomsState.deleteFloor.mockClear().mockResolvedValue()
	})

	it('renders settings component', () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.settings').exists()).toBe(true)
	})

	it('displays header with title', () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.settings-header h1').text()).toContain('Settings')
	})

	it('has house name input field', () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('#house-name').exists()).toBe(true)
	})

	it('displays current house name in input', async () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		// Wait for mounted() to complete and set houseName
		await flushPromises()

		const input = wrapper.find('#house-name')
		expect(input.element.value).toBe('Test House')
	})

	it('has save button for house name', () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.save-button').exists()).toBe(true)
	})

	it('has floors and rooms section', () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.floors-section').exists()).toBe(true)
	})

	it('displays list of floors', () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		const floors = wrapper.findAll('.floor-item')
		expect(floors.length).toBe(2)
	})

	it('displays room count for each floor', () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		const roomCounts = wrapper.findAll('.room-count')
		expect(roomCounts.length).toBe(2)
	})

	it('has add floor button', () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.add-button').exists()).toBe(true)
	})

	it('has danger zone section', () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.danger-zone').exists()).toBe(true)
	})

	it('has reset house button', () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.danger-button').exists()).toBe(true)
	})

	it('opens add floor modal when button clicked', async () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		await wrapper.find('.add-button').trigger('click')
		expect(wrapper.vm.showAddFloorModal).toBe(true)
	})

	it('opens reset confirmation modal when reset clicked', async () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		await wrapper.find('.danger-button').trigger('click')
		expect(wrapper.vm.showResetModal).toBe(true)
	})

	it('disables save button when house name is unchanged', () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		const saveButton = wrapper.find('.save-button')
		expect(saveButton.attributes('disabled')).toBeDefined()
	})

	it('has edit button for each floor', () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		const editButtons = wrapper.findAll('.icon-button')
		expect(editButtons.length).toBeGreaterThanOrEqual(2)
	})

	it('has delete button for each floor', () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		const dangerButtons = wrapper.findAll('.icon-button.danger')
		expect(dangerButtons.length).toBe(2)
	})

	it('uses localized title and placeholder bindings for remaining ui literals', async () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		expect(translate).toHaveBeenCalledWith('chorestrike', 'Edit floor')
		expect(translate).toHaveBeenCalledWith('chorestrike', 'Delete floor')

		await wrapper.find('.danger-button').trigger('click')
		expect(translate).toHaveBeenCalledWith('chorestrike', 'RESET')
	})

	it('toasts success after saving the house name (CS-PLAN-041)', async () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})
		await flushPromises()

		await wrapper.find('[data-testid="house-name-input"]').setValue('Renamed House')
		await wrapper.find('[data-testid="save-house-name"]').trigger('click')
		await flushPromises()

		expect(mockHouseState.updateHouseName).toHaveBeenCalledWith('Renamed House')
		expect(showSuccess).toHaveBeenCalled()
	})

	it('deletes a floor via the styled confirm dialog (CS-PLAN-041)', async () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})
		await flushPromises()

		const deleteButtons = wrapper.findAll('.icon-button.danger')
		await deleteButtons[0].trigger('click')

		expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Ground Floor')

		await wrapper.find('[data-testid="confirm-dialog-confirm"]').trigger('click')
		await flushPromises()

		expect(mockRoomsState.deleteFloor).toHaveBeenCalledWith(1)
	})
})
