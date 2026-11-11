import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import SetupWizardEntry from '@/components/Setup/SetupWizardEntry.vue'
import { logger } from '@/utils/logger.js'

jest.mock('@nextcloud/l10n', () => ({ translate: jest.fn((app, text) => text) }))
jest.mock('@/utils/logger.js', () => ({ logger: { error: jest.fn() } }))

const mockWizardState = { dismissedFor: null }

jest.mock('@/components/Setup/SetupWizard.vue', () => ({
	__esModule: true,
	default: {
		name: 'SetupWizard',
		emits: ['applied', 'skipped', 'close'],
		template: '<div class="setup-wizard-stub" />',
	},
	isWizardDismissedForHouse: (houseId) => houseId !== null && mockWizardState.dismissedFor === houseId,
}))

let mockHouseStore
let mockRoomsStore
let mockUserStore

jest.mock('@/store/house.js', () => ({ useHouseStore: () => mockHouseStore }))
jest.mock('@/store/rooms.js', () => ({ useRoomsStore: () => mockRoomsStore }))
jest.mock('@/store/user.js', () => ({ useUserStore: () => mockUserStore }))

describe('SetupWizardEntry', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		mockWizardState.dismissedFor = null
		mockHouseStore = reactive({ house: { id: 4 }, fetchHouse: jest.fn().mockResolvedValue({ id: 4 }) })
		mockRoomsStore = reactive({ floors: [], fetchFloorsWithRooms: jest.fn().mockResolvedValue([]) })
		mockUserStore = reactive({ isHouseOwner: true })
	})

	const mountEntry = async (props = {}) => {
		const wrapper = mount(SetupWizardEntry, { props: { ready: true, ...props } })
		await flushPromises()
		return wrapper
	}
	const button = (wrapper) => wrapper.find('[data-testid="open-setup-wizard"]')
	const wizard = (wrapper) => wrapper.findComponent({ name: 'SetupWizard' })

	it('offers the wizard to the owner of a house without floors, closed until asked', async () => {
		const wrapper = await mountEntry()

		expect(button(wrapper).text()).toBe('Set up your home')
		expect(wizard(wrapper).exists()).toBe(false)

		await button(wrapper).trigger('click')

		expect(wizard(wrapper).exists()).toBe(true)
	})

	it('renders the wizard at <body>, above Nextcloud\'s chrome (CS-PLAN-087 RF-087-09)', async () => {
		const wrapper = await mountEntry({ autoOpen: true })

		const teleport = wrapper.find('teleport-stub')
		expect(teleport.attributes('to')).toBe('body')
		expect(teleport.find('.setup-wizard-stub').exists()).toBe(true)
	})

	it.each([
		['a member who is not the owner', () => { mockUserStore.isHouseOwner = false }],
		['a house that has floors', () => { mockRoomsStore.floors = [{ id: 1 }] }],
	])('renders nothing for %s (CS-PLAN-061 §3.9)', async (label, arrange) => {
		arrange()
		const wrapper = await mountEntry({ autoOpen: true })

		expect(wrapper.find('.setup-entry').exists()).toBe(false)
		expect(wizard(wrapper).exists()).toBe(false)
	})

	it('waits for the floors read: an empty store is no empty house', async () => {
		const wrapper = await mountEntry({ ready: false, autoOpen: true })
		expect(button(wrapper).exists()).toBe(false)
		expect(wizard(wrapper).exists()).toBe(false)

		await wrapper.setProps({ ready: true })
		await flushPromises()

		expect(button(wrapper).exists()).toBe(true)
		expect(wizard(wrapper).exists()).toBe(true)
	})

	it('does not open by itself after "Start empty", and keeps the button (UX-011)', async () => {
		mockWizardState.dismissedFor = 4
		const wrapper = await mountEntry({ autoOpen: true })

		expect(wizard(wrapper).exists()).toBe(false)
		expect(button(wrapper).exists()).toBe(true)
	})

	it('reads the house first when it is unknown, so the dismissal is found', async () => {
		mockWizardState.dismissedFor = 4
		mockHouseStore.house = null
		mockHouseStore.fetchHouse.mockImplementation(async () => {
			mockHouseStore.house = { id: 4 }
		})
		const wrapper = await mountEntry({ autoOpen: true })

		expect(mockHouseStore.fetchHouse).toHaveBeenCalledTimes(1)
		expect(wizard(wrapper).exists()).toBe(false)
	})

	it('opens by itself when the house read fails: nothing says it was dismissed', async () => {
		mockHouseStore.house = null
		mockHouseStore.fetchHouse.mockRejectedValue(new Error('offline'))
		const wrapper = await mountEntry({ autoOpen: true })

		expect(wizard(wrapper).exists()).toBe(true)
	})

	it.each(['skipped', 'close'])('closes on "%s" and does not reopen by itself', async (event) => {
		const wrapper = await mountEntry({ autoOpen: true })

		wizard(wrapper).vm.$emit(event)
		await flushPromises()

		expect(wizard(wrapper).exists()).toBe(false)
		expect(button(wrapper).exists()).toBe(true)
		expect(wrapper.emitted('applied')).toBeUndefined()
	})

	it('reads floors and house again after the wizard applied, then tells the caller', async () => {
		mockRoomsStore.fetchFloorsWithRooms.mockImplementation(async () => {
			mockRoomsStore.floors = [{ id: 1 }]
		})
		const wrapper = await mountEntry({ autoOpen: true })

		wizard(wrapper).vm.$emit('applied')
		await flushPromises()

		expect(mockRoomsStore.fetchFloorsWithRooms).toHaveBeenCalledTimes(1)
		expect(mockHouseStore.fetchHouse).toHaveBeenCalledTimes(1)
		expect(wrapper.emitted('applied')).toHaveLength(1)
		expect(wrapper.find('.setup-entry').exists()).toBe(false)
	})

	it('still tells the caller when a reload after the wizard fails, and logs it', async () => {
		const failure = new Error('offline')
		mockRoomsStore.fetchFloorsWithRooms.mockRejectedValue(failure)
		const wrapper = await mountEntry({ autoOpen: true })

		wizard(wrapper).vm.$emit('applied')
		await flushPromises()

		expect(logger.error).toHaveBeenCalledWith('Reload after the setup wizard failed', failure, { component: 'SetupWizardEntry', action: 'handleApplied' })
		expect(wrapper.emitted('applied')).toHaveLength(1)
	})
})
