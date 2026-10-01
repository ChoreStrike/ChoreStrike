import { test, expect, DatabaseProfile } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'

/**
 * E2E test: the calm core UI (CS-PLAN-070 WP6) — serial family pool.
 *
 * Replaces the retired Dashboard-landing assumptions with the WP6
 * contract: every role lands on My Day (route-level redirect), the
 * Dashboard stays reachable as the secondary household overview, My Day
 * stays free of Coins/arcade/catalog surfaces, the optional arcade hides
 * together with its navigation entry while `arcadeVisible` is off, and
 * the pause lifecycle (request → owner decision → early end) stays
 * neutral through the real UI.
 */

test.describe('Calm core UI (CS-PLAN-070 WP6)', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })
	test.setTimeout(120000)

	test('every role lands on My Day and the Dashboard stays reachable as the secondary overview', async ({ browser, seededData: _seededData }) => {
		const owner = await createUserContext(browser, 'mom')
		const child = await createUserContext(browser, 'teen')
		try {
			// Owner bare root → My Day (the retired child-only special case
			// is now the universal landing, Q-077-01 Option A).
			await owner.page.goto('/apps/chorestrike/')
			await expect(owner.page.getByTestId('today-view')).toBeVisible({ timeout: 30000 })
			await expect(owner.page.getByTestId('dashboard-view')).toHaveCount(0)

			// Child bare root → My Day as well.
			await child.page.goto('/apps/chorestrike/')
			await expect(child.page.getByTestId('today-view')).toBeVisible({ timeout: 30000 })
			await expect(child.page.getByTestId('dashboard-view')).toHaveCount(0)

			// The Dashboard remains reachable on its explicit secondary path.
			await owner.page.goto('/apps/chorestrike/#/dashboard')
			await expect(owner.page.getByTestId('dashboard-view')).toBeVisible({ timeout: 30000 })
			// The retired military tagline is gone (C5f).
			await expect(owner.page.getByTestId('dashboard-view')).not.toContainText('Defend your Home Base')
			await child.page.goto('/apps/chorestrike/#/dashboard')
			await expect(child.page.getByTestId('dashboard-view')).toBeVisible({ timeout: 30000 })
		} finally {
			await owner.context.close()
			await child.context.close()
		}
	})

	test('My Day stays Coin-free while the wallet stays in the secondary overview', async ({ browser, seededData: _seededData }) => {
		const owner = await createUserContext(browser, 'mom')
		try {
			await owner.page.goto('/apps/chorestrike/#/today')
			await expect(owner.page.getByTestId('today-view')).toBeVisible({ timeout: 30000 })

			// No Coins, no arcade/catalog surfaces on the primary landing.
			await expect(owner.page.getByTestId('today-view')).not.toContainText('Coins')
			await expect(owner.page.getByTestId('own-wallet-available')).toHaveCount(0)

			// The wallet stays reachable outside My Day (C5f/D10a).
			await owner.page.goto('/apps/chorestrike/#/dashboard')
			await expect(owner.page.getByTestId('own-wallet-available')).toBeVisible({ timeout: 30000 })
		} finally {
			await owner.context.close()
		}
	})

	test('the optional arcade hides with its navigation entry when arcadeVisible is off', async ({ browser, seededData: _seededData }) => {
		const owner = await createUserContext(browser, 'mom')
		try {
			await owner.page.goto('/apps/chorestrike/#/today')
			await expect(owner.page.getByTestId('today-view')).toBeVisible({ timeout: 30000 })

			// Default (opt-in on): the navigation entry exists.
			await expect(owner.page.getByTestId('nav-arcade')).toBeVisible({ timeout: 15000 })

			// Opt out through the settings surface (Q-077-05). The switch
			// input is visually hidden behind its content span — click the
			// visible switch label (the realistic user interaction; mom is
			// pinned to English by the family-core-ux language test).
			await owner.page.goto('/apps/chorestrike/#/settings')
			await expect(owner.page.getByTestId('notification-preferences')).toBeVisible({ timeout: 30000 })
			const arcadeSwitch = owner.page.getByTestId('arcade-visible-switch')
			await expect(arcadeSwitch).toBeChecked()
			await owner.page.getByText('Show the arcade', { exact: true }).click()
			await expect(arcadeSwitch).not.toBeChecked()
			await owner.page.getByTestId('save-preferences').click()

			// Consumed immediately: the navigation entry disappears without
			// a reload.
			await expect(owner.page.getByTestId('nav-arcade')).toHaveCount(0, { timeout: 15000 })

			// A direct link shows the neutral placeholder, never the arcade.
			await owner.page.goto('/apps/chorestrike/#/arcade')
			await owner.page.reload()
			await expect(owner.page.getByTestId('arcade-hidden')).toBeVisible({ timeout: 30000 })
			await expect(owner.page.getByTestId('arcade-surface')).toHaveCount(0)

			// Restore the opt-in for the other gate pools (shared DB).
			await owner.page.goto('/apps/chorestrike/#/settings')
			await expect(owner.page.getByTestId('notification-preferences')).toBeVisible({ timeout: 30000 })
			const arcadeSwitchRestore = owner.page.getByTestId('arcade-visible-switch')
			await expect(arcadeSwitchRestore).not.toBeChecked()
			await owner.page.getByText('Show the arcade', { exact: true }).click()
			await expect(arcadeSwitchRestore).toBeChecked()
			await owner.page.getByTestId('save-preferences').click()
			await owner.page.goto('/apps/chorestrike/#/today')
			await expect(owner.page.getByTestId('nav-arcade')).toBeVisible({ timeout: 15000 })
		} finally {
			await owner.context.close()
		}
	})

	test('the pause lifecycle stays neutral through request, owner decision and early end', async ({ browser, seededData: _seededData }) => {
		const child = await createUserContext(browser, 'teen')
		const owner = await createUserContext(browser, 'mom')
		try {
			// The child requests a personal pause through the self-only
			// surface (pending — nothing changes until the owner decides).
			await child.page.goto('/apps/chorestrike/#/settings')
			const pauseSurface = child.page.getByTestId('pause-settings')
			await expect(pauseSurface).toBeVisible({ timeout: 30000 })
			await child.page.getByTestId('pause-start-input').fill('2026-11-02')
			await child.page.getByTestId('pause-end-input').fill('2026-11-04')
			await child.page.getByTestId('pause-reason-select').selectOption('illness')
			await child.page.getByTestId('pause-request-submit').click()

			// The child's own list shows the pending state, neutrally.
			await expect(child.page.locator('.pause-state').filter({ hasText: 'Waiting for the house owner' }))
				.toBeVisible({ timeout: 15000 })

			// The owner decides the pending request (expectedRevision rides
			// along); the pending section empties after the decision.
			await owner.page.goto('/apps/chorestrike/#/settings')
			await expect(owner.page.getByTestId('pending-pause-requests')).toBeVisible({ timeout: 30000 })
			await owner.page.locator('[data-testid^="pause-approve-"]').first().click()
			await expect(owner.page.getByTestId('pending-pause-requests')).toHaveCount(0, { timeout: 15000 })

			// The child re-loads: the pause is acknowledged and the early
			// end is offered with the displayed revision.
			await child.page.reload()
			await expect(pauseSurface).toBeVisible({ timeout: 30000 })
			const shortenInput = child.page.locator('[data-testid^="pause-shorten-input-"]').first()
			await expect(shortenInput).toBeVisible({ timeout: 15000 })

			// The early end shortens the pause to the next local day.
			await shortenInput.fill('2026-11-03')
			await child.page.locator('[data-testid^="pause-shorten-submit-"]').first().click()
			await expect(child.page.locator('.pause-entry').filter({ hasText: '2026-11-03' })).toBeVisible({ timeout: 15000 })
		} finally {
			await owner.context.close()
			await child.context.close()
		}
	})
})
