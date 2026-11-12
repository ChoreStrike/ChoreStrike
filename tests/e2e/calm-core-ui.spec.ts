import type { DatabaseProfile } from './fixtures/database-fixtures.ts'

import { createUserContext } from './fixtures/auth-contexts.ts'
import { completeWithOptionalDialog } from './fixtures/completion-api.ts'
import { expect, test } from './fixtures/database-fixtures.ts'
import { houseDateOffset } from './fixtures/house-clock.js'

/**
 * E2E test: the calm core UI (CS-PLAN-070 WP6) — serial family pool.
 *
 * The WP6 contract as CS-PLAN-090 WP-B leaves it: every role lands on
 * Today (route-level redirect), the old Dashboard address leads there
 * too, Today shows the own Coins and stays free of arcade/catalog
 * surfaces, the optional arcade hides together with its navigation entry
 * while `arcadeVisible` is off, and
 * an absence is a plain period (ADR-020): a child enters one, everyone
 * sees it and the owner deletes it through the real UI.
 */

test.describe('Calm core UI (CS-PLAN-070 WP6)', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })
	test.setTimeout(120000)

	test('a free day stays neutral without catch-up, extra offers or invented success', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const { context, page } = await createUserContext(browser, 'grandma')
		try {
			await page.goto('/apps/chorestrike/#/today')
			await expect(page.getByTestId('today-empty-state')).toContainText('Nothing is planned for today.')
			await expect(page.getByTestId('today-task-item')).toHaveCount(0)
			await expect(page.getByTestId('today-section-catchup')).toHaveCount(0)
			await expect(page.getByTestId('today-overload')).toHaveCount(0)
			await expect(page.locator('[data-testid^="today-completed-check-"]')).toHaveCount(0)
		} finally {
			await context.close()
		}
	})

	test('the animation is on by default, reduced motion wins and switching it off holds', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const task = seededData.data.tasks.find((entry) => entry.name === 'Do the dishes')!
		const roomTask = seededData.data.roomTasks.find((entry) => entry.taskId === task.id)!
		const { context, page } = await createUserContext(browser, 'mom', { reducedMotion: 'reduce' })
		const button = page.getByTestId(`today-complete-button-${roomTask.id}`)
		const canvases = () => expect(page.locator('body'))
		// Observe insertions, not a late snapshot that could miss transient confetti.
		const countCanvases = () => page.evaluate(() => {
			document.body.dataset.celebrationCanvases = '0'
			new MutationObserver((records) => {
				for (const record of records) {
					for (const node of record.addedNodes) {
						if (node instanceof HTMLCanvasElement) {
							document.body.dataset.celebrationCanvases = String(Number(document.body.dataset.celebrationCanvases) + 1)
						}
					}
				}
			}).observe(document.body, { childList: true })
		})
		const completeOnce = async () => {
			expect((await completeWithOptionalDialog(page, button)).ok()).toBe(true)
			await expect(button).toHaveCount(0)
			await expect(page.getByTestId(`undo-snackbar-entry-${roomTask.id}`)).toBeVisible()
		}
		try {
			// The seeded member never saved a preference: on by default (D-090-05).
			await page.goto('/apps/chorestrike/#/settings?category=notifications')
			await expect(page.getByTestId('animation-toggle')).toBeChecked()
			await expect(page.getByTestId('sound-toggle')).not.toBeChecked()

			// Reduced motion wins over the default on a real completion.
			await page.getByTestId('nav-today').click()
			await countCanvases()
			await completeOnce()
			// The snackbar names what the completion paid (WP-F).
			await expect(page.getByTestId(`undo-snackbar-coins-${roomTask.id}`)).toContainText('Coins')
			await canvases().toHaveAttribute('data-celebration-canvases', '0')
			await expect(page.locator('body > canvas')).toHaveCount(0)
			await page.getByTestId(`undo-snackbar-undo-button-${roomTask.id}`).click()
			await expect(button).toBeVisible()

			// Positive control: without any opt-in the same session animates once the OS permits it.
			await page.emulateMedia({ reducedMotion: 'no-preference' })
			await completeOnce()
			await canvases().toHaveAttribute('data-celebration-canvases', '1')
			await page.getByTestId(`undo-snackbar-undo-button-${roomTask.id}`).click()
			await expect(button).toBeVisible()

			// Switching it off is the member's first save, and it holds.
			await page.goto('/apps/chorestrike/#/settings?category=notifications')
			await page.getByText('Celebration animations', { exact: true }).click()
			await expect(page.getByTestId('animation-toggle')).not.toBeChecked()
			const saved = page.waitForResponse((response) => response.request().method() === 'PUT' && response.url().endsWith('/preferences/me'))
			await page.getByTestId('save-preferences').click()
			expect((await saved).ok()).toBe(true)
			await page.reload()
			await expect(page.getByTestId('animation-toggle')).not.toBeChecked()
			await page.getByTestId('nav-today').click()
			await countCanvases()
			await completeOnce()
			await canvases().toHaveAttribute('data-celebration-canvases', '0')
		} finally {
			await context.close()
		}
	})

	test('every role lands on My Day, now Today, and the old Dashboard address leads there', async ({ browser, seededData: _seededData }) => {
		const owner = await createUserContext(browser, 'mom')
		const child = await createUserContext(browser, 'teen')
		try {
			// Owner bare root → Today (the retired child-only special case
			// is the universal landing, Q-077-01 Option A).
			await owner.page.goto('/apps/chorestrike/')
			await expect(owner.page.getByTestId('today-view')).toBeVisible({ timeout: 30000 })
			await expect(owner.page).toHaveURL(/#\/today$/)

			// Child bare root → Today as well.
			await child.page.goto('/apps/chorestrike/')
			await expect(child.page.getByTestId('today-view')).toBeVisible({ timeout: 30000 })
			await expect(child.page).toHaveURL(/#\/today$/)

			// The Dashboard merged into Today: its address redirects for
			// every role, and no second overview is left in the navigation.
			for (const page of [owner.page, child.page]) {
				await page.goto('/apps/chorestrike/#/dashboard')
				await expect(page.getByTestId('today-view')).toBeVisible({ timeout: 30000 })
				await expect(page).toHaveURL(/#\/today$/)
				await expect(page.getByTestId('nav-dashboard')).toHaveCount(0)
			}
			// The retired military tagline is gone (C5f).
			await expect(owner.page.getByTestId('today-view')).not.toContainText('Defend your Home Base')
		} finally {
			await owner.context.close()
			await child.context.close()
		}
	})

	test('Today shows the own Coins and no arcade, catalog or ranking surface', async ({ browser, seededData: _seededData }) => {
		const owner = await createUserContext(browser, 'mom')
		try {
			await owner.page.goto('/apps/chorestrike/#/today')
			const today = owner.page.getByTestId('today-view')
			await expect(today).toBeVisible({ timeout: 30000 })

			// The wallet moved here with the Dashboard (CS-PLAN-090 WP-B).
			await expect(today.getByTestId('own-wallet-available')).toHaveText(/^-?\d+(\.\d+)?$/, { timeout: 30000 })

			// Still none of the optional surfaces on the landing page.
			for (const surface of ['arcade-view', 'arcade-catalog', 'rewards-view', 'ranking-panel']) {
				await expect(today.getByTestId(surface)).toHaveCount(0)
			}
		} finally {
			await owner.context.close()
		}
	})

	test('the optional arcade hides with its navigation entry when arcadeVisible is off', async ({ browser, seededData: _seededData }) => {
		const owner = await createUserContext(browser, 'mom')
		try {
			await owner.page.goto('/apps/chorestrike/#/today')
			await expect(owner.page.getByTestId('today-view')).toBeVisible({ timeout: 30000 })

			// Default (opt-in on): the navigation entry exists.
			await expect(owner.page.getByTestId('nav-arcade')).toBeVisible({ timeout: 15000 })

			// Opt out through the settings surface (Q-077-05). The switch
			// input is visually hidden behind its content span — click the
			// visible switch label (the realistic user interaction; mom is
			// pinned to English by the family-core-ux language test).
			await owner.page.goto('/apps/chorestrike/#/settings?category=notifications')
			await expect(owner.page.getByTestId('notification-preferences')).toBeVisible({ timeout: 30000 })
			const arcadeSwitch = owner.page.getByTestId('arcade-visible-switch')
			await expect(arcadeSwitch).toBeChecked()
			await owner.page.getByText('Show the arcade', { exact: true }).click()
			await expect(arcadeSwitch).not.toBeChecked()
			await owner.page.getByTestId('save-preferences').click()

			// Consumed immediately: the navigation entry disappears without
			// a reload.
			await expect(owner.page.getByTestId('nav-arcade')).toHaveCount(0, { timeout: 15000 })

			// A direct link shows the neutral placeholder, never the arcade.
			await owner.page.goto('/apps/chorestrike/#/arcade')
			await owner.page.reload()
			await expect(owner.page.getByTestId('arcade-hidden')).toBeVisible({ timeout: 30000 })
			await expect(owner.page.getByTestId('arcade-surface')).toHaveCount(0)

			// Restore the opt-in for the other gate pools (shared DB).
			await owner.page.goto('/apps/chorestrike/#/settings?category=notifications')
			await expect(owner.page.getByTestId('notification-preferences')).toBeVisible({ timeout: 30000 })
			const arcadeSwitchRestore = owner.page.getByTestId('arcade-visible-switch')
			await expect(arcadeSwitchRestore).not.toBeChecked()
			await owner.page.getByText('Show the arcade', { exact: true }).click()
			await expect(arcadeSwitchRestore).toBeChecked()
			await owner.page.getByTestId('save-preferences').click()
			await owner.page.goto('/apps/chorestrike/#/today')
			await expect(owner.page.getByTestId('nav-arcade')).toBeVisible({ timeout: 15000 })
		} finally {
			await owner.context.close()
		}
	})

	test('a child enters an absence, everyone sees it, the owner deletes it', async ({ browser, seededData: _seededData }) => {
		const child = await createUserContext(browser, 'teen')
		const sibling = await createUserContext(browser, 'kid')
		const owner = await createUserContext(browser, 'mom')
		// A running absence: deleting it keeps today and frees the days after.
		const start = houseDateOffset(0, 'UTC')
		const end = houseDateOffset(3, 'UTC')
		try {
			// The child enters a plain period (ADR-020): no reason, no
			// request, and it takes effect at once.
			await child.page.goto('/apps/chorestrike/#/absence')
			await expect(child.page.getByTestId('absence-view')).toBeVisible({ timeout: 30000 })
			await expect(child.page.getByTestId('absence-view').locator('select')).toHaveCount(0)
			await child.page.getByTestId('absence-own-start').fill(start)
			await child.page.getByTestId('absence-own-end').fill(end)
			const entered = child.page.waitForResponse((response) => response.request().method() === 'POST' && response.url().endsWith('/api/v1/pauses'))
			await child.page.getByTestId('absence-own-submit').click()
			const absence = (await (await entered).json()).data
			expect(absence.state).toBe('acknowledged')
			const entryId = `absence-entry-${absence.pauseGroupId}`
			const deleteId = `absence-delete-${absence.pauseGroupId}`

			// The child sees its entry and may delete it.
			await expect(child.page.getByTestId(entryId)).toBeVisible({ timeout: 15000 })
			await expect(child.page.getByTestId(deleteId)).toBeVisible()

			// Everyone sees the entry; a sibling cannot delete it.
			await sibling.page.goto('/apps/chorestrike/#/absence')
			await expect(sibling.page.getByTestId(entryId)).toBeVisible({ timeout: 30000 })
			await expect(sibling.page.getByTestId(deleteId)).toHaveCount(0)
			await expect(sibling.page.getByTestId('absence-owner-panel')).toHaveCount(0)

			// The owner sees it and deletes it after the confirmation.
			await owner.page.goto('/apps/chorestrike/#/absence')
			await expect(owner.page.getByTestId(entryId)).toBeVisible({ timeout: 30000 })
			await expect(owner.page.getByTestId('absence-owner-panel')).toBeVisible()
			await owner.page.getByTestId(deleteId).click()
			const deleted = owner.page.waitForResponse((response) => response.request().method() === 'DELETE' && response.url().includes(`/api/v1/pauses/${absence.pauseGroupId}`))
			await owner.page.getByTestId('confirm-dialog-confirm').click()
			expect((await (await deleted).json()).data.state).toBe('cancelled')
			await expect(owner.page.getByTestId(entryId)).toHaveCount(0, { timeout: 15000 })

			// The deleted absence is gone for the child as well.
			await child.page.reload()
			await expect(child.page.getByTestId('absence-view')).toBeVisible({ timeout: 30000 })
			await expect(child.page.getByTestId(entryId)).toHaveCount(0)
		} finally {
			await owner.context.close()
			await sibling.context.close()
			await child.context.close()
		}
	})
})
