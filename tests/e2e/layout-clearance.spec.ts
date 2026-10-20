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

import type { Locator, Page } from '@playwright/test'

import { createUserContext } from './fixtures/auth-contexts.ts'
import { expect, test } from './fixtures/database-fixtures.ts'

const USER = 'friend'
const VIEWPORTS = [
	{ name: 'desktop', width: 1280, height: 800 },
	{ name: 'phone', width: 390, height: 844 },
] as const

// Real Nextcloud 34/35 metrics: 34 px clickable area + 2 * 8 px navigation
// padding clear the toggle. Views keep a 2rem gutter on desktop and 1rem on
// phones; Nextcloud sets 1rem to its 15 px base font size.
const TOGGLE_CLEARANCE = 50
const DESKTOP_GUTTER_REM = 2
const PHONE_GUTTER_REM = 1

async function remSize(page: Page): Promise<number> {
	return await page.evaluate(() => parseFloat(getComputedStyle(document.documentElement).fontSize))
}

type Box = { left: number, right: number, top: number, bottom: number }

const overlaps = (a: Box, b: Box): boolean => a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom

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
	const rooms = ((await response.json()).data as Array<{ rooms: Array<{ id: number }> }>).flatMap((floor) => floor.rooms)
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
				const views: Array<{ path: string, ready: string, content?: string }> = [
					{ path: '#/today', ready: 'today-view' },
					{ path: '#/dashboard', ready: 'dashboard-view' },
					{ path: '#/tasks', ready: 'task-manager-view' },
					{ path: `#/room/${await firstRoomId(page)}`, ready: 'room-detail-view', content: 'room-task-list' },
					{ path: '#/leaderboard', ready: 'leaderboard-view' },
					{ path: '#/insights', ready: 'insights-view', content: 'member-fairness-table' },
					{ path: '#/arcade', ready: 'arcade-view' },
					{ path: '#/scheduling', ready: 'scheduling-view' },
					{ path: '#/calendar', ready: 'calendar-view' },
					{ path: '#/settings', ready: 'settings-view' },
				]
				const covered: string[] = []
				const scrolled: string[] = []
				const insets: string[] = []
				const gutter = (viewport.name === 'phone' ? PHONE_GUTTER_REM : DESKTOP_GUTTER_REM) * await remSize(page)
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
					// CR-LAYOUT-01: the content keeps the gutter, only the heading row adds
					// the toggle clearance on top of it.
					const padding = await root.evaluate((element) => {
						const header = element.querySelector('header')
						return {
							root: parseFloat(getComputedStyle(element).paddingInlineStart),
							header: header ? parseFloat(getComputedStyle(header).paddingInlineStart) : Number.NaN,
						}
					})
					if (padding.root !== gutter || padding.header !== TOGGLE_CLEARANCE - gutter) {
						insets.push(`${view.ready}: root ${padding.root}px / heading row ${padding.header}px, expected ${gutter}px / ${TOGGLE_CLEARANCE - gutter}px`)
					}
					if (viewport.name === 'phone') {
						// The content that once overflowed must be rendered, or the
						// check would pass on an empty view.
						if (view.content) {
							await expect(root.getByTestId(view.content)).toBeVisible()
						}
						scrolled.push(...(await sidewaysScrollers(root)).map((scroller) => `${view.ready}: ${scroller}`))
					}
				}
				expect(covered, covered.join('\n')).toEqual([])
				expect(insets, `content keeps the ${gutter}px gutter, the heading row clears the toggle (CR-LAYOUT-01)\n${insets.join('\n')}`).toEqual([])
				expect(scrolled, `no view scrolls sideways at phone width (UX-018)\n${scrolled.join('\n')}`).toEqual([])
			} finally {
				await context.close()
			}
		})
	}

	test('the view insets follow the host navigation metrics, not constants (CR-LAYOUT-01)', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, USER, { viewport: VIEWPORTS[0] })
		try {
			await page.goto('/apps/chorestrike/#/today')
			const root = page.getByTestId('today-view')
			await expect(root).toBeVisible({ timeout: 20000 })
			await page.waitForLoadState('networkidle')
			const heading = root.getByRole('heading').first()
			await expect(heading).toBeVisible()

			// The tokens resolve inside `.app-content`; a probe child reads them.
			const measure = async (): Promise<{ inlineStart: number, headingInset: number }> => await page.evaluate(() => {
				const content = document.querySelector('.app-content')
				if (!content) {
					throw new Error('the app content is missing')
				}
				const probe = document.createElement('div')
				content.appendChild(probe)
				probe.style.paddingInlineStart = 'var(--cs-view-inline-start)'
				const inlineStart = parseFloat(getComputedStyle(probe).paddingInlineStart)
				probe.style.paddingInlineStart = 'var(--cs-view-heading-inset)'
				const headingInset = parseFloat(getComputedStyle(probe).paddingInlineStart)
				probe.remove()
				return { inlineStart, headingInset }
			})

			const desktopGutter = DESKTOP_GUTTER_REM * await remSize(page)
			expect(await measure()).toEqual({ inlineStart: TOGGLE_CLEARANCE, headingInset: TOGGLE_CLEARANCE - desktopGutter })
			const before = (await textBox(heading)).left

			// A host with larger metrics: 44 px clickable area, 12 px navigation padding.
			await page.evaluate(() => {
				document.body.style.setProperty('--default-clickable-area', '44px')
				const content = document.querySelector<HTMLElement>('.app-content')
				content?.style.setProperty('--app-navigation-padding', '12px')
			})
			expect(await measure()).toEqual({ inlineStart: 68, headingInset: 68 - desktopGutter })
			expect((await textBox(heading)).left - before, 'the heading row follows the metrics').toBe(68 - TOGGLE_CLEARANCE)
		} finally {
			await context.close()
		}
	})

	test('the toggle covers no heading in a right-to-left layout (CR-LAYOUT-01)', async ({ browser }) => {
		const { context, page } = await createUserContext(browser, USER, { viewport: VIEWPORTS[1] })
		try {
			for (const view of [{ path: '#/today', ready: 'today-view' }, { path: '#/dashboard', ready: 'dashboard-view' }]) {
				await page.goto(`/apps/chorestrike/${view.path}`)
				const root = page.getByTestId(view.ready)
				await expect(root).toBeVisible({ timeout: 20000 })
				await page.waitForLoadState('networkidle')
				await page.evaluate(() => document.documentElement.setAttribute('dir', 'rtl'))
				const toggle = page.getByRole('button', { name: /(open|close) navigation/i })
				await expect(toggle).toBeVisible()
				const heading = root.getByRole('heading').first()
				await expect(heading).toBeVisible()
				expect(overlaps(await boxOf(toggle), await textBox(heading)), `${view.ready}: toggle and heading text must not overlap in RTL`).toBe(false)
				const rootStart = await root.evaluate((element) => parseFloat(getComputedStyle(element).paddingInlineStart))
				expect(rootStart, 'the content keeps the gutter on the inline-start side').toBe(PHONE_GUTTER_REM * await remSize(page))
			}
		} finally {
			await context.close()
		}
	})

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
			const topmostIsFill = await page.evaluate(({ x, y }) => document.elementFromPoint(x, y)?.closest('.health-bar-fill') !== null, { x: (fillBox.left + fillBox.right) / 2, y: (fillBox.top + fillBox.bottom) / 2 })
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
