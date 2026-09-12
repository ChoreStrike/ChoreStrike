import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import App from '../../../src/App.vue'
import { useUserStore } from '../../../src/store/user.js'

/**
 * App root component
 *
 * Regression guard for CS-PLAN-015 Phase 1a: the navigation entries must each
 * carry a stable `data-testid` so E2E specs can target them with `getByTestId`
 * (and `getByRole('link', { name })` as a fallback). Historically a stale
 * `text=Tasks` selector was used because the testids were believed not to
 * survive the NcAppNavigationItem render — this test pins them in place.
 *
 * CS-PLAN-061 §3.9: while the user store's membership state is 'denied'
 * (403/FORBIDDEN from user#current), the whole app is replaced by the
 * NotAuthorizedState gate.
 */
describe('App', () => {
	const mountApp = () => {
		const pinia = createPinia()
		setActivePinia(pinia)
		return mount(App, {
			global: {
				plugins: [pinia],
				stubs: {
					// vue-router is mocked globally (useRouter/useRoute only); stub
					// the view + link components so App mounts without a router.
					'router-view': true,
					'router-link': true,
				},
			},
		})
	}

	describe('Navigation testids', () => {
		it('renders a data-testid on every navigation entry', () => {
			const html = mountApp().html()

			expect(html).toContain('data-testid="nav-dashboard"')
			expect(html).toContain('data-testid="nav-today"')
			expect(html).toContain('data-testid="nav-tasks"')
			expect(html).toContain('data-testid="nav-leaderboard"')
			expect(html).toContain('data-testid="nav-insights"')
			expect(html).toContain('data-testid="nav-scheduling"')
			expect(html).toContain('data-testid="nav-calendar"')
			expect(html).toContain('data-testid="nav-settings"')
		})

		it('renders all eight navigation entries', () => {
			const navItems = mountApp().findAllComponents({ name: 'NcAppNavigationItem' })

			expect(navItems).toHaveLength(8)
		})
	})

	describe('Membership denied gate (CS-PLAN-061 §3.9)', () => {
		it('renders the normal app while membership is unknown or member', () => {
			const wrapper = mountApp()
			const store = useUserStore()

			expect(store.membership).toBe('unknown')
			expect(wrapper.find('[data-testid="not-authorized-state"]').exists()).toBe(false)
			expect(wrapper.find('[data-testid="nav-dashboard"]').exists()).toBe(true)

			store.membership = 'member'
			expect(wrapper.find('[data-testid="not-authorized-state"]').exists()).toBe(false)
		})

		it('replaces the whole app with NotAuthorizedState when membership is denied', async () => {
			const wrapper = mountApp()
			const store = useUserStore()

			store.membership = 'denied'
			await wrapper.vm.$nextTick()

			expect(wrapper.find('[data-testid="not-authorized-state"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="nav-dashboard"]').exists()).toBe(false)
			expect(wrapper.find('[data-testid="not-authorized-retry"]').exists()).toBe(true)
		})

		it('retry resets the store and re-initializes the current user', async () => {
			const wrapper = mountApp()
			const store = useUserStore()
			store.membership = 'denied'
			const initSpy = jest.spyOn(store, 'initializeCurrentUser').mockResolvedValue(null)
			await wrapper.vm.$nextTick()

			await wrapper.find('[data-testid="not-authorized-retry"]').trigger('click')

			expect(store.membership).toBe('unknown')
			expect(store.currentUser).toBeNull()
			expect(initSpy).toHaveBeenCalledTimes(1)
		})
	})

	// CS-PLAN-066 (D4): after membership resolves, only the initial
	// unqualified root visit of a child redirects to My Day.
	describe('Child landing redirect (CS-PLAN-066 D4)', () => {
		const mountWithRouter = (route, setupStore) => {
			const pinia = createPinia()
			setActivePinia(pinia)
			const store = useUserStore()
			if (setupStore) {
				setupStore(store)
			}
			const routerReplace = jest.fn()
			const wrapper = mount(App, {
				global: {
					plugins: [pinia],
					mocks: {
						$route: route,
						$router: { replace: routerReplace },
					},
					stubs: {
						'router-view': true,
						'router-link': true,
					},
				},
			})
			return { wrapper, routerReplace }
		}

		it('redirects the initial bare root visit of a resolved child to My Day', async () => {
			const { wrapper, routerReplace } = mountWithRouter(
				{ path: '/', name: 'dashboard', query: {} },
				store => {
					store.currentUser = { nextcloudUserId: 'teen', role: 'child' }
					store.membership = 'member'
				},
			)
			await wrapper.vm.$nextTick()

			expect(routerReplace).toHaveBeenCalledWith({ name: 'today' })
		})

		it('keeps owner and member on the Dashboard', async () => {
			const { wrapper, routerReplace } = mountWithRouter(
				{ path: '/', name: 'dashboard', query: {} },
				store => {
					store.currentUser = { nextcloudUserId: 'mom', role: 'owner' }
					store.membership = 'member'
				},
			)
			await wrapper.vm.$nextTick()

			expect(routerReplace).not.toHaveBeenCalled()
		})

		it('keeps explicit deep links usable for children', async () => {
			const { wrapper, routerReplace } = mountWithRouter(
				{ path: '/tasks', name: 'tasks', query: {} },
				store => {
					store.currentUser = { nextcloudUserId: 'teen', role: 'child' }
					store.membership = 'member'
				},
			)
			await wrapper.vm.$nextTick()

			expect(routerReplace).not.toHaveBeenCalled()
		})

		it('applies the landing decision at most once (later Dashboard visits stay)', async () => {
			const { wrapper, routerReplace } = mountWithRouter(
				{ path: '/', name: 'dashboard', query: {} },
				store => {
					store.currentUser = { nextcloudUserId: 'teen', role: 'child' }
					store.membership = 'member'
				},
			)
			await wrapper.vm.$nextTick()

			// A second membership reactivity tick must not re-trigger.
			const store = useUserStore()
			store.membership = 'unknown'
			await wrapper.vm.$nextTick()
			store.membership = 'member'
			await wrapper.vm.$nextTick()

			expect(routerReplace).toHaveBeenCalledTimes(1)
		})
	})
})
