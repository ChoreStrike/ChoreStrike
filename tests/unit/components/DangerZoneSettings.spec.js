/* eslint-env jest */
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { translate } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import DangerZoneSettings from '../../../src/components/Settings/DangerZoneSettings.vue'

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
// The danger zone of the household settings (moved out of Settings.spec.js
// with the split per category, CS-PLAN-090 WP-D).
describe('DangerZoneSettings', () => {
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
	it('has reset house button', () => {
		const wrapper = mount(DangerZoneSettings, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.danger-button').exists()).toBe(true)
	})

	it('opens reset confirmation modal when reset clicked', async () => {
		const wrapper = mount(DangerZoneSettings, {
			global: {
				plugins: [pinia],
			},
		})

		await wrapper.find('.danger-button').trigger('click')
		expect(wrapper.vm.showResetModal).toBe(true)
	})

	it('submits the confirmed reset through the receipt-aware house store', async () => {
		const wrapper = mount(DangerZoneSettings, { global: { plugins: [pinia] } })
		await flushPromises()

		await wrapper.find('.danger-button').trigger('click')
		await wrapper.find('input[placeholder="RESET"]').setValue('RESET')
		await wrapper.find('.modal-overlay .danger-button').trigger('click')
		await flushPromises()

		expect(mockHouseState.resetHouse).toHaveBeenCalledTimes(1)
		expect(showSuccess).toHaveBeenCalled()
		// The reset changed the rooms and the house: both are read again.
		expect(mockHouseState.fetchHouse).toHaveBeenCalledTimes(1)
		expect(mockRoomsState.fetchFloorsWithRooms).toHaveBeenCalledTimes(1)
	})

	it('never reports a reset that went through as failed when only the read afterwards fails', async () => {
		mockHouseState.fetchHouse.mockRejectedValueOnce(new Error('network'))
		const wrapper = mount(DangerZoneSettings, { global: { plugins: [pinia] } })
		await flushPromises()

		await wrapper.find('.danger-button').trigger('click')
		await wrapper.find('input[placeholder="RESET"]').setValue('RESET')
		await wrapper.find('.modal-overlay .danger-button').trigger('click')
		await flushPromises()

		expect(mockHouseState.resetHouse).toHaveBeenCalledTimes(1)
		expect(showSuccess).toHaveBeenCalledTimes(1)
		expect(showError).not.toHaveBeenCalled()
		expect(mockRoomsState.fetchFloorsWithRooms).toHaveBeenCalledTimes(1)
		expect(wrapper.find('.modal-overlay').exists()).toBe(false)
	})

	it('reports a refused reset and keeps the dialog open', async () => {
		mockHouseState.resetHouse.mockRejectedValueOnce(new Error('refused'))
		const wrapper = mount(DangerZoneSettings, { global: { plugins: [pinia] } })
		await flushPromises()

		await wrapper.find('.danger-button').trigger('click')
		await wrapper.find('input[placeholder="RESET"]').setValue('RESET')
		await wrapper.find('.modal-overlay .danger-button').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledTimes(1)
		expect(showSuccess).not.toHaveBeenCalled()
		expect(mockHouseState.fetchHouse).not.toHaveBeenCalled()
		expect(wrapper.find('.modal-overlay').exists()).toBe(true)
	})

	it('describes the reset as room/house-health only and names the retained facts (CS-PLAN-081 H4)', () => {
		const wrapper = mount(DangerZoneSettings, { global: { plugins: [pinia] } })

		// The danger-zone card must not promise a whole-house wipe: only
		// rooms and house health reset; setup/planning/history/finances/
		// rewards/achievements survive the unchanged backend contract.
		const cardText = wrapper.find('.danger-item').text()
		expect(cardText).toContain('house health')
		expect(cardText).toContain('achievements are kept')
		expect(cardText).not.toContain('start fresh')
	})

	it('reset confirmation explains the retained setup, tasks and history and cancel does nothing (CS-PLAN-081 H4)', async () => {
		const wrapper = mount(DangerZoneSettings, { global: { plugins: [pinia] } })

		await wrapper.find('.danger-button').trigger('click')
		expect(translate).toHaveBeenCalledWith('chorestrike', 'RESET')
		const modalText = wrapper.find('.modal-overlay').text()
		expect(modalText).toContain('house health is restored')
		expect(modalText).toContain('completion history')
		expect(modalText).not.toContain('delete all floors')

		await wrapper.find('.modal-overlay .secondary-button').trigger('click')
		expect(wrapper.vm.showResetModal).toBe(false)
		expect(mockHouseState.resetHouse).not.toHaveBeenCalled()
	})
})
