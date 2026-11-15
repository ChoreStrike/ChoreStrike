/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * E2E: colour schemes (CS-PLAN-090 WP-K, FEAT-UX-057, D-090-61/62/67). A
 * member picks a scheme in Settings → Personal → Appearance; it holds after
 * a reload (initial state), colours a dialog teleported to the body, and
 * leaves the Nextcloud header alone. Under a high-contrast Nextcloud theme
 * the app keeps the standard colours. Contrast per scheme and theme is
 * measured by `color-contrast.spec.ts`.
 *
 * Requires: Docker test environment with the `family` database profile.
 */

import { createUserContext } from './fixtures/auth-contexts.ts'
import { accentOf, setColorScheme } from './fixtures/color-scheme.ts'
import { expect, test } from './fixtures/database-fixtures.ts'
import { openSettings } from './fixtures/settings.ts'
import { useTheme } from './fixtures/theme.ts'

// No other spec of the parallel pool signs in as this member.
const USER = 'grandma'
// Forest's accent in the light theme (src/styles/schemes.css).
const FOREST_LIGHT = '#206d2f'

test.describe('Colour schemes (CS-PLAN-090 WP-K)', () => {
	test.use({ databaseProfile: 'family' })
	// One member's theme and scheme are switched per test: never in parallel.
	test.describe.configure({ mode: 'serial' })

	test('a scheme chosen in Settings holds after a reload and colours an open dialog, not the header', async ({ browser }) => {
		test.setTimeout(120000)
		const { context, page } = await createUserContext(browser, USER)
		try {
			await useTheme(page, 'light')
			await setColorScheme(page, 'standard')
			await openSettings(page, 'appearance')
			const instance = await accentOf(page, '#header')
			expect(await accentOf(page, '#app-chorestrike-vue')).toBe(instance)
			expect(instance).not.toBe(FOREST_LIGHT)

			const saved = page.waitForResponse((response) => response.url().includes('/preferences/me/color-scheme')
				&& response.request().method() === 'PUT')
			await page.getByTestId('color-scheme-forest').click()
			expect((await saved).status()).toBe(200)
			await expect(page.locator('body')).toHaveAttribute('data-cs-scheme', 'forest')
			expect(await accentOf(page, '#app-chorestrike-vue')).toBe(FOREST_LIGHT)
			expect(await accentOf(page, '#header')).toBe(instance)

			await page.reload()
			await expect(page.getByTestId('settings-view')).toBeVisible({ timeout: 30000 })
			await expect(page.locator('body')).toHaveAttribute('data-cs-scheme', 'forest')
			await expect(page.getByTestId('color-scheme-forest').locator('input')).toBeChecked()
			expect(await accentOf(page, '#app-chorestrike-vue')).toBe(FOREST_LIGHT)

			// The help dialog is teleported to the body, outside the app content.
			await page.getByTestId('nav-help').click()
			await expect(page.getByTestId('help-modal')).toBeVisible()
			expect(await accentOf(page, '[data-testid="help-modal"]')).toBe(FOREST_LIGHT)
		} finally {
			await setColorScheme(page, 'standard').catch(() => {})
			await useTheme(page, 'default').catch(() => {})
			await context.close()
		}
	})

	test('a high-contrast Nextcloud theme keeps the standard colours', async ({ browser }) => {
		test.setTimeout(120000)
		const { context, page } = await createUserContext(browser, USER)
		try {
			await useTheme(page, 'light-highcontrast')
			await setColorScheme(page, 'berry')
			await openSettings(page, 'appearance')

			await expect(page.locator('body')).toHaveAttribute('data-theme-light-highcontrast', '')
			await expect(page.getByTestId('color-scheme-high-contrast')).toBeVisible()
			await expect(page.locator('body')).not.toHaveAttribute('data-cs-scheme', /.*/)
			expect(await accentOf(page, '#app-chorestrike-vue')).toBe(await accentOf(page, '#header'))
			// The choice stays stored for when the theme changes back.
			await expect(page.getByTestId('color-scheme-berry').locator('input')).toBeChecked()
		} finally {
			await setColorScheme(page, 'standard').catch(() => {})
			await useTheme(page, 'default').catch(() => {})
			await context.close()
		}
	})
})
