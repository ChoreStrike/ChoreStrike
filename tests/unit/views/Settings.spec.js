/* eslint-env jest */
import { mount, flushPromises } from '@vue/test-utils'
import Settings from '../../../src/views/Settings.vue'

/**
 * The Settings page (FEAT-UX-054, CS-PLAN-090 WP-D): a switch *Personal |
 * Household* and collapsible categories that start closed. Each category is
 * a component with a spec of its own (HouseSettings, StructureSettings,
 * DangerZoneSettings, PlanningSettings, NotificationPreferences, …); this
 * spec pins the page: which categories a role sees, that a category mounts
 * only when it opens, and the links into it.
 */

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

let mockRoute = { query: {} }
const mockRouter = { replace: jest.fn().mockResolvedValue() }
jest.mock('vue-router', () => ({
	useRoute: () => mockRoute,
	useRouter: () => mockRouter,
}))

jest.mock('@/utils/logger', () => ({
	logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() },
}))

const mountSettings = () => mount(Settings, {
	global: { stubs: { RouterLink: { props: ['to'], template: '<a :data-to="to.name"><slot /></a>' } } },
})

const mockUserState = { isHouseOwner: true }
jest.mock('../../../src/store/user.js', () => ({
	useUserStore: () => mockUserState,
}))

const mockPreferencesState = { fetchPreferences: jest.fn().mockResolvedValue() }
jest.mock('../../../src/store/preferences.js', () => ({
	usePreferencesStore: () => mockPreferencesState,
}))

jest.mock('../../../src/components/Common/Icon.vue', () => ({
	name: 'Icon',
	props: ['name', 'size'],
	template: '<span class="icon">{{ name }}</span>',
}))

// Every category body is a stub that records its mount: the page must not
// mount, and so not load, a category that is closed.
const mockMounted = []
// A function declaration: the hoisted mock factories below call it.
function mockCategory(name) {
	return {
		name,
		mounted() {
			mockMounted.push(name)
		},
		template: `<div data-testid="stub-${name}" />`,
	}
}
jest.mock('../../../src/components/Settings/TitleSettings.vue', () => mockCategory('TitleSettings'))
jest.mock('../../../src/components/Settings/NotificationPreferences.vue', () => mockCategory('NotificationPreferences'))
jest.mock('../../../src/components/Settings/PlanningSettings.vue', () => mockCategory('PlanningSettings'))
jest.mock('../../../src/components/Settings/HouseSettings.vue', () => mockCategory('HouseSettings'))
jest.mock('../../../src/components/Settings/StructureSettings.vue', () => mockCategory('StructureSettings'))
jest.mock('../../../src/components/Settings/TaskCategorySettings.vue', () => mockCategory('TaskCategorySettings'))
jest.mock('../../../src/components/Settings/DangerZoneSettings.vue', () => mockCategory('DangerZoneSettings'))
jest.mock('../../../src/components/Members/MemberList.vue', () => mockCategory('MemberList'))
jest.mock('../../../src/components/Rewards/RewardCatalogEditor.vue', () => mockCategory('RewardCatalogEditor'))
jest.mock('../../../src/components/Minigames/ArcadeGateSetting.vue', () => mockCategory('ArcadeGateSetting'))

const PERSONAL = ['title', 'notifications', 'planning']
const HOUSEHOLD = ['house', 'members', 'structure', 'categories', 'rewards', 'arcade', 'danger']
// The section test ids the page had before the split stay.
const SECTION_IDS = ['house-section', 'members-section', 'floors-section', 'task-categories-section', 'catalog-section', 'arcade-gate-section', 'danger-zone']

const toggle = (wrapper, id) => wrapper.get(`[data-testid="settings-category-${id}"]`)
const shown = (wrapper) => wrapper.findAll('[data-testid^="settings-category-"]')
	.map((button) => button.attributes('data-testid').replace('settings-category-', ''))

