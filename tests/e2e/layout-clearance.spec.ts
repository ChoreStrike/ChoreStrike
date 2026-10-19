/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * E2E: rendered layout regressions found while capturing the README
 * screenshots (TECH-DOCS-005). Nextcloud's navigation toggle sits in the
 * top-left corner of the app content and covered the first letters of most
 * view headings; the dashboard's house-health fill was painted under its own
 * track, the house name touched the percentage and the title icon was never
 * registered. Measured in a real browser at a docked-navigation desktop width
 * and a phone width; read-only on the `family` seed. At phone width no routed
 * view may scroll sideways (UX-018: the Insights fairness table and the room
 * detail grid did).
 *
 * Requires: Docker test environment with the `family` database profile.
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import type { Locator, Page } from '@playwright/test'

const USER = 'friend'
const VIEWPORTS = [
	{ name: 'desktop', width: 1280, height: 800 },
	{ name: 'phone', width: 390, height: 844 },
] as const

type Box = { left: number, right: number, top: number, bottom: number }

const overlaps = (a: Box, b: Box): boolean =>
	a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom

/** Box of the heading's rendered text, not of its (often full-width) element. */
async function textBox(heading: Locator): Promise<Box> {
	return await heading.evaluate((element) => {
		const range = document.createRange()
		range.selectNodeContents(element)
		const { left, right, top, bottom } = range.getBoundingClientRect()
		return { left, right, top, bottom }
	})
}

async function boxOf(locator: Locator): Promise<Box> {
	const box = await locator.boundingBox()
	expect(box, 'element is rendered').not.toBeNull()
	return { left: box!.x, right: box!.x + box!.width, top: box!.y, bottom: box!.y + box!.height }
}

/**
 * Scroll containers from the view root up to the document that scroll
 * sideways (UX-018). A deliberately scrolling box inside the view, such as
 * the Insights fairness table, is not an ancestor and stays allowed.
 */
async function sidewaysScrollers(root: Locator): Promise<string[]> {
	return await root.evaluate((element) => {
		const found: string[] = []
		for (let node: Element | null = element; node; node = node.parentElement) {
			const scrolls = node === document.scrollingElement || ['auto', 'scroll'].includes(getComputedStyle(node).overflowX)
			if (scrolls && node.scrollWidth > node.clientWidth + 1) {
				found.push(`${node.tagName.toLowerCase()}${node.id ? '#' + node.id : ''} ${node.scrollWidth} > ${node.clientWidth}`)
			}
		}
		return found
	})
}

async function firstRoomId(page: Page): Promise<number> {
	const response = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms')
	expect(response.status()).toBe(200)
	const rooms = ((await response.json()).data as Array<{ rooms: Array<{ id: number }> }>).flatMap(floor => floor.rooms)
	expect(rooms.length, 'the family seed provides rooms').toBeGreaterThan(0)
	return rooms[0].id
}

test.describe('Layout clearance and dashboard health bar', () => {
	test.use({ databaseProfile: 'family' })

	for (const viewport of VIEWPORTS) {
		test(`the navigation toggle covers no view heading (${viewport.name})`, async ({ browser }) => {
			test.setTimeout(180000)
			const { context, page } = await createUserContext(browser, USER, { viewport })
			try {
				const views = [
					{ path: '#/today', ready: 'today-view' },
					{ path: '#/dashboard', ready: 'dashboard-view' },
					{ path: '#/tasks', ready: 'task-manager-view' },
					{ path: `#/room/${await firstRoomId(page)}`, ready: 'room-detail-view' },
					{ path: '#/leaderboard', ready: 'leaderboard-view' },
					{ path: '#/insights', ready: 'insights-view' },
					{ path: '#/arcade', ready: 'arcade-view' },
					{ path: '#/scheduling', ready: 'scheduling-view' },
					{ path: '#/calendar', ready: 'calendar-view' },
					{ path: '#/settings', ready: 'settings-view' },
				]
				const covered: string[] = []
				const scrolled: string[] = []
				for (const view of views) {
					await page.goto(`/apps/chorestrike/${view.path}`)
					const root = page.getByTestId(view.ready)
					await expect(root).toBeVisible({ timeout: 20000 })
					await page.waitForLoadState('networkidle')
					const toggle = page.getByRole('button', { name: /(open|close) navigation/i })
					await expect(toggle).toBeVisible()
					const heading = root.getByRole('heading').first()
					await expect(heading).toBeVisible()
					const toggleBox = await boxOf(toggle)
					const headingBox = await textBox(heading)
					if (overlaps(toggleBox, headingBox)) {
						covered.push(`${view.ready}: toggle ${JSON.stringify(toggleBox)} covers heading text ${JSON.stringify(headingBox)}`)
					}
					if (viewport.name === 'phone') {
						scrolled.push(...(await sidewaysScrollers(root)).map(scroller => `${view.ready}: ${scroller}`))
					}
				}
				expect(covered, covered.join('\n')).toEqual([])
				expect(scrolled, `no view scrolls sideways at phone width (UX-018)\n${scrolled.join('\n')}`).toEqual([])
			} finally {
				await context.close()
			}
		})
	}

	test('the house-health bar shows its fill, icon and a gap before the percentage', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, USER, { viewport: VIEWPORTS[0] })
		try {
			await page.goto('/apps/chorestrike/#/dashboard')
			const health = page.getByTestId('house-health')
			await expect(health).toBeVisible({ timeout: 20000 })
			await page.waitForLoadState('networkidle')

			// The family seed starts at full house health: the fill must be the
			// topmost layer at its own center, not the track behind it.
			const fill = health.locator('.health-bar-fill')
			const fillBox = await boxOf(fill)
			expect(fillBox.right - fillBox.left).toBeGreaterThan(0)
			const topmostIsFill = await page.evaluate(({ x, y }) =>
				document.elementFromPoint(x, y)?.closest('.health-bar-fill') !== null,
			{ x: (fillBox.left + fillBox.right) / 2, y: (fillBox.top + fillBox.bottom) / 2 })
			expect(topmostIsFill, 'the fill paints above the track').toBe(true)

			const titleBox = await textBox(health.locator('.health-title'))
			const percentageBox = await boxOf(health.locator('.health-percentage'))
			expect(percentageBox.left - titleBox.right, 'gap between house name and percentage').toBeGreaterThanOrEqual(8)

			const icon = await boxOf(health.locator('.health-title svg'))
			expect(icon.right - icon.left, 'the title icon renders').toBeGreaterThan(0)
		} finally {
			await context.close()
		}
	})
})
