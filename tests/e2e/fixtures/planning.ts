import type { Page } from '@playwright/test'

import { expect } from '@playwright/test'
import { openSettings } from './settings.ts'

/**
 * Where planning lives since CS-PLAN-090 WP-D: the weekly plan left the
 * navigation and opens from the calendar header (D-090-14); the own days
 * and the own energy are a category of the personal settings.
 */

/**
 * Open the weekly plan from the calendar and wait for its content.
 *
 * @param page - Page of a signed-in household member, inside the app.
 */
export async function openWeeklyPlan(page: Page): Promise<void> {
	await page.getByTestId('nav-calendar').click()
	await page.getByTestId('calendar-open-plan').click()
	await page.getByTestId('scheduling-view').waitFor({ state: 'visible', timeout: 10000 })
	await page.locator('.scheduling-content').waitFor({ state: 'visible', timeout: 10000 })
}

/**
 * Open the planning category of the personal settings (own days and own
 * energy) and wait for both forms.
 *
 * @param page - Page of a signed-in household member.
 */
export async function openPlanningSettings(page: Page): Promise<void> {
	await openSettings(page, 'planning')
	await expect(page.getByTestId('self-availability-settings')).toBeVisible({ timeout: 30000 })
	await expect(page.getByTestId('energy-budget-settings')).toBeVisible()
}
