import { mount } from '@vue/test-utils'
import App from '../../../src/App.vue'

/**
 * App root component
 *
 * Regression guard for CS-PLAN-015 Phase 1a: the navigation entries must each
 * carry a stable `data-testid` so E2E specs can target them with `getByTestId`
 * (and `getByRole('link', { name })` as a fallback). Historically a stale
 * `text=Tasks` selector was used because the testids were believed not to
 * survive the NcAppNavigationItem render — this test pins them in place.
 */
describe('App', () => {
	const mountApp = () => mount(App, {
		global: {
			stubs: {
				// vue-router is mocked globally (useRouter/useRoute only); stub
				// the view + link components so App mounts without a router.
				'router-view': true,
				'router-link': true,
			},
		},
	})

	describe('Navigation testids', () => {
		it('renders a data-testid on every navigation entry', () => {
			const html = mountApp().html()

			expect(html).toContain('data-testid="nav-dashboard"')
			expect(html).toContain('data-testid="nav-tasks"')
			expect(html).toContain('data-testid="nav-leaderboard"')
			expect(html).toContain('data-testid="nav-scheduling"')
			expect(html).toContain('data-testid="nav-settings"')
		})

		it('renders all five navigation entries', () => {
			const navItems = mountApp().findAllComponents({ name: 'NcAppNavigationItem' })

			expect(navItems).toHaveLength(5)
		})
	})
})
