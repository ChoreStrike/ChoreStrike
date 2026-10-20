/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * E2E: color contrast in the light and dark Nextcloud themes (CS-PLAN-084
 * WP-B, UX-017). axe's WCAG 2 AA `color-contrast` rule runs over the app
 * container (`#content`) of every main view. The theme is switched for
 * one dedicated member through the Nextcloud theming API and restored to the
 * system default afterwards; Nextcloud's own chrome is out of scope.
 *
 * Requires: Docker test environment with the `family` database profile.
 */

import type { Page } from '@playwright/test'

import { AxeBuilder } from '@axe-core/playwright'
import { setArcadeGate } from './fixtures/arcade-gate.ts'
import { createUserContext } from './fixtures/auth-contexts.ts'
import { expect, test } from './fixtures/database-fixtures.ts'

const USER = 'friend'
const THEMES = ['light', 'dark'] as const

/** Enable exactly one Nextcloud theme for the signed-in user (default = follow the system). */
async function useTheme(page: Page, theme: 'light' | 'dark' | 'default'): Promise<void> {
	for (const other of ['light', 'dark']) {
		if (other !== theme) {
			await page.request.delete(`/ocs/v2.php/apps/theming/api/v1/theme/${other}`, { headers: { 'OCS-APIRequest': 'true' } })
		}
	}
	if (theme !== 'default') {
		const response = await page.request.put(`/ocs/v2.php/apps/theming/api/v1/theme/${theme}/enable`, { headers: { 'OCS-APIRequest': 'true' } })
		expect(response.status(), await response.text()).toBe(200)
	}
}

async function firstRoomId(page: Page): Promise<number> {
	const response = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms')
	expect(response.status()).toBe(200)
	const rooms = ((await response.json()).data as Array<{ rooms: Array<{ id: number }> }>).flatMap((floor) => floor.rooms)
	expect(rooms.length, 'the family seed provides rooms').toBeGreaterThan(0)
	return rooms[0].id
}

test.describe('Color contrast (light/dark Nextcloud themes)', () => {
	test.use({ databaseProfile: 'family' })
	// One member's theme is switched per test: never in parallel.
	test.describe.configure({ mode: 'serial' })

	for (const theme of THEMES) {
		test(`main views have no color-contrast violations in the ${theme} theme`, async ({ browser }) => {
			test.setTimeout(240000)
			const { context, page } = await createUserContext(browser, USER)
			try {
				await useTheme(page, theme)
				const views = [
					{ name: 'My Day', path: '#/today', ready: 'today-view' },
					{ name: 'Dashboard', path: '#/dashboard', ready: 'dashboard-view' },
					{ name: 'Tasks', path: '#/tasks', ready: 'task-manager-view' },
					{ name: 'Room', path: `#/room/${await firstRoomId(page)}`, ready: 'room-detail-view' },
					{ name: 'Leaderboard', path: '#/leaderboard', ready: 'leaderboard-view' },
					{ name: 'Insights', path: '#/insights', ready: 'insights-view' },
					{ name: 'Scheduling', path: '#/scheduling', ready: 'scheduling-view' },
					{ name: 'Calendar', path: '#/calendar', ready: 'calendar-view' },
					{ name: 'Settings', path: '#/settings', ready: 'settings-view' },
				]
				const violations: string[] = []
				const collect = async (name: string): Promise<void> => {
					await page.waitForLoadState('networkidle')
					// Measure the settled UI: CSS transitions (e.g. the active navigation
					// background) would otherwise be sampled at an intermediate color.
					// Infinite decorative loops (e.g. the health bar shimmer) never settle.
					await page.waitForFunction(() => document.getAnimations()
						.filter((animation) => animation.effect?.getTiming().iterations !== Infinity)
						.every((animation) => animation.playState !== 'running'))
					const result = await new AxeBuilder({ page }).include('#content').withRules(['color-contrast']).analyze()
					for (const violation of result.violations) {
						for (const node of violation.nodes) {
							violations.push(`${name}: ${node.target.join(' ')} — ${node.failureSummary?.split('\n').slice(-1)[0]?.trim()}`)
						}
					}
				}
				for (const view of views) {
					await page.goto(`/apps/chorestrike/${view.path}`)
					await expect(page.locator('body')).toHaveAttribute(`data-theme-${theme}`, '')
					await expect(page.getByTestId(view.ready)).toBeVisible({ timeout: 20000 })
					await collect(view.name)
				}
				// CS-PLAN-086: the arcade catalog and an open game on its start overlay
				// (the owner gate opens the game for the seeded member).
				await setArcadeGate(browser, false)
				await page.goto('/apps/chorestrike/#/arcade')
				await expect(page.getByTestId('arcade-card-snake')).toBeVisible({ timeout: 20000 })
				await collect('Arcade')
				await page.getByTestId('arcade-play-snake').click()
				await expect(page.getByTestId('game-shell')).toHaveAttribute('data-phase', 'ready', { timeout: 15000 })
				await collect('Arcade game')
				expect(violations, violations.join('\n')).toEqual([])
			} finally {
				await useTheme(page, 'default').catch(() => {})
				await context.close()
			}
		})
	}
})
