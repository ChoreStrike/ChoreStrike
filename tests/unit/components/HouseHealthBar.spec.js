import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import HouseHealthBar from '../../../src/components/Dashboard/HouseHealthBar.vue'
import Icon from '../../../src/components/Common/Icon.vue'
import { useHouseStore } from '../../../src/store/house.js'

describe('HouseHealthBar', () => {
	let store
	let pinia

	beforeEach(() => {
		// Create a fresh pinia instance for each test
		pinia = createPinia()
		setActivePinia(pinia)
		store = useHouseStore()
	})

	const createWrapper = (props = {}, storeHealth = 75, storeName = 'Test House', loading = false) => {
		// Set up store state before mounting
		store.house = {
			houseHealth: storeHealth,
			name: storeName,
		}
		store.loading = loading

		return mount(HouseHealthBar, {
			props: {
				showRefreshButton: true,
				compact: false,
				...props,
			},
			global: {
				plugins: [pinia],
			},
		})
	}

	describe('rendering', () => {
		it('renders component correctly', () => {
			const wrapper = createWrapper()
			expect(wrapper.exists()).toBe(true)
		})

		it('displays house name', () => {
			const wrapper = createWrapper({}, 75, 'My Test House')
			expect(wrapper.text()).toContain('My Test House')
		})

		it('shows health percentage', () => {
			const wrapper = createWrapper({}, 85)
			expect(wrapper.text()).toContain('85%')
		})

		it('has data-testid attribute', () => {
			const wrapper = createWrapper()
			expect(wrapper.attributes('data-testid')).toBe('house-health')
		})
	})

	describe('health status classes', () => {
		it.each([
			[95, 'excellent'],
			[75, 'good'],
			[50, 'fair'],
			[30, 'poor'],
			[10, 'terrible'],
		])('should apply %s class for health %i%%', (health, expectedClass) => {
			const wrapper = createWrapper({}, health)
			const fill = wrapper.find('.health-bar-fill')
			expect(fill.classes()).toContain(expectedClass)
		})

		it('shows correct status text for each health level', () => {
			const testCases = [
				{ health: 90, text: 'Excellent!' },
				{ health: 70, text: 'Good!' },
				{ health: 50, text: 'Fair' },
				{ health: 30, text: 'Needs attention' },
				{ health: 10, text: 'Critical!' },
			]

			testCases.forEach(({ health, text }) => {
				const wrapper = createWrapper({}, health)
				expect(wrapper.text()).toContain(text)
			})
		})
	})

	describe('refresh button', () => {
		it('should show refresh button by default', () => {
			const wrapper = createWrapper()
			expect(wrapper.find('.refresh-button').exists()).toBe(true)
		})

		it('should hide refresh button when showRefreshButton is false', () => {
			const wrapper = createWrapper({ showRefreshButton: false })
			expect(wrapper.find('.refresh-button').exists()).toBe(false)
		})

		it('should be disabled when loading', () => {
			const wrapper = createWrapper({}, 80, 'Test', true)
			expect(wrapper.find('.refresh-button').attributes('disabled')).toBeDefined()
		})
	})

	describe('compact mode', () => {
		it('should apply compact class when compact prop is true', () => {
			const wrapper = createWrapper({ compact: true })
			expect(wrapper.classes()).toContain('compact')
		})

		it('should not have compact class by default', () => {
			const wrapper = createWrapper()
			expect(wrapper.classes()).not.toContain('compact')
		})
	})

	describe('progress bar', () => {
		it('renders progress bar with correct width', () => {
			const wrapper = createWrapper({}, 50)
			const progressBar = wrapper.find('.health-bar-fill')
			expect(progressBar.exists()).toBe(true)
			expect(progressBar.attributes('style')).toContain('width: 50%')
		})

		it('has glow animation effect', () => {
			const wrapper = createWrapper()
			expect(wrapper.find('.health-bar-glow').exists()).toBe(true)
		})

		// Regression: both layers are absolutely positioned, so a track rendered
		// after the fill painted over it and the bar always looked empty.
		it('renders the track before the fill so the fill paints on top', () => {
			const wrapper = createWrapper({}, 100)
			const layers = wrapper.find('.health-bar-container').element.children
			expect(Array.from(layers).map(layer => layer.className.split(' ')[0]))
				.toEqual(['health-bar-background', 'health-bar-fill'])
		})
	})

	describe('icons', () => {
		// Regression: Icon was used without being registered, so production
		// rendered an empty unknown element instead of the house and reload
		// icons. The global Jest stub resolves unregistered usages, so the
		// registration itself is asserted (see also iconRegistration.spec.js).
		it('registers the Icon component it renders', () => {
			expect(HouseHealthBar.components).toEqual(expect.objectContaining({ Icon }))
		})
	})
})
