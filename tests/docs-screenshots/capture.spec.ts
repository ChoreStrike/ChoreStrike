/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Documentation screenshot capture (CS-PLAN-087 WP-B, TECH-DOCS-007).
 *
 * Runs only through `npm run docs:screenshots` (playwright.docs.config.ts)
 * against an isolated staged row — never part of a gate. It reuses the E2E
 * `setup` project and auth contexts. Before anything is captured it proves
 * the target is the seeded demo household; then it presents the household
 * (name, a small reward catalog) and captures the scenes of scenes.js.
 */

import { expect, test } from '@playwright/test'
import type {APIResponse, Page} from '@playwright/test';
import path from 'node:path'
import { createUserContext } from '../e2e/fixtures/auth-contexts'
import { newCommandId } from '../e2e/fixtures/completion-api'
import { DatabaseManager } from '../e2e/fixtures/database-fixtures'
import { holdToConfirm } from '../e2e/fixtures/hold-to-confirm'
import { assertCaptureTarget, PHONE, PRESENTED_HOUSE_NAME, SCENES, SEEDED_HOUSE_NAME, SEEDED_ROLES } from './scenes'

const API = '/apps/chorestrike/api/v1'
const OUTPUT_DIR = path.resolve(__dirname, '../../docs/screenshots')

/** Demo catalog for the reward scene (prices in whole Coins). */
const CATALOG = [
	{ title: 'Pick the movie for movie night', description: 'Your choice on Friday evening.', coins: 3 },
	{ title: 'Pizza Friday', description: 'Homemade pizza, you choose the toppings.', coins: 8 },
	{ title: '30 minutes extra screen time', description: 'Redeem on a weekend day.', coins: 5 },
	{ title: 'Trip to the climbing hall', description: 'One afternoon with a parent.', coins: 20 },
]

test.describe.configure({ mode: 'serial' })

async function jsonOf (response: APIResponse) {
	expect(response.ok(), await response.text()).toBe(true)
	return (await response.json()).data
}

/** Steps after the scene's view is ready. */
const ACTION_STEPS: Record<string, (page: Page) => Promise<void>> = {
	async planPreview(page) {
		// All tasks: the planner only proposes changes where the plan differs
		// (the seed's unassigned tasks).
		const tasks = page.locator('[data-testid^="planner-task-checkbox-"]')
		await expect(tasks.first()).toBeVisible({ timeout: 30000 })
		for (const task of await tasks.all()) {
			await task.check()
		}
		await page.getByTestId('preview-plan-button').click()
		const changes = page.getByTestId('plan-changes')
		await expect(changes).toBeVisible({ timeout: 30000 })
		await changes.evaluate((list) => list.parentElement!.scrollIntoView({ block: 'start' }))
	},
	async notificationSettings(page) {
		const section = page.getByTestId('notifications-section')
		await expect(section).toBeVisible({ timeout: 30000 })
		await section.evaluate((element) => element.scrollIntoView({ block: 'start' }))
	},
	async completeTask(page) {
		await holdToConfirm(page.locator('[data-testid^="today-complete-button-"]').first())
		await expect(page.getByTestId('undo-snackbar')).toBeVisible({ timeout: 30000 })
	},
}

test('the target is the seeded demo household', async ({ browser, baseURL }) => {
	assertCaptureTarget(baseURL)
	expect(await new DatabaseManager(baseURL!, test.info().project.name).isTestMode()).toBe(true)
	const { context, page } = await createUserContext(browser, 'mom')
	try {
		expect((await jsonOf(await page.request.get(`${API}/house`))).name).toBe(SEEDED_HOUSE_NAME)
		const members: Array<{ id: number, nextcloudUserId: string }> = await jsonOf(await page.request.get(`${API}/users`))
		expect(members.map((member) => member.nextcloudUserId).sort()).toEqual([...SEEDED_ROLES, 'admin'].sort())

		// Present the household: the seed's test-only admin member leaves
		// (the planner would otherwise assign to "Admin"), the name reads
		// like a family's, and a small catalog exists.
		const admin = members.find((member) => member.nextcloudUserId === 'admin')!
		expect((await page.request.delete(`${API}/users/${admin.id}`)).ok()).toBe(true)
		await jsonOf(await page.request.put(`${API}/house`, { data: { name: PRESENTED_HOUSE_NAME } }))
		const house = await jsonOf(await page.request.get(`${API}/house`))
		await jsonOf(await page.request.put(`${API}/house/catalog`, {
			data: { catalogEnabled: true, expectedVersion: house.stateVersion, commandId: newCommandId() },
		}))
		for (const item of CATALOG) {
			await jsonOf(await page.request.post(`${API}/rewards`, {
				data: { title: item.title, description: item.description, priceMinor: item.coins * 100, commandId: newCommandId() },
			}))
		}
	} finally {
		await context.close()
	}
})

for (const scene of SCENES) {
	test(`captures ${scene.output}`, async ({ browser, baseURL }) => {
		if (scene.prepare === 'emptyHousehold') {
			await new DatabaseManager(baseURL!, test.info().project.name).seed('emptyhouse')
		}
		const phone = scene.viewport === PHONE
		const { context, page } = await createUserContext(browser, scene.role, {
			viewport: scene.viewport,
			...(phone ? { deviceScaleFactor: 2, isMobile: true, hasTouch: true } : {}),
		})
		try {
			await page.goto(`/apps/chorestrike/#${scene.route}`)
			await expect(page.getByTestId(scene.ready)).toBeVisible({ timeout: 30000 })
			if (scene.action) {
				await ACTION_STEPS[scene.action](page)
			}
			// Settle: no pending requests, web fonts loaded, no hover state.
			await page.waitForLoadState('networkidle')
			await page.evaluate(() => document.fonts.ready)
			await page.mouse.move(scene.viewport.width - 1, scene.viewport.height - 1)
			await page.screenshot({ path: path.join(OUTPUT_DIR, scene.output), animations: 'disabled', caret: 'hide' })
		} finally {
			await context.close()
		}
	})
}
