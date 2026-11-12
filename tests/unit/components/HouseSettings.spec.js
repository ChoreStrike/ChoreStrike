/* eslint-env jest */
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { translate } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import HouseSettings from '../../../src/components/Settings/HouseSettings.vue'

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
// House name, timezone and ranking switch of the household settings (moved
// out of Settings.spec.js with the split per category, CS-PLAN-090 WP-D).
describe('HouseSettings', () => {
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
	it('has house name input field', () => {
		const wrapper = mount(HouseSettings, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('#house-name').exists()).toBe(true)
	})

	it('displays current house name in input', async () => {
		const wrapper = mount(HouseSettings, {
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
		const wrapper = mount(HouseSettings, { global: { plugins: [pinia] } })
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

	it('offers retry after a failed house read without enabling fallback writes', async () => {
		mockHouseState.house = null
		mockHouseState.fetchHouse.mockRejectedValueOnce(new Error('Network error'))
		const wrapper = mount(HouseSettings, { global: { plugins: [pinia] } })
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
		const wrapper = mount(HouseSettings, {
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.save-button').exists()).toBe(true)
	})

	it('disables save button when house name is unchanged', () => {
		const wrapper = mount(HouseSettings, {
			global: {
				plugins: [pinia],
			},
		})

		const saveButton = wrapper.find('.save-button')
		expect(saveButton.attributes('disabled')).toBeDefined()
	})

	it('toasts success after saving the house name (CS-PLAN-041)', async () => {
		const wrapper = mount(HouseSettings, {
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

	it('offers the owner the ranking toggle initialized from the house flag', async () => {
		const wrapper = mount(HouseSettings, { global: { plugins: [pinia] } })
		await flushPromises()

		const toggle = wrapper.find('[data-testid="ranking-toggle"]')
		expect(toggle.exists()).toBe(true)
		expect(toggle.element.checked).toBe(false)
		// Unchanged flag keeps the save button disabled.
		expect(wrapper.find('[data-testid="save-house-ranking"]').attributes('disabled')).toBeDefined()
	})

	it('saves the ranking flag through the house store when toggled on', async () => {
		const wrapper = mount(HouseSettings, { global: { plugins: [pinia] } })
		await flushPromises()

		await wrapper.find('[data-testid="ranking-toggle"]').setValue(true)
		await wrapper.find('[data-testid="save-house-ranking"]').trigger('click')
		await flushPromises()

		expect(mockHouseState.updateRankingEnabled).toHaveBeenCalledWith(true)
		expect(showSuccess).toHaveBeenCalled()
	})

	it('re-syncs the ranking checkbox when the save fails', async () => {
		mockHouseState.updateRankingEnabled.mockRejectedValueOnce(new Error('boom'))

		const wrapper = mount(HouseSettings, { global: { plugins: [pinia] } })
		await flushPromises()

		await wrapper.find('[data-testid="ranking-toggle"]').setValue(true)
		await wrapper.find('[data-testid="save-house-ranking"]').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalled()
		expect(wrapper.find('[data-testid="ranking-toggle"]').element.checked).toBe(false)
	})
})
