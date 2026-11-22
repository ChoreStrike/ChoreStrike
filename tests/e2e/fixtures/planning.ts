import type { Page } from '@playwright/test'

import { expect } from '@playwright/test'
import { openSettings } from './settings.ts'

/**
 * Where planning lives since CS-PLAN-091 WP-C: the calendar shows the live
 * suggestion as a week of days; the own days, energy and preferred days are
 * a category of the personal settings, and the owner's view of every
 * member's week is a category of the household settings. The weekly plan's
 * page is gone.
 */

export const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as const

/**
 * The lowercase weekday name of a `YYYY-MM-DD` key, as the preference
 * sections name their days.
 *
 * @param dayKey - House-local day key.
 * @returns The weekday name.
 */
export function weekdayOf(dayKey: string): typeof WEEKDAYS[number] {
	const [year, month, day] = dayKey.split('-').map(Number)
	return WEEKDAYS[(new Date(Date.UTC(year, month - 1, day)).getUTCDay() + 6) % 7]
}

/**
 * Preferences that make the suggestion place a member's work on one day:
 * that day is preferred, and every day has room, so the seed's own work of
 * the weekday cannot fill it first.
 *
 * @param dayKey - The house-local day the work should be suggested on.
 * @returns The body of a preferences write.
 */
export function preferringDay(dayKey: string): { preferredWeekdays: string[], energyBudget: Record<string, number> } {
	return {
		preferredWeekdays: [weekdayOf(dayKey)],
		energyBudget: Object.fromEntries(WEEKDAYS.map((weekday) => [weekday, 20])),
	}
}

/**
 * Call an app route the way the app does: a CSRF-checked fetch with the
 * page's request token. For pages of the project's own storage state, whose
 * context carries no primed token header.
 *
 * @param page - Page of a signed-in member, inside Nextcloud.
 * @param method - HTTP method.
 * @param path - Route below the app's API root, with its query.
 * @param data - JSON body of a write.
 * @returns The status and the parsed body.
 */
export async function appRequest(page: Page, method: 'GET' | 'PUT', path: string, data?: unknown): Promise<{ status: number, body: { data?: unknown, error?: { code?: string } } }> {
	return page.evaluate(async ({ route, verb, payload }) => {
		const response = await fetch(`/apps/chorestrike/api/v1${route}`, {
			method: verb,
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
				requesttoken: (window as unknown as { OC: { requestToken: string } }).OC.requestToken,
			},
			body: payload === undefined ? undefined : JSON.stringify(payload),
		})
		return { status: response.status, body: await response.json() }
	}, { route: path, verb: method, payload: data })
}

/**
 * Open the calendar and wait for the week's list of days.
 *
 * @param page - Page of a signed-in household member, inside the app.
 */
export async function openCalendarWeek(page: Page): Promise<void> {
	await page.goto('/apps/chorestrike/#/calendar')
	await expect(page.getByTestId('calendar-view')).toBeVisible({ timeout: 30000 })
	await expect(page.getByTestId('calendar-agenda')).toBeVisible({ timeout: 20000 })
}

/**
 * Open the owner's view of every member's week (household settings) and
 * wait for its member cards.
 *
 * @param page - Page of a signed-in house owner.
 */
export async function openMemberPlanning(page: Page): Promise<void> {
	await openSettings(page, 'member-planning')
	await expect(page.getByTestId('availability-settings')).toBeVisible({ timeout: 30000 })
	await expect(page.getByTestId('edit-availability-button').first()).toBeVisible({ timeout: 20000 })
}

/**
 * Open the planning category of the personal settings (own days, own
 * energy and preferred days) and wait for both forms.
 *
 * @param page - Page of a signed-in household member.
 */
export async function openPlanningSettings(page: Page): Promise<void> {
	await openSettings(page, 'planning')
	await expect(page.getByTestId('self-availability-settings')).toBeVisible({ timeout: 30000 })
	await expect(page.getByTestId('energy-budget-settings')).toBeVisible()
}
