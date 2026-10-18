/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * E2E: the household arcade catalog and its chore unlocks (CS-PLAN-086).
 * A locked game shows neutral progress and keeps its leaderboard, a
 * crafted score for it is refused, a real completion counts toward the
 * unlock, and the owner switch opens every game. Each game plays a round
 * through its deterministic opening; its score lands only on its own board
 * (Crumb Snake's rounds live in snake-game.spec.ts).
 *
 * Serial pool: the owner switch is household-wide app config; every test
 * reseeds the family profile, which restores the default (gate on). The
 * admin session is a plain member without finished chores.
 *
 * Requires: Docker test environment with the `family` database profile.
 */

import { test, expect } from './fixtures/database-fixtures'
import type { DatabaseProfile, SeededData } from './fixtures/database-fixtures'
import type { Browser, Page } from '@playwright/test'
import { createUserContext } from './fixtures/auth-contexts'
import { completeAsSoleParticipantViaApi } from './fixtures/completion-api'

/**
 * Run a step as the admin member in its own context (primed CSRF token
 * for the direct API calls).
 */
async function asAdmin(browser: Browser, step: (page: Page) => Promise<void>): Promise<void> {
	const { context, page } = await createUserContext(browser, 'admin')
	try {
		await step(page)
	} finally {
		await context.close()
	}
}

async function openArcade(page: Page): Promise<void> {
	await page.goto('/apps/chorestrike/#/arcade')
	await expect(page.getByTestId('arcade-surface')).toBeVisible({ timeout: 30000 })
}

/**
 * Finish one seeded chore as the admin member. Not every seeded task has a
 * completable occurrence at every wall-clock moment, so walk the active
 * ones with an explicit solo split (the completer takes part, Q-074-02).
 */
async function finishOneChore(page: Page, seeded: SeededData | null): Promise<void> {
	const roomTasks = (seeded?.data.roomTasks ?? []) as Array<{ id: number; isActive?: boolean }>
	for (const roomTask of roomTasks.filter((row) => row.isActive !== false)) {
		const response = await completeAsSoleParticipantViaApi(page, roomTask.id, 'admin').catch(() => null)
		if (response?.ok()) {
			return
		}
	}
	throw new Error('no seeded room task could be finished by admin')
}

test.describe('Household arcade (CS-PLAN-086)', () => {
	test.use({ databaseProfile: 'family' as DatabaseProfile })
	test.setTimeout(120000)

	test('a locked game shows neutral progress and keeps its leaderboard readable', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		await asAdmin(browser, async (page) => {
			await openArcade(page)

			await expect(page.getByTestId('arcade-card-snake')).toHaveAttribute('data-unlocked', 'false')
			await expect(page.getByTestId('arcade-progress-snake')).toHaveText('Unlocks at 10 finished chores · 10 to go')
			await expect(page.getByTestId('arcade-play-snake')).toHaveCount(0)
			// Scores-only (T10/D7d): no Coin, wallet or boost surface.
			await expect(page.getByTestId('arcade-surface')).not.toContainText(/coin|wallet|boost/i)

			await page.getByTestId('arcade-leaderboard-snake').click()
			await expect(page.getByTestId('game-leaderboard')).toHaveAttribute('data-game-id', 'snake')
		})
	})

	test('a crafted score for a locked game is refused', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		await asAdmin(browser, async (page) => {
			const response = await page.request.post('/apps/chorestrike/api/v1/minigames/snake/scores', {
				data: { score: 10, durationMs: 5000 },
			})

			expect(response.status()).toBe(403)
			expect((await response.json()).error.code).toBe('MINIGAME_LOCKED')
		})
	})

	test('a finished chore counts toward the unlock', async ({ browser, seededData }) => {
		await asAdmin(browser, async (page) => {
			await finishOneChore(page, seededData)

			await openArcade(page)

			await expect(page.getByTestId('arcade-progress-snake')).toHaveText('Unlocks at 10 finished chores · 9 to go')
		})
	})

	test('Spot Sprint is open without chores and saves its round to its own board', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		test.setTimeout(150000)
		await asAdmin(browser, async (page) => {
			await openArcade(page)
			await expect(page.getByTestId('arcade-card-spots')).toHaveAttribute('data-unlocked', 'true')
			await expect(page.getByTestId('arcade-progress-spots')).toHaveCount(0)

			await page.getByTestId('arcade-play-spots').click()
			const shell = page.getByTestId('game-shell')
			await expect(shell).toHaveAttribute('data-phase', 'ready', { timeout: 15000 })
			await page.getByTestId('game-start').click()
			// The opening stain lies under the cursor: Enter wipes it.
			await expect(shell).toBeFocused()
			await page.keyboard.press('Enter')
			await expect(page.getByTestId('game-score')).toHaveText('10')

			const saved = page.waitForResponse((response) => response.url().includes('/api/v1/minigames/spots/scores')
				&& response.request().method() === 'POST', { timeout: 90000 })
			await expect(shell).toHaveAttribute('data-phase', 'over', { timeout: 90000 })
			const response = await saved
			expect(response.status()).toBe(200)
			const body = response.request().postDataJSON() as { score: number, durationMs: number }
			expect(body.score).toBeGreaterThanOrEqual(10)
			expect(body.durationMs).toBeGreaterThanOrEqual(60000)

			await page.getByTestId('game-open-leaderboard').click()
			const board = page.getByTestId('game-leaderboard')
			await expect(board).toHaveAttribute('data-game-id', 'spots')
			await expect(board.getByTestId('game-leaderboard-entry').first()).toBeVisible({ timeout: 10000 })
			const snakeBest = await page.request.get('/apps/chorestrike/api/v1/minigames/snake/personal-best')
			expect((await snakeBest.json()).data, 'the round never reaches another game').toBeNull()
		})
	})

	test('the owner switch opens every game and members cannot use it', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		await asAdmin(browser, async (page) => {
			const denied = await page.request.put('/apps/chorestrike/api/v1/house/arcade-gate', {
				data: { arcadeGateEnabled: false },
			})
			expect(denied.status()).toBe(403)
		})

		const owner = await createUserContext(browser, 'mom')
		try {
			await owner.page.goto('/apps/chorestrike/#/settings')
			const toggle = owner.page.getByTestId('arcade-gate-toggle')
			await expect(toggle).toBeChecked({ timeout: 30000 })
			await toggle.uncheck()
			await owner.page.getByTestId('arcade-gate-save').click()
			await expect(owner.page.getByTestId('arcade-gate-saved')).toBeVisible()
		} finally {
			await owner.context.close()
		}

		await asAdmin(browser, async (page) => {
			await openArcade(page)
			await expect(page.getByTestId('arcade-card-snake')).toHaveAttribute('data-unlocked', 'true')
			await expect(page.getByTestId('arcade-progress-snake')).toHaveCount(0)
			await page.getByTestId('arcade-play-snake').click()
			await expect(page.getByTestId('game-shell')).toHaveAttribute('data-phase', 'ready', { timeout: 15000 })
		})
	})
})
