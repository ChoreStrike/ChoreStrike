/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * E2E: the household arcade catalog and its chore unlocks (CS-PLAN-086).
 * A locked game shows neutral progress and keeps its leaderboard, a
 * crafted score for it is refused, three real completions unlock Sock
 * Match, and the owner switch opens every game. Each game plays a round
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
import { setArcadeGate } from './fixtures/arcade-gate'

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
 * Finish `count` seeded chores as the admin member. Not every seeded task
 * has a completable occurrence at every wall-clock moment, so walk the
 * active ones with an explicit solo split (the completer takes part,
 * Q-074-02), each room task at most once.
 */
async function finishChores(page: Page, seeded: SeededData | null, count: number): Promise<void> {
	const roomTasks = (seeded?.data.roomTasks ?? []) as Array<{ id: number; isActive?: boolean }>
	let finished = 0
	for (const roomTask of roomTasks.filter((row) => row.isActive !== false)) {
		const response = await completeAsSoleParticipantViaApi(page, roomTask.id, 'admin').catch(() => null)
		if (response?.ok() && ++finished === count) {
			return
		}
	}
	throw new Error(`only ${finished} of ${count} seeded room tasks could be finished by admin`)
}

async function startGame(page: Page, gameId: string): Promise<void> {
	await page.getByTestId(`arcade-play-${gameId}`).click()
	const shell = page.getByTestId('game-shell')
	await expect(shell).toHaveAttribute('data-phase', 'ready', { timeout: 15000 })
	await page.getByTestId('game-start').click()
	await expect(shell).toBeFocused()
}

/**
 * Play a whole Sock Match round by tapping, like a player with a perfect
 * memory: the board shows a pattern only on a turned sock, so the spec
 * remembers what it saw and turns a pair as soon as it knows one. Every
 * tapped sock is down at that moment, so its new face proves the turn.
 */
async function playSockMatch(page: Page): Promise<void> {
	const sock = (cell: number) => page.locator(`[data-cell="${cell}"]`)
	const turn = async (cell: number): Promise<string> => {
		await sock(cell).click()
		await expect(sock(cell)).toHaveAttribute('data-face', /^(up|miss|matched)$/)
		return (await sock(cell).getAttribute('data-pattern')) ?? ''
	}
	const seen = new Map<number, string>()
	const found = new Set<number>()
	const take = (...cells: number[]): void => {
		for (const cell of cells) {
			seen.delete(cell)
			found.add(cell)
		}
	}
	const unknown = (): number => {
		const cell = [...Array(16).keys()].find((index) => !found.has(index) && !seen.has(index))
		if (cell === undefined) throw new Error('no unknown sock left')
		return cell
	}
	const partnerOf = (cell: number, pattern: string): number | undefined => [...seen]
		.find(([other, seenPattern]) => other !== cell && seenPattern === pattern)?.[0]

	while (found.size < 16) {
		const known = [...seen].find(([cell, pattern]) => partnerOf(cell, pattern) !== undefined)
		if (known) {
			const partner = partnerOf(known[0], known[1]) as number
			await turn(known[0])
			await turn(partner)
			take(known[0], partner)
			continue
		}
		const first = unknown()
		const pattern = await turn(first)
		const partner = partnerOf(first, pattern)
		if (partner !== undefined) {
			await turn(partner)
			take(first, partner)
			continue
		}
		seen.set(first, pattern)
		const second = unknown()
		const secondPattern = await turn(second)
		if (secondPattern === pattern) {
			take(first, second)
		} else {
			seen.set(second, secondPattern)
		}
	}
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

	test('three finished chores unlock Sock Match, which opens with a known pair', async ({ browser, seededData }) => {
		await asAdmin(browser, async (page) => {
			await openArcade(page)
			await expect(page.getByTestId('arcade-card-socks')).toHaveAttribute('data-unlocked', 'false')
			await expect(page.getByTestId('arcade-progress-socks')).toHaveText('Unlocks at 3 finished chores · 3 to go')

			await finishChores(page, seededData, 3)
			// Same URL: only a reload reads the catalog again.
			await page.reload()
			await expect(page.getByTestId('arcade-surface')).toBeVisible({ timeout: 30000 })

			await expect(page.getByTestId('arcade-card-socks')).toHaveAttribute('data-unlocked', 'true')
			await expect(page.getByTestId('arcade-progress-socks')).toHaveCount(0)
			await expect(page.getByTestId('arcade-progress-snake')).toHaveText('Unlocks at 10 finished chores · 7 to go')

			await startGame(page, 'socks')
			// The cursor's sock and its right neighbour are a pair.
			await page.keyboard.press('Enter')
			await page.keyboard.press('ArrowRight')
			await page.keyboard.press('Enter')
			await expect(page.getByTestId('game-score')).toHaveText('100')
			await expect(page.getByTestId('game-time')).toHaveCount(0)
		})
	})

	test('Sock Match saves a round of found pairs to its own board', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		// The full round is the subject here; the unlock is proven above.
		await setArcadeGate(browser, false)
		await asAdmin(browser, async (page) => {
			await openArcade(page)
			await startGame(page, 'socks')
			const saved = page.waitForResponse((response) => response.url().includes('/api/v1/minigames/socks/scores')
				&& response.request().method() === 'POST', { timeout: 60000 })

			await playSockMatch(page)

			await expect(page.getByTestId('game-shell')).toHaveAttribute('data-phase', 'over')
			await expect(page.getByTestId('game-over-title')).toHaveText('All pairs found!')
			const response = await saved
			expect(response.status()).toBe(200)
			const body = response.request().postDataJSON() as { score: number, durationMs: number }
			// Every pair scores at least 20; with perfect memory the round
			// stays well above that floor and never exceeds the ceiling.
			expect(body.score).toBeGreaterThanOrEqual(160)
			expect(body.score).toBeLessThanOrEqual(800)
			expect(body.durationMs).toBeGreaterThan(0)
			await expect(page.getByTestId('game-final-score')).toHaveText(`Score: ${body.score}`)

			await page.getByTestId('game-open-leaderboard').click()
			const board = page.getByTestId('game-leaderboard')
			await expect(board).toHaveAttribute('data-game-id', 'socks')
			await expect(board.getByTestId('game-leaderboard-entry').first()).toBeVisible({ timeout: 10000 })
			const spotsBest = await page.request.get('/apps/chorestrike/api/v1/minigames/spots/personal-best')
			expect((await spotsBest.json()).data, 'the round never reaches another game').toBeNull()
		})
	})

	test('Spot Sprint is open without chores and saves its round to its own board', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		test.setTimeout(150000)
		await asAdmin(browser, async (page) => {
			await openArcade(page)
			await expect(page.getByTestId('arcade-card-spots')).toHaveAttribute('data-unlocked', 'true')
			await expect(page.getByTestId('arcade-progress-spots')).toHaveCount(0)

			await startGame(page, 'spots')
			const shell = page.getByTestId('game-shell')
			// The opening stain lies under the cursor: Enter wipes it.
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
