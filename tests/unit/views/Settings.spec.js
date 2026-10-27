/* eslint-env jest */
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { translate } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
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

// WP6 pause administration (Q-077-03 A): the owner house controls and the
// self-only PauseSettings surface read the pause store; the cross-layer
// contract lives in pauses.spec.js.
const mockPausesState = {
	pauses: [],
	// Plain projection (the real store derives it via a getter).
	pendingRequests: [],
	loading: false,
	saving: false,
	error: null,
	conflict: false,
	fetchPauses: jest.fn().mockResolvedValue([]),
	createHousePause: jest.fn().mockResolvedValue({ pauseGroupId: 1, revision: 1 }),
	approvePause: jest.fn().mockResolvedValue({ pauseGroupId: 1, revision: 2 }),
	rejectPause: jest.fn().mockResolvedValue({ pauseGroupId: 1, revision: 2 }),
	recognizeIllness: jest.fn().mockResolvedValue({ pauseGroupId: 2, revision: 1 }),
}

jest.mock('../../../src/store/pauses.js', () => ({
	usePausesStore: () => require('vue').reactive(mockPausesState),
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

describe('Settings.vue', () => {
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
		mockPausesState.pauses = []
		mockPausesState.pendingRequests = []
		mockPausesState.saving = false
		mockPausesState.conflict = false
		mockPausesState.error = null
		mockPausesState.fetchPauses.mockClear().mockResolvedValue([])
		mockPausesState.createHousePause.mockClear().mockResolvedValue({ pauseGroupId: 1, revision: 1 })
		mockPausesState.approvePause.mockClear().mockResolvedValue({ pauseGroupId: 1, revision: 2 })
		mockPausesState.rejectPause.mockClear().mockResolvedValue({ pauseGroupId: 1, revision: 2 })
		mockPausesState.recognizeIllness.mockClear().mockResolvedValue({ pauseGroupId: 2, revision: 1 })
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

	it('keeps house controls disabled until the actual house read completes', async () => {
		let resolveHouse
		mockHouseState.house = null
		mockHouseState.houseName = 'My House'
		mockHouseState.fetchHouse.mockImplementationOnce(() => new Promise(resolve => { resolveHouse = resolve }))
		const wrapper = mount(Settings, { global: { plugins: [pinia] } })
		for (const id of ['house-name-input', 'house-timezone-select', 'ranking-toggle']) {
			expect(wrapper.get(`[data-testid="${id}"]`).element.disabled).toBe(true)
		}
		await wrapper.setData({ houseTimezone: 'Europe/Berlin', rankingEnabled: true })
		await wrapper.vm.saveHouseTimezone()
		await wrapper.vm.saveRanking()
		expect(mockHouseState.updateHouseName).not.toHaveBeenCalled()
		expect(mockHouseState.updateRankingEnabled).not.toHaveBeenCalled()
		mockHouseState.house = { id: 1, name: 'Saved Home' }
		mockHouseState.houseName = 'Saved Home'
		resolveHouse(mockHouseState.house)
		await flushPromises()
		expect(wrapper.get('#house-name').element.value).toBe('Saved Home')
		wrapper.unmount()
	})

	it('initializes house drafts before a slow floor read and never overwrites typing when floors arrive', async () => {
		let resolveFloors
		mockRoomsState.fetchFloorsWithRooms.mockImplementationOnce(() => new Promise(resolve => { resolveFloors = resolve }))
		const wrapper = mount(Settings, { global: { plugins: [pinia] } })
		await flushPromises()
		expect(wrapper.get('#house-name').element.value).toBe('Test House')
		await wrapper.get('#house-name').setValue('Unsaved edit')
		resolveFloors()
		await flushPromises()
		expect(wrapper.get('#house-name').element.value).toBe('Unsaved edit')
		wrapper.unmount()
	})

	it('does not expose cached structure controls before the initial floor read finishes', async () => {
		let resolveFloors
		mockRoomsState.fetchFloorsWithRooms.mockImplementationOnce(() => new Promise(resolve => { resolveFloors = resolve }))
		const wrapper = mount(Settings, { global: { plugins: [pinia] } })
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
		const wrapper = mount(Settings, { global: { plugins: [pinia] } })
		await flushPromises()
		expect(wrapper.vm.structureReady).toBe(false)
		expect(wrapper.find('[data-testid="floors-list"]').exists()).toBe(false)
		wrapper.unmount()
	})

	it('offers retry after a failed house read without enabling fallback writes', async () => {
		mockHouseState.house = null
		mockHouseState.fetchHouse.mockRejectedValueOnce(new Error('Network error'))
		const wrapper = mount(Settings, { global: { plugins: [pinia] } })
		await flushPromises()
		expect(wrapper.get('#house-name').element.disabled).toBe(true)
		expect(wrapper.find('[data-testid="error-state"]').exists()).toBe(true)
		mockHouseState.house = { id: 1, name: 'Test House' }
		await wrapper.get('[data-testid="error-retry"]').trigger('click')
		await flushPromises()
		expect(wrapper.get('#house-name').element.value).toBe('Test House')
		expect(wrapper.get('#house-name').element.disabled).toBe(false)
		wrapper.unmount()
	})

	it('has save button for house name', () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('[data-testid="house-section"] .save-button').exists()).toBe(true)
	})

	it('has floors and rooms section', async () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		await flushPromises()
		expect(wrapper.find('.floors-section').exists()).toBe(true)
	})

	it('displays list of floors', async () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		await flushPromises()
		const floors = wrapper.findAll('.floor-item')
		expect(floors.length).toBe(2)
	})

	it('displays room count for each floor', async () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		await flushPromises()
		const roomCounts = wrapper.findAll('.room-count')
		expect(roomCounts.length).toBe(2)
	})

	it('has add floor button', async () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		await flushPromises()
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

		await flushPromises()
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

	it('submits the confirmed reset through the receipt-aware house store', async () => {
		const wrapper = mount(Settings, { global: { plugins: [pinia] } })
		await flushPromises()

		await wrapper.find('.danger-button').trigger('click')
		await wrapper.find('input[placeholder="RESET"]').setValue('RESET')
		await wrapper.find('.modal-overlay .danger-button').trigger('click')
		await flushPromises()

		expect(mockHouseState.resetHouse).toHaveBeenCalledTimes(1)
		expect(showSuccess).toHaveBeenCalled()
	})

	it('describes the reset as room/house-health only and names the retained facts (CS-PLAN-081 H4)', () => {
		const wrapper = mount(Settings, { global: { plugins: [pinia] } })

		// The danger-zone card must not promise a whole-house wipe: only
		// rooms and house health reset; setup/planning/history/finances/
		// rewards/achievements survive the unchanged backend contract.
		const cardText = wrapper.find('.danger-zone').text()
		expect(cardText).toContain('house health')
		expect(cardText).toContain('achievements are kept')
		expect(cardText).not.toContain('start fresh')
	})

	it('reset confirmation explains the retained setup, tasks and history and cancel does nothing (CS-PLAN-081 H4)', async () => {
		const wrapper = mount(Settings, { global: { plugins: [pinia] } })

		await wrapper.find('.danger-button').trigger('click')
		const modalText = wrapper.find('.modal-overlay').text()
		expect(modalText).toContain('house health is restored')
		expect(modalText).toContain('completion history')
		expect(modalText).not.toContain('delete all floors')

		await wrapper.find('.modal-overlay .secondary-button').trigger('click')
		expect(wrapper.vm.showResetModal).toBe(false)
		expect(mockHouseState.resetHouse).not.toHaveBeenCalled()
	})

	it('disables save button when house name is unchanged', () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		const saveButton = wrapper.find('[data-testid="house-section"] .save-button')
		expect(saveButton.attributes('disabled')).toBeDefined()
	})

	it('has edit button for each floor', async () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		await flushPromises()
		const editButtons = wrapper.findAll('.icon-button')
		expect(editButtons.length).toBeGreaterThanOrEqual(2)
	})

	it('has delete button for each floor', async () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		await flushPromises()
		const dangerButtons = wrapper.findAll('.icon-button.danger')
		expect(dangerButtons.length).toBe(2)
	})

	it('uses localized title and placeholder bindings for remaining ui literals', async () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})

		await flushPromises()
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

	// --- Owner ranking toggle (CS-PLAN-070 WP4, C5i/D10b) ---

	it('offers the owner the ranking toggle initialized from the house flag', async () => {
		const wrapper = mount(Settings, { global: { plugins: [pinia] } })
		await flushPromises()

		const toggle = wrapper.find('[data-testid="ranking-toggle"]')
		expect(toggle.exists()).toBe(true)
		expect(toggle.element.checked).toBe(false)
		// Unchanged flag keeps the save button disabled.
		expect(wrapper.find('[data-testid="save-house-ranking"]').attributes('disabled')).toBeDefined()
	})

	it('saves the ranking flag through the house store when toggled on', async () => {
		const wrapper = mount(Settings, { global: { plugins: [pinia] } })
		await flushPromises()

		await wrapper.find('[data-testid="ranking-toggle"]').setValue(true)
		await wrapper.find('[data-testid="save-house-ranking"]').trigger('click')
		await flushPromises()

		expect(mockHouseState.updateRankingEnabled).toHaveBeenCalledWith(true)
		expect(showSuccess).toHaveBeenCalled()
	})

	it('re-syncs the ranking checkbox when the save fails', async () => {
		mockHouseState.updateRankingEnabled.mockRejectedValueOnce(new Error('boom'))

		const wrapper = mount(Settings, { global: { plugins: [pinia] } })
		await flushPromises()

		await wrapper.find('[data-testid="ranking-toggle"]').setValue(true)
		await wrapper.find('[data-testid="save-house-ranking"]').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalled()
		expect(wrapper.find('[data-testid="ranking-toggle"]').element.checked).toBe(false)
	})

	it('hides the ranking toggle from members with the owner-only house section', async () => {
		mockUserState.currentUser = { id: 1, role: 'member' }
		mockUserState.isHouseOwner = false

		const wrapper = mount(Settings, { global: { plugins: [pinia] } })
		await flushPromises()

		expect(wrapper.find('[data-testid="ranking-toggle"]').exists()).toBe(false)
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

	// --- Role gating + floor reorder (CS-PLAN-042, UX-004) ---

	it('hides owner-only sections from members (no 403 paths)', async () => {
		mockUserState.currentUser = { id: 1, role: 'member' }
		mockUserState.isHouseOwner = false

		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})
		await flushPromises()

		expect(wrapper.find('[data-testid="house-section"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="floors-section"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="danger-zone"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="members-section"]').exists()).toBe(false)
		// CS-PLAN-070 WP8: the owner catalog administration is owner-only.
		expect(wrapper.find('[data-testid="catalog-section"]').exists()).toBe(false)
		// CS-PLAN-086: so is the arcade unlock gate.
		expect(wrapper.find('[data-testid="arcade-gate-section"]').exists()).toBe(false)
		// The personal title section stays visible for members.
		expect(wrapper.text()).toContain('Your Title')
	})

	it('shows owner-only sections to owners', async () => {
		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})
		await flushPromises()

		expect(wrapper.find('[data-testid="house-section"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="floors-section"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="danger-zone"]').exists()).toBe(true)
		// CS-PLAN-070 WP8: owner catalog administration hosts the editor.
		expect(wrapper.find('[data-testid="catalog-section"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="catalog-editor-stub"]').exists()).toBe(true)
		// CS-PLAN-086 D-086-02: the arcade gate sits next to the catalog gate.
		expect(wrapper.find('[data-testid="arcade-gate-section"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="arcade-gate-stub"]').exists()).toBe(true)
	})

	it('offers up/down reorder buttons per floor as owner', async () => {
		const wrapper = mount(Settings, {
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
		const wrapper = mount(Settings, {
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

		const wrapper = mount(Settings, {
			global: {
				plugins: [pinia],
			},
		})
		await flushPromises()

		await wrapper.find('[data-testid="move-floor-down-1"]').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalled()
	})

	// --- Per-floor room CRUD in Settings (CS-PLAN-042, UX-004) ---

	it('expands a floor to reveal its rooms and the inline add form', async () => {
		const wrapper = mount(Settings, {
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

	it('creates a room on the expanded floor via the store', async () => {
		mockRoomsState.createRoom.mockResolvedValue({ id: 9, name: 'Pantry', floorId: 1 })
		mockRoomsState.roomsByFloor = { ...mockRoomsState.roomsByFloor }

		const wrapper = mount(Settings, {
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

		const wrapper = mount(Settings, { global: { plugins: [pinia] } })
		await flushPromises()
		await wrapper.find('[data-testid="expand-floor-1"]').trigger('click')
		await wrapper.find('[data-testid="new-room-name-input"]').setValue('Pantry')
		await wrapper.find('[data-testid="add-room-to-floor-button"]').trigger('click')
		await flushPromises()

		// The spec's t() mock returns the source string without interpolation.
		expect(showSuccess).toHaveBeenCalledWith('Name already taken — room created as "{name}"')
	})

	it('deletes a room via the styled confirm dialog', async () => {
		const wrapper = mount(Settings, {
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

	// ------------------------------------------------------------------
	// Notifications section (CS-PLAN-070 WP6 §3.3): delegated to the
	// dedicated self-only component — visible to every member, owner or
	// not. The form contract lives in
	// components/Settings/NotificationPreferences.spec.js.
	// ------------------------------------------------------------------

	it('renders the notifications delegation for every member (not only owners)', async () => {
		mockUserState.isHouseOwner = false
		const wrapper = mount(Settings, { global: { plugins: [pinia] } })
		await flushPromises()

		expect(wrapper.find('[data-testid="notifications-section"]').exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'NotificationPreferences' }).exists()).toBe(true)
	})

	it('renders the notifications delegation for owners too', async () => {
		const wrapper = mount(Settings, { global: { plugins: [pinia] } })
		await flushPromises()

		expect(wrapper.find('[data-testid="notifications-section"]').exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'NotificationPreferences' }).exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'NcNoteCard' }).exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).exists()).toBe(true)
	})

	// --- Pauses (CS-PLAN-070 WP6 §3.4, Q-077-03 A) ---

	it('renders the self-only pause section for every member', async () => {
		mockUserState.isHouseOwner = false
		const wrapper = mount(Settings, { global: { plugins: [pinia] } })
		await flushPromises()

		expect(wrapper.find('[data-testid="pause-section"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="pause-settings"]').exists()).toBe(true)
		// Owner administration stays in the owner House section.
		expect(wrapper.find('[data-testid="house-pause-section"]').exists()).toBe(false)
	})

	it('offers the owner the house pause, request decisions and illness recognition', async () => {
		const pendingRequest = {
			pauseGroupId: 5,
			revision: 1,
			scope: 'personal',
			subjectUserId: 'teen',
			state: 'requested',
			startDate: '2026-10-01',
			endDate: '2026-10-03',
			reasonCode: 'illness',
			isRetrospectiveIllness: false,
		}
		mockPausesState.pauses = [pendingRequest]
		mockPausesState.pendingRequests = [pendingRequest]
		mockUserState.houseUsers = [{ nextcloudUserId: 'teen', displayName: 'Teen' }]
		const wrapper = mount(Settings, { global: { plugins: [pinia] } })
		await flushPromises()

		expect(wrapper.find('[data-testid="house-pause-section"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="illness-recognition"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="pending-pause-requests"]').exists()).toBe(true)

		// Approve sends the displayed expectedRevision.
		await wrapper.find('[data-testid="pause-approve-5"]').trigger('click')
		await flushPromises()
		expect(mockPausesState.approvePause).toHaveBeenCalledWith(5, 1)

		// The house pause form sends the whitelisted fields.
		await wrapper.find('[data-testid="house-pause-start-input"]').setValue('2026-12-01')
		await wrapper.find('[data-testid="house-pause-end-input"]').setValue('2026-12-05')
		await wrapper.find('[data-testid="house-pause-reason-select"]').setValue('travel')
		await wrapper.find('[data-testid="house-pause-submit"]').trigger('click')
		await flushPromises()
		expect(mockPausesState.createHousePause).toHaveBeenCalledWith({
			startDate: '2026-12-01',
			endDate: '2026-12-05',
			reasonCode: 'travel',
		})
	})
})
