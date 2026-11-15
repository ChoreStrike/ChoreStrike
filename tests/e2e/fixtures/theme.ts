/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import type { Page } from '@playwright/test'

import { expect } from '@playwright/test'

/** The Nextcloud themes a spec switches (Nextcloud's theme ids). */
const THEMES = ['light', 'dark', 'light-highcontrast'] as const

/**
 * Enable exactly one Nextcloud theme for the signed-in user through the
 * theming API (`default` = follow the system). Callers restore `default`.
 */
export async function useTheme(page: Page, theme: typeof THEMES[number] | 'default'): Promise<void> {
	for (const other of THEMES) {
		if (other !== theme) {
			await page.request.delete(`/ocs/v2.php/apps/theming/api/v1/theme/${other}`, { headers: { 'OCS-APIRequest': 'true' } })
		}
	}
	if (theme !== 'default') {
		const response = await page.request.put(`/ocs/v2.php/apps/theming/api/v1/theme/${theme}/enable`, { headers: { 'OCS-APIRequest': 'true' } })
		expect(response.status(), await response.text()).toBe(200)
	}
}
