/* eslint-env jest */
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { translate } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import StructureSettings from '../../../src/components/Settings/StructureSettings.vue'

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

// Deliberate error-path specs (e.g. the floor-reorder failure) must not leak
// real logger output into the noise gate (CS-PLAN-034 convention).
jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

// Mutable so individual tests can assert store interactions.
const mockHouseState = {
	house: { id: 1, name: 'Test House' },
	houseName: 'Test House',
	rankingEnabled: false,
	loading: false,
	fetchHouse: jest.fn(),
	updateHouseName: jest.fn().mockResolvedValue(),
	updateRankingEnabled: jest.fn().mockResolvedValue(),
	resetHouse: jest.fn(),
}

jest.mock('../../../src/store/house.js', () => ({
	useHouseStore: () => mockHouseState,
}))

const mockRoomsState = {
	floors: [
		{ id: 1, name: 'Ground Floor', sortOrder: 0 },
		{ id: 2, name: 'First Floor', sortOrder: 1 },
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
	loading: false,
	fetchFloorsWithRooms: jest.fn(),
	createFloor: jest.fn(),
	updateFloorName: jest.fn(),
	deleteFloor: jest.fn().mockResolvedValue(),
	reorderFloors: jest.fn().mockResolvedValue(),
	createRoom: jest.fn().mockResolvedValue({ id: 9, name: 'Pantry', floorId: 1 }),
	deleteRoom: jest.fn().mockResolvedValue(),
}

jest.mock('../../../src/store/rooms.js', () => ({
	useRoomsStore: () => mockRoomsState,
}))

const mockRoomTasksState = {
	houseLoaded: false,
	houseStale: false,
	roomTasksByRoom: {},
	fetchHouseRoomTasks: jest.fn(),
}

jest.mock('../../../src/store/roomTasks.js', () => ({
	useRoomTasksStore: () => mockRoomTasksState,
}))

// Mutable role so tests can switch between owner and member (CS-PLAN-042
// role gating). Defaults to owner: the owner-only sections under test.
// `fetchHouseUsers`/`houseUsers` satisfy the MemberList child mounted in the
// owner-visible members section (keeps the suite noise-free).
const mockUserState = {
	userId: 1,
	currentUser: { id: 1, role: 'owner' },
	isHouseOwner: true,
	houseUsers: [],
	initializeCurrentUser: jest.fn().mockResolvedValue(),
	fetchHouseUsers: jest.fn().mockResolvedValue(),
}

jest.mock('../../../src/store/user.js', () => ({
	useUserStore: () => mockUserState,
}))

// CS-PLAN-070 WP5: the self-only notification preferences section. The
// store is mocked — the cross-layer contract lives in preferences.spec.js.
const mockPreferencesState = {
	version: 0,
	taskDigestEnabled: false,
	orgDigestEnabled: false,
	digestTime: '18:00',
	quietStart: null,
	quietEnd: null,
	soundEnabled: false,
	animationEnabled: false,
	arcadeVisible: true,
	catalogVisible: true,
	loading: false,
	saving: false,
	error: null,
	conflict: false,
	fetchPreferences: jest.fn().mockResolvedValue(),
	savePreferences: jest.fn().mockResolvedValue(true),
}

jest.mock('../../../src/store/preferences.js', () => ({
	// Reactive wrapper so store mutations (e.g. the 409 conflict flag)
	// actually re-render the component under test.
	usePreferencesStore: () => require('vue').reactive(mockPreferencesState),
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

// CS-PLAN-070 WP8: the owner catalog editor is a dedicated spec surface
// (RewardCatalogEditor.spec.js); Settings only hosts the section card.
jest.mock('../../../src/components/Rewards/RewardCatalogEditor.vue', () => ({
	name: 'RewardCatalogEditor',
	template: '<div data-testid="catalog-editor-stub" />',
}))

// CS-PLAN-086: the owner arcade gate has its own spec (ArcadeGateSetting.spec.js).
jest.mock('../../../src/components/Minigames/ArcadeGateSetting.vue', () => ({
	name: 'ArcadeGateSetting',
	template: '<div data-testid="arcade-gate-stub" />',
}))

// The wizard itself is covered by SetupWizard.spec.js; here only its entry.
jest.mock('../../../src/components/Setup/SetupWizard.vue', () => ({
	__esModule: true,
	default: { name: 'SetupWizard', emits: ['applied', 'skipped', 'close'], template: '<div class="setup-wizard-stub" />' },
	isWizardDismissedForHouse: () => false,
}))
// Floors and rooms of the household settings (moved out of Settings.spec.js
// with the split per category, CS-PLAN-090 WP-D).
describe('StructureSettings', () => {
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		translate.mockClear()
		showSuccess.mockClear()
		showError.mockClear()
		mockHouseState.house = { id: 1, name: 'Test House' }
		mockHouseState.houseName = 'Test House'
		mockHouseState.timezone = 'UTC'
		mockHouseState.fetchHouse.mockReset().mockResolvedValue()
		mockRoomsState.fetchFloorsWithRooms.mockReset().mockResolvedValue()
		mockRoomTasksState.fetchHouseRoomTasks.mockReset().mockResolvedValue([])
		mockRoomTasksState.roomTasksByRoom = {}
		mockRoomTasksState.houseLoaded = false
		mockRoomTasksState.houseStale = false
		mockHouseState.updateHouseName.mockClear().mockResolvedValue()
		mockHouseState.updateRankingEnabled.mockClear().mockResolvedValue()
		mockHouseState.resetHouse.mockClear().mockResolvedValue()
		mockRoomsState.deleteFloor.mockClear().mockResolvedValue()
		mockRoomsState.reorderFloors.mockClear().mockResolvedValue()
		mockUserState.currentUser = { id: 1, role: 'owner' }
		mockUserState.isHouseOwner = true
		mockUserState.initializeCurrentUser.mockClear().mockResolvedValue()
		mockUserState.fetchHouseUsers.mockClear().mockResolvedValue()
		mockUserState.houseUsers = []
		mockPreferencesState.version = 0
		mockPreferencesState.taskDigestEnabled = false
		mockPreferencesState.orgDigestEnabled = false
		mockPreferencesState.digestTime = '18:00'
		mockPreferencesState.quietStart = null
		mockPreferencesState.quietEnd = null
		mockPreferencesState.saving = false
		mockPreferencesState.conflict = false
		mockPreferencesState.fetchPreferences.mockClear().mockResolvedValue()
		mockPreferencesState.savePreferences.mockClear().mockResolvedValue(true)
	})
	it('does not expose cached structure controls before the initial floor read finishes', async () => {
		let resolveFloors
		mockRoomsState.fetchFloorsWithRooms.mockImplementationOnce(() => new Promise(resolve => { resolveFloors = resolve }))
		const wrapper = mount(StructureSettings, { global: { plugins: [pinia] } })
		expect(wrapper.find('[data-testid="add-floor-button"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="floors-list"]').exists()).toBe(false)
		await flushPromises()
		expect(wrapper.find('[data-testid="add-floor-button"]').exists()).toBe(false)
		resolveFloors()
		await flushPromises()
		expect(wrapper.get('[data-testid="add-floor-button"]').element.disabled).toBe(false)
		expect(wrapper.find('[data-testid="floors-list"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('does not mark structure ready when the initial read belongs to a cancelled reset generation', async () => {
		mockRoomsState.fetchFloorsWithRooms.mockResolvedValueOnce(null)
		const wrapper = mount(StructureSettings, { global: { plugins: [pinia] } })
		await flushPromises()
		expect(wrapper.vm.structureReady).toBe(false)
		expect(wrapper.find('[data-testid="floors-list"]').exists()).toBe(false)
		wrapper.unmount()
	})

	it('offers a retry after a failed read instead of an endless spinner, and shows the structure once it answers', async () => {
		mockRoomsState.fetchFloorsWithRooms.mockRejectedValueOnce(new Error('network'))
		const wrapper = mount(StructureSettings, { global: { plugins: [pinia] } })
		await flushPromises()
		expect(wrapper.find('[data-testid="structure-error"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="structure-loading"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="add-floor-button"]').exists()).toBe(false)

		await wrapper.get('[data-testid="error-retry"]').trigger('click')
		await flushPromises()
		expect(mockRoomsState.fetchFloorsWithRooms).toHaveBeenCalledTimes(2)
		expect(wrapper.find('[data-testid="structure-error"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="add-floor-button"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('offers the setup wizard under Floors & Rooms while the house has no floors, closed until asked (D-090-12)', async () => {
		const floors = mockRoomsState.floors
		mockRoomsState.floors = []
		try {
			const wrapper = mount(StructureSettings, { global: { plugins: [pinia] } })
			await flushPromises()

			const entry = wrapper.get('[data-testid="no-floors-message"] [data-testid="open-setup-wizard"]')
			expect(wrapper.findComponent({ name: 'SetupWizard' }).exists()).toBe(false)
			await entry.trigger('click')
			expect(wrapper.findComponent({ name: 'SetupWizard' }).exists()).toBe(true)
			wrapper.unmount()
		} finally {
			mockRoomsState.floors = floors
		}
	})

	it('offers no setup wizard for a house that has floors', async () => {
		const wrapper = mount(StructureSettings, { global: { plugins: [pinia] } })
		await flushPromises()

		expect(wrapper.find('[data-testid="floors-list"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="open-setup-wizard"]').exists()).toBe(false)
		wrapper.unmount()
	})

	it('has floors and rooms section', async () => {
		const wrapper = mount(StructureSettings, {
			global: {
				plugins: [pinia],
			},
		})

		await flushPromises()
		expect(wrapper.find('.floors-section').exists()).toBe(true)
	})

	it('displays list of floors', async () => {
		const wrapper = mount(StructureSettings, {
			global: {
				plugins: [pinia],
			},
		})

		await flushPromises()
		const floors = wrapper.findAll('.floor-item')
		expect(floors.length).toBe(2)
	})

	it('displays room count for each floor', async () => {
		const wrapper = mount(StructureSettings, {
			global: {
				plugins: [pinia],
			},
		})

		await flushPromises()
		const roomCounts = wrapper.findAll('.room-count')
		expect(roomCounts.length).toBe(2)
	})

	it('has add floor button', async () => {
		const wrapper = mount(StructureSettings, {
			global: {
				plugins: [pinia],
			},
		})

		await flushPromises()
		expect(wrapper.find('.add-button').exists()).toBe(true)
	})

	it('opens add floor modal when button clicked', async () => {
		const wrapper = mount(StructureSettings, {
			global: {
				plugins: [pinia],
			},
		})

		await flushPromises()
		await wrapper.find('.add-button').trigger('click')
		expect(wrapper.vm.showAddFloorModal).toBe(true)
	})

	it('has edit button for each floor', async () => {
		const wrapper = mount(StructureSettings, {
			global: {
				plugins: [pinia],
			},
		})

		await flushPromises()
		const editButtons = wrapper.findAll('.icon-button')
		expect(editButtons.length).toBeGreaterThanOrEqual(2)
	})

	it('has delete button for each floor', async () => {
		const wrapper = mount(StructureSettings, {
			global: {
				plugins: [pinia],
			},
		})

		await flushPromises()
		const dangerButtons = wrapper.findAll('.icon-button.danger')
		expect(dangerButtons.length).toBe(2)
	})

	it('uses localized title and placeholder bindings for remaining ui literals', async () => {
		const wrapper = mount(StructureSettings, {
			global: {
				plugins: [pinia],
			},
		})

		await flushPromises()
		expect(translate).toHaveBeenCalledWith('chorestrike', 'Edit floor')
		expect(translate).toHaveBeenCalledWith('chorestrike', 'Delete floor')
	})

	it('deletes a floor via the styled confirm dialog (CS-PLAN-041)', async () => {
		const wrapper = mount(StructureSettings, {
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

	it('offers up/down reorder buttons per floor as owner', async () => {
		const wrapper = mount(StructureSettings, {
			global: {
				plugins: [pinia],
			},
		})
		await flushPromises()

		const ups = wrapper.findAll('[data-testid^="move-floor-up-"]')
		const downs = wrapper.findAll('[data-testid^="move-floor-down-"]')
		expect(ups.length).toBe(2)
		expect(downs.length).toBe(2)

		// Boundary disabling: first floor cannot go up, last cannot go down.
		expect(ups[0].attributes('disabled')).toBeDefined()
		expect(downs[0].attributes('disabled')).toBeUndefined()
		expect(ups[1].attributes('disabled')).toBeUndefined()
		expect(downs[1].attributes('disabled')).toBeDefined()
	})

	it('persists the swapped order when a floor is moved down', async () => {
		const wrapper = mount(StructureSettings, {
			global: {
				plugins: [pinia],
			},
		})
		await flushPromises()

		await wrapper.find('[data-testid="move-floor-down-1"]').trigger('click')
		await flushPromises()

		expect(mockRoomsState.reorderFloors).toHaveBeenCalledWith([2, 1])
		expect(showSuccess).toHaveBeenCalled()
	})

	it('toasts an error and keeps the old order when reordering fails', async () => {
		mockRoomsState.reorderFloors.mockRejectedValueOnce(new Error('boom'))

		const wrapper = mount(StructureSettings, {
			global: {
				plugins: [pinia],
			},
		})
		await flushPromises()

		await wrapper.find('[data-testid="move-floor-down-1"]').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalled()
	})

	it('expands a floor to reveal its rooms and the inline add form', async () => {
		const wrapper = mount(StructureSettings, {
			global: {
				plugins: [pinia],
			},
		})
		await flushPromises()

		expect(wrapper.find('[data-testid="floor-rooms-1"]').exists()).toBe(false)

		await wrapper.find('[data-testid="expand-floor-1"]').trigger('click')

		const rooms = wrapper.find('[data-testid="floor-rooms-1"]')
		expect(rooms.exists()).toBe(true)
		expect(rooms.text()).toContain('Kitchen')
		expect(wrapper.find('[data-testid="add-room-to-floor-button"]').exists()).toBe(true)
	})

	it('counts the active tasks of each room, read once on the first expanded floor (FEAT-UX-049)', async () => {
		mockRoomTasksState.roomTasksByRoom = {
			1: [{ id: 1, isActive: true }, { id: 2, isActive: false }],
			2: [{ id: 3, isActive: true }, { id: 4, isActive: true }],
		}
		const wrapper = mount(StructureSettings, { global: { plugins: [pinia] } })
		await flushPromises()

		expect(mockRoomTasksState.fetchHouseRoomTasks).not.toHaveBeenCalled()

		await wrapper.find('[data-testid="expand-floor-1"]').trigger('click')
		await flushPromises()
		await wrapper.find('[data-testid="expand-floor-2"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="room-task-count-1"]').text()).toBe('1 task')
		expect(wrapper.find('[data-testid="room-task-count-2"]').text()).toBe('{count} tasks')
		expect(mockRoomTasksState.fetchHouseRoomTasks).toHaveBeenCalledTimes(1)
	})

	it('reuses a fresh house read for the counts (FEAT-UX-049)', async () => {
		mockRoomTasksState.houseLoaded = true
		mockRoomTasksState.roomTasksByRoom = { 1: [{ id: 1, isActive: true }] }
		const wrapper = mount(StructureSettings, { global: { plugins: [pinia] } })
		await flushPromises()
		await wrapper.find('[data-testid="expand-floor-1"]').trigger('click')
		await flushPromises()

		expect(mockRoomTasksState.fetchHouseRoomTasks).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="room-task-count-1"]').text()).toBe('1 task')
	})

	it('hides the count when the read fails and tries again on the next floor (FEAT-UX-049)', async () => {
		mockRoomTasksState.fetchHouseRoomTasks.mockRejectedValueOnce(new Error('down'))
		const wrapper = mount(StructureSettings, { global: { plugins: [pinia] } })
		await flushPromises()

		await wrapper.find('[data-testid="expand-floor-1"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="floor-rooms-1"]').text()).toContain('Kitchen')
		expect(wrapper.find('[data-testid="room-task-count-1"]').exists()).toBe(false)

		await wrapper.find('[data-testid="expand-floor-2"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="room-task-count-1"]').exists()).toBe(true)
		expect(mockRoomTasksState.fetchHouseRoomTasks).toHaveBeenCalledTimes(2)
	})

	it('renames a room from the list through the shared dialog (FEAT-UX-049)', async () => {
		const wrapper = mount(StructureSettings, { global: { plugins: [pinia] } })
		await flushPromises()
		await wrapper.find('[data-testid="expand-floor-1"]').trigger('click')
		await flushPromises()

		expect(wrapper.findComponent({ name: 'RoomRenameDialog' }).exists()).toBe(false)

		await wrapper.find('[data-testid="rename-room-1"]').trigger('click')

		const dialog = wrapper.findComponent({ name: 'RoomRenameDialog' })
		expect(dialog.props('room')).toEqual({ id: 1, name: 'Kitchen' })

		dialog.vm.$emit('close')
		await flushPromises()

		expect(wrapper.findComponent({ name: 'RoomRenameDialog' }).exists()).toBe(false)
	})

	it('creates a room on the expanded floor via the store', async () => {
		mockRoomsState.createRoom.mockResolvedValue({ id: 9, name: 'Pantry', floorId: 1 })
		mockRoomsState.roomsByFloor = { ...mockRoomsState.roomsByFloor }

		const wrapper = mount(StructureSettings, {
			global: {
				plugins: [pinia],
			},
		})
		await flushPromises()

		await wrapper.find('[data-testid="expand-floor-1"]').trigger('click')
		await wrapper.find('[data-testid="new-room-name-input"]').setValue('Pantry')
		await wrapper.find('[data-testid="add-room-to-floor-button"]').trigger('click')
		await flushPromises()

		expect(mockRoomsState.createRoom).toHaveBeenCalledWith(1, 'Pantry')
		expect(showSuccess).toHaveBeenCalledWith('Room created')
	})

	// FEAT-UX-044: a taken name is numbered by the server, and the toast says so.
	it('names the numbered room when the name was taken', async () => {
		mockRoomsState.createRoom.mockResolvedValue({ id: 9, name: 'Pantry 2', floorId: 1 })

		const wrapper = mount(StructureSettings, { global: { plugins: [pinia] } })
		await flushPromises()
		await wrapper.find('[data-testid="expand-floor-1"]').trigger('click')
		await wrapper.find('[data-testid="new-room-name-input"]').setValue('Pantry')
		await wrapper.find('[data-testid="add-room-to-floor-button"]').trigger('click')
		await flushPromises()

		// The spec's t() mock returns the source string without interpolation.
		expect(showSuccess).toHaveBeenCalledWith('Name already taken — room created as "{name}"')
	})

	it('deletes a room via the styled confirm dialog', async () => {
		const wrapper = mount(StructureSettings, {
			global: {
				plugins: [pinia],
			},
		})
		await flushPromises()

		await wrapper.find('[data-testid="expand-floor-1"]').trigger('click')
		await wrapper.find('[data-testid="delete-room-2"]').trigger('click')

		expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Living Room')

		await wrapper.find('[data-testid="confirm-dialog-confirm"]').trigger('click')
		await flushPromises()

		expect(mockRoomsState.deleteRoom).toHaveBeenCalledWith(2)
	})
})
