import type { Page } from '@playwright/test'

import { expect } from '@playwright/test'

/**
 * Ways into the Settings page (FEAT-UX-054, CS-PLAN-090 WP-D): a switch
 * *Personal | Household* and categories that start closed. A category
 * mounts, and so reads its data, only when it opens; every spec opens the
 * one it needs through this helper.
 */

const HOUSEHOLD = ['house', 'members', 'member-planning', 'structure', 'categories', 'rewards', 'arcade', 'danger'] as const

export type SettingsCategory = typeof HOUSEHOLD[number] | 'notifications' | 'appearance' | 'planning' | 'title'

/**
 * On the Settings page: show the area of a category and expand it, unless
 * it is open already. Household categories exist for the owner only.
 *
 * @param page - Page showing the Settings view.
 * @param category - The category to open.
 */
export async function openSettingsCategory(page: Page, category: SettingsCategory): Promise<void> {
	await expect(page.getByTestId('settings-view')).toBeVisible({ timeout: 30000 })
	const toggle = page.getByTestId(`settings-category-${category}`)
	if (!(await toggle.isVisible())) {
		const tab = (HOUSEHOLD as readonly string[]).includes(category) ? 'household' : 'personal'
		await page.getByTestId(`settings-tab-${tab}`).click()
	}
	await expect(toggle).toBeVisible({ timeout: 15000 })
	if ((await toggle.getAttribute('aria-expanded')) !== 'true') {
		await toggle.click()
	}
	await expect(toggle).toHaveAttribute('aria-expanded', 'true')
}

/**
 * Open the Settings page by its address with one category expanded.
 *
 * @param page - Page of a signed-in household member.
 * @param category - The category the address names.
 */
export async function openSettings(page: Page, category: SettingsCategory): Promise<void> {
	await page.goto(`/apps/chorestrike/#/settings?category=${category}`)
	await expect(page.getByTestId('settings-view')).toBeVisible({ timeout: 30000 })
	await expect(page.getByTestId(`settings-category-${category}`)).toHaveAttribute('aria-expanded', 'true', { timeout: 15000 })
}

/**
 * The own reward requests left the navigation: they open from Rewards and,
 * with either catalog gate closed too, from the personal settings.
 *
 * @param page - Page of a signed-in household member.
 * @param label - The link text to expect (for a localized session).
 */
export async function expectOwnRequestsLink(page: Page, label?: string): Promise<void> {
	await page.goto('/apps/chorestrike/#/settings')
	const link = page.getByTestId('settings-open-requests')
	await expect(link).toBeVisible({ timeout: 30000 })
	await expect(page.getByTestId('nav-reward-requests')).toHaveCount(0)
	if (label !== undefined) {
		await expect(link).toContainText(label)
	}
}
