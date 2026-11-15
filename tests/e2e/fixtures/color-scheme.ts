/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * The viewer's colour scheme (CS-PLAN-090 WP-K) for browser specs: set it
 * through its real route and read the accent a part of the page resolves.
 * Callers restore *Standard*; the test-mode reset clears every scheme too.
 */

import type { Page } from '@playwright/test'

import { expect } from '@playwright/test'

export const COLOR_SCHEMES = ['ocean', 'forest', 'sunset', 'berry', 'slate'] as const

/**
 * Store the signed-in member's own colour scheme.
 *
 * @param page - Page of a context with the member's session and CSRF header.
 * @param scheme - A listed scheme key, or `standard`.
 */
export async function setColorScheme(page: Page, scheme: typeof COLOR_SCHEMES[number] | 'standard'): Promise<void> {
	const response = await page.request.put('/apps/chorestrike/api/v1/preferences/me/color-scheme', { data: { colorScheme: scheme } })
	expect(response.status(), await response.text()).toBe(200)
}

/**
 * The accent colour (`--color-primary-element`) the first element matching
 * a selector resolves, normalised to lower case.
 *
 * @param page - Page showing the app.
 * @param selector - CSS selector of the element to read.
 */
export async function accentOf(page: Page, selector: string): Promise<string> {
	return page.locator(selector).first().evaluate((element) => getComputedStyle(element)
		.getPropertyValue('--color-primary-element').trim().toLowerCase())
}