describe('Settings.vue', () => {
	beforeEach(() => {
		mockRoute = { query: {} }
		mockUserState.isHouseOwner = true
		mockMounted.length = 0
		mockPreferencesState.fetchPreferences.mockClear().mockResolvedValue()
		mockRouter.replace.mockClear().mockResolvedValue()
	})

	it('shows the title, the switch and the personal categories, all closed', () => {
		const wrapper = mountSettings()

		expect(wrapper.find('.settings-header h1').text()).toBe('Settings')
		expect(wrapper.get('[data-testid="settings-tab-personal"]').attributes('aria-pressed')).toBe('true')
		expect(shown(wrapper)).toEqual(PERSONAL)
		for (const id of PERSONAL) {
			expect(toggle(wrapper, id).attributes('aria-expanded')).toBe('false')
		}
		// A closed category mounts nothing, so it reads nothing.
		expect(mockMounted).toEqual([])
	})

	it('mounts a category when it opens, keeps others open beside it, and closes it again', async () => {
		const wrapper = mountSettings()

		await toggle(wrapper, 'notifications').trigger('click')
		expect(toggle(wrapper, 'notifications').attributes('aria-expanded')).toBe('true')
		expect(wrapper.find('[data-testid="stub-NotificationPreferences"]').exists()).toBe(true)
		expect(mockMounted).toEqual(['NotificationPreferences'])

		await toggle(wrapper, 'planning').trigger('click')
		expect(wrapper.find('[data-testid="stub-NotificationPreferences"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="stub-PlanningSettings"]').exists()).toBe(true)

		await toggle(wrapper, 'notifications').trigger('click')
		expect(wrapper.find('[data-testid="stub-NotificationPreferences"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="stub-PlanningSettings"]').exists()).toBe(true)
	})

	it('shows the owner the household categories behind the switch, with the page\'s former section ids', async () => {
		const wrapper = mountSettings()

		await wrapper.get('[data-testid="settings-tab-household"]').trigger('click')

		expect(shown(wrapper)).toEqual(HOUSEHOLD)
		for (const testId of SECTION_IDS) {
			expect(wrapper.find(`[data-testid="${testId}"]`).exists()).toBe(true)
		}
		expect(wrapper.find('[data-testid="notifications-section"]').exists()).toBe(false)
		expect(mockMounted).toEqual([])
	})

	it.each([
		['house', 'HouseSettings'],
		['members', 'MemberList'],
		['structure', 'StructureSettings'],
		['categories', 'TaskCategorySettings'],
		['rewards', 'RewardCatalogEditor'],
		['arcade', 'ArcadeGateSetting'],
		['danger', 'DangerZoneSettings'],
	])('hosts the %s category', async (id, component) => {
		const wrapper = mountSettings()
		await wrapper.get('[data-testid="settings-tab-household"]').trigger('click')

		await toggle(wrapper, id).trigger('click')

		expect(wrapper.find(`[data-testid="stub-${component}"]`).exists()).toBe(true)
		expect(mockMounted).toEqual([component])
	})

	it('shows a member only the personal categories, without a switch (no 403 paths)', () => {
		mockUserState.isHouseOwner = false
		const wrapper = mountSettings()

		expect(wrapper.find('[data-testid="settings-tab-household"]').exists()).toBe(false)
		expect(shown(wrapper)).toEqual(PERSONAL)
		for (const testId of SECTION_IDS) {
			expect(wrapper.find(`[data-testid="${testId}"]`).exists()).toBe(false)
		}
		// The notifications stay with every member, owner or not.
		expect(wrapper.find('[data-testid="notifications-section"]').exists()).toBe(true)
	})

	it('opens the category a link names, in its area', async () => {
		mockRoute = { query: { category: 'structure' } }
		const wrapper = mountSettings()
		await flushPromises()

		expect(wrapper.get('[data-testid="settings-tab-household"]').attributes('aria-pressed')).toBe('true')
		expect(toggle(wrapper, 'structure').attributes('aria-expanded')).toBe('true')
		expect(toggle(wrapper, 'house').attributes('aria-expanded')).toBe('false')
		expect(mockMounted).toEqual(['StructureSettings'])
	})

	it('names the category opened last in the address and drops it when that one closes', async () => {
		const wrapper = mountSettings()

		await toggle(wrapper, 'notifications').trigger('click')
		expect(mockRouter.replace).toHaveBeenLastCalledWith({ query: { category: 'notifications' } })

		// The mocked route does not follow the router.
		mockRoute.query = { category: 'notifications' }
		await toggle(wrapper, 'planning').trigger('click')
		expect(mockRouter.replace).toHaveBeenLastCalledWith({ query: { category: 'planning' } })

		mockRoute.query = { category: 'planning' }
		mockRouter.replace.mockClear()
		await toggle(wrapper, 'notifications').trigger('click')
		expect(mockRouter.replace).not.toHaveBeenCalled()

		await toggle(wrapper, 'planning').trigger('click')
		expect(mockRouter.replace).toHaveBeenLastCalledWith({ query: {} })
	})

	it('opens a personal category by a link and ignores an unknown one', async () => {
		mockRoute = { query: { category: 'planning' } }
		const personal = mountSettings()
		await flushPromises()
		expect(toggle(personal, 'planning').attributes('aria-expanded')).toBe('true')

		mockMounted.length = 0
		mockRoute = { query: { category: 'pauses' } }
		const unknown = mountSettings()
		await flushPromises()
		expect(shown(unknown)).toEqual(PERSONAL)
		expect(mockMounted).toEqual([])
	})

	it('never shows a member a household category, also by a link', async () => {
		mockUserState.isHouseOwner = false
		mockRoute = { query: { category: 'danger' } }
		const wrapper = mountSettings()
		await flushPromises()

		expect(shown(wrapper)).toEqual(PERSONAL)
		expect(mockMounted).toEqual([])
	})

	it('links the own reward requests from the personal settings, for every role', () => {
		for (const isHouseOwner of [true, false]) {
			mockUserState.isHouseOwner = isHouseOwner
			const wrapper = mountSettings()

			expect(wrapper.get('[data-testid="settings-open-requests"]').attributes('data-to')).toBe('reward-requests')
			expect(wrapper.get('[data-testid="settings-open-requests"]').text()).toContain('My Requests')
		}
	})

	it('keeps the preferences store warm for the arcade and feedback consumers', async () => {
		mountSettings()
		await flushPromises()

		expect(mockPreferencesState.fetchPreferences).toHaveBeenCalledTimes(1)
	})

	// Absences have their own view (ADR-020).
	it('holds no pause or absence controls', async () => {
		const wrapper = mountSettings()
		for (const id of PERSONAL) {
			await toggle(wrapper, id).trigger('click')
		}

		for (const testId of ['pause-section', 'pause-settings', 'house-pause-section', 'pending-pause-requests', 'illness-recognition']) {
			expect(wrapper.find(`[data-testid="${testId}"]`).exists()).toBe(false)
		}
	})
})
