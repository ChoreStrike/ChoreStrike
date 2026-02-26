import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import HouseHealthBar from '../../../src/components/Dashboard/HouseHealthBar.vue'

describe('HouseHealthBar', () => {
	const createWrapper = (props = {}, storeHealth = 75, storeName = 'Test House') => {
		const pinia = createPinia()
		setActivePinia(pinia)
		
		return mount(HouseHealthBar, {
			props: {
				showRefreshButton: true,
				compact: false,
				...props,
			},
			global: {
				plugins: [pinia],
				mocks: {
					// Mock store values
					$store: {
						house: {
							houseHealth: storeHealth,
							houseName: storeName,
							loading: false,
						}
					}
				},
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
			const pinia = createPinia()
			setActivePinia(pinia)
			
			const wrapper = mount(HouseHealthBar, {
				props: { showRefreshButton: true },
				global: {
					plugins: [pinia],
					mocks: {
						$store: {
							house: {
								houseHealth: 80,
								houseName: 'Test',
								loading: true,
							}
						}
					},
				},
			})
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
	})
})
